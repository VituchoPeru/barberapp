<?php

declare(strict_types=1);

namespace Tests\Integration\Security;

use App\Core\Config;
use App\Services\Backup\BackupCipher;
use App\Services\Backup\BackupService;
use RuntimeException;
use Tests\Integration\DatabaseTestCase;

/**
 * Copias de seguridad cifradas y prueba de restauración (Fase 13).
 *
 * @group seguridad
 */
final class BackupTest extends DatabaseTestCase
{
    private string $dir;

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/barberapp-backup-test-' . bin2hex(random_bytes(4));
        foreach (['backup.key', 'backup.keep', 'backup.verify_database'] as $k) {
            $this->saved[$k] = Config::get($k);
        }
        Config::set('backup.key', BackupCipher::generateKey());
        Config::set('backup.keep', 2);
        Config::set('backup.verify_database', BARBERAPP_TEST_DB . '_restore_check');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->dir);
        foreach ($this->saved as $k => $v) {
            Config::set($k, $v);
        }
        parent::tearDown();
    }

    public function testEncryptedBackupRestoresWithTheSameRowsAndBinaryData(): void
    {
        $svc = new BackupService($this->dir);
        $r = $svc->create();
        $this->assertTrue($r['cifrado']);
        $this->assertStringEndsWith('.sql.gz.enc', $r['archivo']);
        $this->assertTrue(BackupCipher::isEncrypted($r['archivo']));
        $this->assertFileExists($r['archivo'] . '.sha256');
        $this->assertSame(0600, fileperms($r['archivo']) & 0777);
        $this->assertGreaterThan(40, $r['tablas']);
        // El contenido no se lee sin la clave (nada de texto plano dentro).
        $this->assertStringNotContainsString('barberapp.test', (string) file_get_contents($r['archivo']));

        $v = $svc->verify($r['archivo']);
        $this->assertTrue($v['ok'], json_encode($v['diferencias']));
        $this->assertSame($r['filas'], $v['filas']);
        $exists = (int) $this->scalar('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?', [BARBERAPP_TEST_DB . '_restore_check']);
        $this->assertSame(0, $exists, 'La base temporal se borra');
    }

    public function testRestoredCopyKeepsBinaryAndGeneratedColumns(): void
    {
        $svc = new BackupService($this->dir);
        // Fila confirmada fuera de la transacción de la prueba para que la copia la vea.
        $cfg = (array) Config::get('database');
        $other = \App\Core\Database::connect($cfg);
        $other->exec("INSERT INTO auditoria (accion, resultado, ip, user_agent, created_at) VALUES ('prueba.backup', 'exito', 0x" . bin2hex((string) inet_pton('2001:db8::1')) . ", 'Línea con \"comillas\", ; y \\\\ barra\nsalto', UTC_TIMESTAMP())");
        try {
            $r = $svc->create();
            $target = BARBERAPP_TEST_DB . '_copia';
            $svc->restore($r['archivo'], $target);
            $copy = \App\Core\Database::connect(['database' => $target] + $cfg);
            $row = $copy->query("SELECT ip, user_agent FROM auditoria WHERE accion = 'prueba.backup'")->fetch();
            $this->assertSame('2001:db8::1', inet_ntop((string) $row['ip']));
            $this->assertSame("Línea con \"comillas\", ; y \\ barra\nsalto", $row['user_agent']);
            $this->assertSame(
                (int) $this->scalar('SELECT SUM(ocupa_agenda) FROM reservas'),
                (int) $copy->query('SELECT SUM(ocupa_agenda) FROM reservas')->fetchColumn(),
                'La columna generada se recalcula igual'
            );
            $copy->exec("DROP DATABASE `{$target}`");
        } finally {
            $other->exec("DELETE FROM auditoria WHERE accion = 'prueba.backup'");
        }
    }

    public function testTamperedOrWrongKeyCopiesAreRejected(): void
    {
        $svc = new BackupService($this->dir);
        $file = $svc->create()['archivo'];

        Config::set('backup.key', BackupCipher::generateKey());
        try {
            $svc->plainCopy($file);
            $this->fail('Con otra clave no debe descifrar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('clave', $e->getMessage());
        }

        $bytes = (string) file_get_contents($file);
        $bytes[100] = $bytes[100] === 'a' ? 'b' : 'a';
        file_put_contents($file, $bytes);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SHA-256');
        $svc->plainCopy($file);
    }

    public function testRotationKeepsTheConfiguredNumberOfCopies(): void
    {
        $svc = new BackupService($this->dir);
        $names = [];
        for ($i = 0; $i < 3; $i++) {
            $names[] = basename($svc->create()['archivo']);
            sleep(1); // el nombre lleva la hora con segundos
        }
        $this->assertCount(2, $svc->list());
        $this->assertFileDoesNotExist($this->dir . '/' . $names[0]);
    }

    public function testProductionRequiresAKey(): void
    {
        Config::set('backup.key', '');
        Config::set('app.env', 'production');
        try {
            $this->expectException(RuntimeException::class);
            (new BackupService($this->dir))->create();
        } finally {
            Config::set('app.env', 'testing');
        }
    }
}
