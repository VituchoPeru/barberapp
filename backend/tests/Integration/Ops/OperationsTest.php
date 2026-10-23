<?php

declare(strict_types=1);

namespace Tests\Integration\Ops;

use App\Core\Config;
use App\Helpers\Clock;
use App\Services\Backup\BackupCipher;
use App\Services\Backup\BackupService;
use App\Services\Mail\Mailer;
use App\Services\Ops\Heartbeat;
use App\Services\Ops\Maintenance;
use App\Services\Ops\Monitor;
use App\Services\Ops\Preflight;
use App\Services\Platform\PlatformAdmin;
use Database\Seeds\CatalogSeeder;
use DateTimeImmutable;
use Tests\Integration\Auth\ApiTestCase;

/**
 * Operación en producción (Fase 15): mantenimiento durante las migraciones, monitoreo con sus
 * alertas, /health/operacion, primer superadministrador, catálogo idempotente en cada despliegue
 * y restauración limpia para el rollback.
 */
final class OperationsTest extends ApiTestCase
{
    private string $tmp;

    /** @var array<string, mixed> */
    private array $saved = [];

    private const KEYS = ['ops.heartbeat_path', 'ops.maintenance_file', 'ops.alert_state_file', 'ops.alert_email', 'ops.token', 'ops.heartbeats',
        'backup.path', 'backup.key', 'backup.remote', 'backup.verify_database', 'ops.min_free_disk_percent'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/barberapp-ops-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
        foreach (self::KEYS as $k) {
            $this->saved[$k] = Config::get($k);
        }
        Config::set('ops.heartbeat_path', $this->tmp . '/latidos');
        Config::set('ops.maintenance_file', $this->tmp . '/mantenimiento.json');
        Config::set('ops.alert_state_file', $this->tmp . '/monitor-estado.json');
        Config::set('ops.alert_email', 'alertas@barberapp.test');
        Config::set('ops.min_free_disk_percent', 1);
        Config::set('backup.path', $this->tmp . '/copias');
        Config::set('backup.key', BackupCipher::generateKey());
        Config::set('backup.remote', []);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            Config::set($k, $v);
        }
        exec('rm -rf ' . escapeshellarg($this->tmp));
        parent::tearDown();
    }

    // ------------------------------------------------------------ mantenimiento

    public function testMaintenanceAnswers503WithRetryAfterButKeepsLiveness(): void
    {
        Maintenance::enable('actualizacion', 45);
        $res = $this->call('GET', '/marketplace/barberias');
        $this->assertSame(503, $res->status());
        $this->assertSame('maintenance', $this->errorCode($res));
        $this->assertSame('45', $res->header('Retry-After'));
        $this->assertSame(503, $this->call('POST', '/auth/login', ['email' => 'cliente1@barberapp.test', 'password' => self::PASSWORD])->status());
        $this->assertSame(503, $this->call('POST', '/webhooks/pagos/mercadopago', ['type' => 'payment'])->status(), 'el pago se reintenta después');
        $this->assertSame(200, $this->call('GET', '/health/live')->status());

        Maintenance::disable();
        $this->assertSame(200, $this->call('GET', '/marketplace/barberias')->status());
        $this->assertFalse($this->data($this->call('GET', '/health'))['maintenance']);
    }

    // ------------------------------------------------------------ /health/operacion

    public function testOperationEndpointRequiresTheMonitorToken(): void
    {
        Config::set('ops.token', '');
        $this->assertSame(404, $this->call('GET', '/health/operacion')->status(), 'sin token configurado la ruta no existe');

        Config::set('ops.token', 'token-de-monitoreo-largo-1234567890');
        $this->assertSame(401, $this->call('GET', '/health/operacion')->status());
        $this->assertSame(401, $this->call('GET', '/health/operacion', null, ['Authorization' => 'Bearer otro'])->status());

        $auth = ['Authorization' => 'Bearer token-de-monitoreo-largo-1234567890'];
        $res = $this->call('GET', '/health/operacion', null, $auth);
        $this->assertSame(503, $res->status(), 'sin latidos de cron ni copias hay problemas');
        $body = $this->data($res);
        $this->assertSame('error', $body['status']);
        $temas = array_column($body['puntos'], 'nivel', 'tema');
        $this->assertSame('error', $temas['Tarea backup:crear']);
        $this->assertSame('no-store', $res->header('Cache-Control'));
        $this->assertStringNotContainsString('@barberapp.test', $res->body(), 'solo estado operativo, sin datos de personas');

        $this->allHealthy();
        $res = $this->call('GET', '/health/operacion', null, $auth);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame('ok', $this->data($res)['status']);
    }

    // ------------------------------------------------------------ monitoreo y alertas

    public function testMonitorDetectsStoppedCronAndAlertsOncePerProblem(): void
    {
        $this->allHealthy();
        $this->assertSame([], $this->problems(Monitor::run(BARBERAPP_BASE, false)));
        $this->assertNull(Monitor::alert(Monitor::run(BARBERAPP_BASE, false)), 'sin problemas no hay correo');

        // El worker de avisos dejó de correr hace 20 minutos.
        Clock::freeze(Clock::nowUtc()->modify('+20 minutes'));
        $checks = Monitor::run(BARBERAPP_BASE, false);
        $this->assertSame(['Tarea notificaciones:procesar'], $this->problems($checks));
        $subject = Monitor::alert($checks);
        $this->assertStringContainsString('1 problema(s)', (string) $subject);
        $this->assertCount(1, Mailer::sent());
        $this->assertSame('alertas@barberapp.test', Mailer::sent()[0]->to);
        $this->assertStringContainsString('Tarea notificaciones:procesar', Mailer::sent()[0]->text);

        $this->assertNull(Monitor::alert(Monitor::run(BARBERAPP_BASE, false)), 'el mismo problema no se repite enseguida');
        Clock::freeze(Clock::nowUtc()->modify('+7 hours'));
        Heartbeat::record('suscripciones:vencer');
        $this->assertNotNull(Monitor::alert(Monitor::run(BARBERAPP_BASE, false)), 'se recuerda tras MONITOR_REPETIR_HORAS');

        Heartbeat::record('notificaciones:procesar');
        $this->assertSame([], $this->problems(Monitor::run(BARBERAPP_BASE, false)));
        $this->assertStringStartsWith('[BARBERAPP] Resuelto', (string) Monitor::alert(Monitor::run(BARBERAPP_BASE, false)));
        $this->assertCount(3, Mailer::sent());
    }

    public function testMonitorFlagsLateQueueForgottenMaintenanceAndMissingOffsite(): void
    {
        $this->allHealthy();
        Config::set('app.env', 'production');
        try {
            $this->assertSame(['Copias fuera del servidor'], $this->problems(Monitor::run(BARBERAPP_BASE, false)));
        } finally {
            Config::set('app.env', 'testing');
        }
        $this->exec("UPDATE notificaciones SET estado = 'programada', canal = 'push', programada_para_utc = UTC_TIMESTAMP() - INTERVAL 1 HOUR, proximo_intento_utc = NULL LIMIT 1");
        Clock::freeze(Clock::nowUtc());
        Maintenance::enable('actualizacion');
        Clock::freeze(Clock::nowUtc()->modify('+45 minutes'));
        foreach (array_keys((array) Config::get('ops.heartbeats')) as $t) {
            Heartbeat::record((string) $t);
        }
        $problems = $this->problems(Monitor::run(BARBERAPP_BASE, false));
        $this->assertContains('Cola de avisos', $problems);
        $this->assertContains('Mantenimiento', $problems);
    }

    // ------------------------------------------------------------ revisión previa y primer superadmin

    public function testPreflightPassesOnTheTestServer(): void
    {
        $checks = Preflight::run(BARBERAPP_BASE, false);
        $errors = array_filter($checks, static fn (array $c): bool => $c['nivel'] === 'error');
        $this->assertSame([], array_values($errors), json_encode($checks, JSON_UNESCAPED_UNICODE));
        $this->assertContains('MySQL', array_column($checks, 'tema'));
    }

    public function testFirstSuperadminIsCreatedWithAVerifiedAccountOrPromoted(): void
    {
        $r = PlatformAdmin::ensure('Dueno@BarberApp.pe', 'Victor');
        $this->assertTrue($r['creado']);
        $row = $this->db->query("SELECT u.email, u.email_verificado_en, u.password_hash, r.codigo FROM usuarios u JOIN roles r ON r.id = u.rol_plataforma_id WHERE u.id = {$r['usuario_id']}")->fetch();
        $this->assertSame('dueno@barberapp.pe', $row['email']);
        $this->assertSame('superadmin', $row['codigo']);
        $this->assertNotNull($row['email_verificado_en']);
        $this->assertTrue(password_verify((string) $r['clave'], (string) $row['password_hash']));
        $this->assertSame([], \App\Services\Auth\PasswordPolicy::errors((string) $r['clave']));
        $res = $this->call('POST', '/auth/login', ['email' => 'dueno@barberapp.pe', 'password' => $r['clave']]);
        $this->assertSame(200, $res->status(), $res->body());

        $p = PlatformAdmin::ensure('cliente1@barberapp.test');
        $this->assertFalse($p['creado']);
        $this->assertNull($p['clave']);
        $this->assertSame('superadmin', $this->scalar('SELECT r.codigo FROM usuarios u JOIN roles r ON r.id = u.rol_plataforma_id WHERE u.email = ?', ['cliente1@barberapp.test']));
    }

    public function testCatalogSeedOnEveryDeployKeepsPlansEditedInThePanel(): void
    {
        // El seeder abre su propia transacción: esta prueba trabaja fuera de la de DatabaseTestCase
        // y deja el plan como estaba.
        $this->db->rollBack();
        $before = $this->db->query("SELECT nombre, precio_mensual FROM planes WHERE codigo = 'basico'")->fetch();
        try {
            $this->exec("UPDATE planes SET nombre = 'Básico Plus', precio_mensual = 45.00 WHERE codigo = 'basico'");
            (new CatalogSeeder($this->db))->run();
            $row = $this->db->query("SELECT nombre, precio_mensual FROM planes WHERE codigo = 'basico'")->fetch();
            $this->assertSame(['nombre' => 'Básico Plus', 'precio_mensual' => '45.00'], ['nombre' => $row['nombre'], 'precio_mensual' => $row['precio_mensual']]);
        } finally {
            $this->exec('UPDATE planes SET nombre = ?, precio_mensual = ? WHERE codigo = ?', [$before['nombre'], $before['precio_mensual'], 'basico']);
            $this->db->beginTransaction();
        }
    }

    public function testCleanRestoreRemovesTablesCreatedAfterTheCopy(): void
    {
        $target = BARBERAPP_TEST_DB . '_rollback_check';
        $svc = new BackupService($this->tmp . '/copias');
        $copy = $svc->create()['archivo'];
        $svc->restore($copy, $target);
        $pdo = \App\Core\Database::connect(['database' => $target] + (array) Config::get('database'));
        try {
            $pdo->exec('CREATE TABLE tabla_de_migracion_nueva (id INT PRIMARY KEY)');
            $svc->restore($copy, $target);
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tabla_de_migracion_nueva'")->fetchColumn(), 'sin --limpiar la tabla queda');
            $svc->restore($copy, $target, true);
            $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tabla_de_migracion_nueva'")->fetchColumn());
            $this->assertGreaterThan(40, (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn());
        } finally {
            $pdo->exec("DROP DATABASE IF EXISTS `{$target}`");
        }
    }

    // ------------------------------------------------------------ auxiliares

    private function allHealthy(): void
    {
        foreach (array_keys((array) Config::get('ops.heartbeats')) as $t) {
            Heartbeat::record((string) $t);
        }
        (new BackupService($this->tmp . '/copias'))->create();
        $this->exec("UPDATE notificaciones SET estado = 'cancelada' WHERE estado = 'programada' AND programada_para_utc < UTC_TIMESTAMP() + INTERVAL 1 DAY");
    }

    /** @param list<array{nivel: string, tema: string, mensaje: string}> $checks @return list<string> */
    private function problems(array $checks): array
    {
        return array_values(array_map(static fn (array $c): string => $c['tema'], array_filter($checks, static fn (array $c): bool => $c['nivel'] === 'error')));
    }
}
