<?php

declare(strict_types=1);

namespace Tests\Integration\Booking;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Solicitudes REALMENTE simultáneas (Fase 5): cada solicitud corre en su propio proceso PHP con
 * su propia conexión a MySQL (tests/Support/booking_worker.php) y todas arrancan en el mismo instante.
 * Se inyecta una pausa entre la validación y la escritura para forzar la carrera: sin el candado
 * SELECT … FOR UPDATE ambas validarían el horario como libre y se crearían dos reservas.
 *
 * Estas pruebas confirman datos de verdad en la base de pruebas; al terminar borran lo que crearon.
 *
 * @group concurrencia
 */
final class ConcurrencyTest extends TestCase
{
    private const DELAY_MS = 400;

    private PDO $db;

    /** @var array<string, int> */
    private array $marks = [];

    /** @var list<array<string, mixed>> */
    private array $links = [];

    protected function setUp(): void
    {
        if (!BARBERAPP_DB_AVAILABLE) {
            $this->markTestSkipped('MySQL no disponible.');
        }
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open no disponible.');
        }
        $this->db = Database::connection();
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        foreach (['reservas', 'reserva_eventos', 'notificaciones', 'idempotency_keys', 'auditoria', 'clientes'] as $t) {
            $this->marks[$t] = (int) $this->db->query("SELECT COALESCE(MAX(id), 0) FROM {$t}")->fetchColumn();
        }
        $this->links = $this->db->query('SELECT id, total_reservas FROM cliente_tenants')->fetchAll();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $m = $this->marks;
        $this->db->exec("DELETE FROM notificaciones WHERE id > {$m['notificaciones']} OR reserva_id > {$m['reservas']}");
        $this->db->exec("DELETE FROM reserva_eventos WHERE id > {$m['reserva_eventos']}");
        $this->db->exec("DELETE FROM reservas WHERE id > {$m['reservas']}");
        $this->db->exec("DELETE FROM idempotency_keys WHERE id > {$m['idempotency_keys']}");
        $this->db->exec("DELETE FROM auditoria WHERE id > {$m['auditoria']}");
        $this->db->exec("DELETE FROM cliente_tenants WHERE cliente_id > {$m['clientes']}");
        $this->db->exec("DELETE FROM clientes WHERE id > {$m['clientes']}");
        $upd = $this->db->prepare('UPDATE cliente_tenants SET total_reservas = ? WHERE id = ?');
        foreach ($this->links as $l) {
            $upd->execute([$l['total_reservas'], $l['id']]);
        }
    }

    public function testTwoClientsBookTheSameSlotAtTheSameTime(): void
    {
        $f = $this->day(4, 9);
        $body = $this->body($f, '10:00', ['Corte clásico'], 'Barbero Uno Demo');
        $out = $this->race([
            ['email' => 'cliente1@barberapp.test', 'action' => 'book', 'body' => $body, 'key' => 'conc-a-' . uniqid()],
            ['email' => 'cliente2@barberapp.test', 'action' => 'book', 'body' => $body, 'key' => 'conc-b-' . uniqid()],
        ]);
        $this->assertSame([201, 409], $this->statuses($out), json_encode($out));
        $loser = $out[0]['status'] === 409 ? $out[0] : $out[1];
        $this->assertSame('slot_unavailable', $loser['code']);
        $this->assertGreaterThanOrEqual(self::DELAY_MS - 50, $loser['ms'], 'El perdedor esperó el candado del barbero');
        $this->assertSame(1, $this->activeAt($body['barbero_id'], $f, '10:00'));
    }

    public function testPartiallyOverlappingRequestsAtTheSameTime(): void
    {
        $f = $this->day(5, 9);
        $out = $this->race([
            ['email' => 'cliente1@barberapp.test', 'action' => 'book', 'body' => $this->body($f, '10:00', ['Corte y barba'], 'Barbero Uno Demo'), 'key' => 'conc-c-' . uniqid()],
            ['email' => 'cliente2@barberapp.test', 'action' => 'book', 'body' => $this->body($f, '10:30', ['Corte clásico'], 'Barbero Uno Demo'), 'key' => 'conc-d-' . uniqid()],
        ]);
        $this->assertSame([201, 409], $this->statuses($out), json_encode($out));
        $n = (int) $this->scalar(
            "SELECT COUNT(*) FROM reservas WHERE barbero_id = ? AND estado IN ('pendiente','confirmada') AND inicio_utc < ? AND fin_utc > ?",
            [$this->barber('Barbero Uno Demo'), $this->utc($f, '11:00'), $this->utc($f, '10:00')]
        );
        $this->assertSame(1, $n, 'Nunca quedan dos citas cruzadas del mismo barbero');
    }

    public function testNetworkRetryWithTheSameKeyArrivingTwiceAtOnce(): void
    {
        $f = $this->day(6, 9);
        $body = $this->body($f, '11:00', ['Corte clásico'], 'Barbero Dos Demo');
        $key = 'conc-retry-' . uniqid();
        $out = $this->race([
            ['email' => 'cliente1@barberapp.test', 'action' => 'book', 'body' => $body, 'key' => $key],
            ['email' => 'cliente1@barberapp.test', 'action' => 'book', 'body' => $body, 'key' => $key],
        ]);
        $this->assertSame([201, 201], $this->statuses($out), json_encode($out));
        $this->assertSame([false, true], (static function (array $r) { sort($r); return $r; })(array_column($out, 'replayed')));
        $this->assertSame($out[0]['data']['codigo'], $out[1]['data']['codigo'], 'El reintento devuelve la MISMA reserva');
        $this->assertSame(1, $this->activeAt($body['barbero_id'], $f, '11:00'));
    }

    public function testThreeClientsChooseAnyBarberForTwoFreeBarbers(): void
    {
        $f = $this->day(2, 9);
        $body = $this->body($f, '15:00', ['Corte clásico'], null);
        $out = $this->race([
            ['email' => 'cliente1@barberapp.test', 'action' => 'book', 'body' => $body, 'key' => 'conc-e-' . uniqid()],
            ['email' => 'cliente2@barberapp.test', 'action' => 'book', 'body' => $body, 'key' => 'conc-f-' . uniqid()],
            ['email' => 'propietario.norte@barberapp.test', 'action' => 'book', 'body' => $body, 'key' => 'conc-g-' . uniqid()],
        ]);
        $this->assertSame([201, 201, 409], $this->statuses($out), json_encode($out));
        $ok = array_values(array_filter($out, static fn ($o) => $o['status'] === 201));
        $this->assertNotSame($ok[0]['data']['barbero']['id'], $ok[1]['data']['barbero']['id'], 'Cada barbero recibe una cita');
    }

    public function testCancellationAndNewBookingAtTheSameTime(): void
    {
        $f = $this->day(3, 9);
        $body = $this->body($f, '16:00', ['Corte clásico'], 'Barbero Uno Demo');
        $first = $this->race([['email' => 'cliente1@barberapp.test', 'action' => 'book', 'body' => $body, 'key' => 'conc-h-' . uniqid()]], 0);
        $this->assertSame(201, $first[0]['status'], json_encode($first));
        $id = (int) $first[0]['data']['id'];

        $out = $this->race([
            ['email' => 'cliente1@barberapp.test', 'action' => 'cancel', 'reserva_id' => $id],
            ['email' => 'cliente2@barberapp.test', 'action' => 'book', 'body' => $body, 'key' => 'conc-i-' . uniqid()],
        ], 150);
        $this->assertSame(200, $out[0]['status'], json_encode($out));
        $this->assertContains($out[1]['status'], [201, 409], json_encode($out));
        $this->assertSame('cancelada', $this->scalar('SELECT estado FROM reservas WHERE id = ?', [$id]));
        $this->assertLessThanOrEqual(1, $this->activeAt($body['barbero_id'], $f, '16:00'), 'Como máximo una cita activa en ese horario');
        if ($out[1]['status'] === 409) {
            // Llegó antes de que la cancelación se confirmara: ahora el horario ya está libre.
            $again = $this->race([['email' => 'cliente2@barberapp.test', 'action' => 'book', 'body' => $body, 'key' => 'conc-j-' . uniqid()]], 0);
            $this->assertSame(201, $again[0]['status'], json_encode($again));
        }
    }

    // ------------------------------------------------------------------ utilidades

    /**
     * Lanza un proceso por solicitud y espera a todos. Devuelve sus resultados en el mismo orden.
     *
     * @param list<array<string, mixed>> $jobs
     * @return list<array<string, mixed>>
     */
    private function race(array $jobs, int $delayMs = self::DELAY_MS): array
    {
        $start = microtime(true) + 1.5;
        $procs = [];
        foreach ($jobs as $job) {
            $arg = json_encode($job + ['db' => BARBERAPP_TEST_DB, 'start_at' => $start, 'delay_ms' => $delayMs]);
            $p = proc_open([PHP_BINARY, BARBERAPP_BASE . '/tests/Support/booking_worker.php', $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($p);
            $procs[] = [$p, $pipes];
        }
        $out = [];
        foreach ($procs as [$p, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
            $decoded = json_decode(trim((string) $stdout), true);
            $this->assertIsArray($decoded, "Salida inválida del proceso: {$stdout} {$stderr}");
            $out[] = $decoded;
        }
        return $out;
    }

    /** @param list<array<string, mixed>> $out @return list<int> */
    private function statuses(array $out): array
    {
        $s = array_map(static fn ($o) => (int) $o['status'], $out);
        sort($s);
        return $s;
    }

    /** @return array<string, mixed> */
    private function body(string $fecha, string $hora, array $servicios, ?string $barbero): array
    {
        $centro = (int) $this->scalar("SELECT id FROM tenants WHERE slug = 'demo-centro'");
        return [
            'slug' => 'demo-centro',
            'sucursal_id' => (int) $this->scalar("SELECT id FROM sucursales WHERE tenant_id = ? AND slug = 'sede-cercado'", [$centro]),
            'servicio_ids' => array_map(fn (string $s): int => (int) $this->scalar('SELECT id FROM servicios WHERE tenant_id = ? AND nombre = ?', [$centro, $s]), $servicios),
            'barbero_id' => $barbero === null ? null : $this->barber($barbero),
            'fecha' => $fecha,
            'hora' => $hora,
            'canal' => 'enlace',
        ];
    }

    private function barber(string $nombre): int
    {
        return (int) $this->scalar('SELECT id FROM barberos WHERE nombre_publico = ?', [$nombre]);
    }

    private function activeAt(?int $barberoId, string $fecha, string $hora): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM reservas WHERE barbero_id = ? AND inicio_utc = ? AND estado IN ('pendiente','confirmada')", [$barberoId, $this->utc($fecha, $hora)]);
    }

    private function utc(string $fecha, string $hora): string
    {
        return (new DateTimeImmutable("{$fecha} {$hora}", new DateTimeZone('America/Lima')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function day(int $iso, int $skip): string
    {
        $d = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->modify("+{$skip} day");
        while ((int) $d->format('N') !== $iso) {
            $d = $d->modify('+1 day');
        }
        return $d->format('Y-m-d');
    }

    /** @param list<mixed> $params @return mixed */
    private function scalar(string $sql, array $params = [])
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
