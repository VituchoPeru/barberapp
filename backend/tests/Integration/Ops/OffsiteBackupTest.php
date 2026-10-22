<?php

declare(strict_types=1);

namespace Tests\Integration\Ops;

use App\Core\Config;
use App\Helpers\Clock;
use App\Services\Backup\BackupCipher;
use App\Services\Backup\BackupService;
use App\Services\Backup\OffsiteBackup;
use App\Services\Backup\S3Client;
use DateTimeImmutable;
use RuntimeException;
use Tests\Integration\DatabaseTestCase;

/**
 * Copias fuera del servidor (Fase 15): subida idempotente, verificación, retención, fotos y
 * recuperación en un servidor nuevo. El almacenamiento es FakeS3 (en memoria); con la variable
 * BARBERAPP_S3_PRUEBA_ENDPOINT la última prueba usa un servidor S3 real (MinIO, moto…).
 *
 * @group seguridad
 */
final class OffsiteBackupTest extends DatabaseTestCase
{
    private string $dir;
    private string $media;

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/barberapp-offsite-' . bin2hex(random_bytes(4));
        $this->media = $this->dir . '-media';
        foreach (['backup.key', 'backup.keep', 'backup.remote', 'media.path'] as $k) {
            $this->saved[$k] = Config::get($k);
        }
        Config::set('backup.key', BackupCipher::generateKey());
        Config::set('backup.keep', 10);
        Config::set('backup.remote', ['keep' => 0, 'media' => true, 'prefix' => 'barberapp/prueba']);
        Config::set('media.path', $this->media);
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
        exec('rm -rf ' . escapeshellarg($this->dir) . ' ' . escapeshellarg($this->media));
        foreach ($this->saved as $k => $v) {
            Config::set($k, $v);
        }
        parent::tearDown();
    }

    private function copyAt(BackupService $svc, string $at): string
    {
        Clock::freeze(new DateTimeImmutable($at));
        $r = $svc->create();
        Clock::freeze(null);
        return basename($r['archivo']);
    }

    public function testPushUploadsEncryptedCopiesOnceWithTheirChecksum(): void
    {
        $s3 = new FakeS3();
        $svc = new BackupService($this->dir);
        $a = $this->copyAt($svc, '2026-10-20T08:15:00Z');
        $b = $this->copyAt($svc, '2026-10-21T08:15:00Z');
        $off = new OffsiteBackup($s3->client(), $svc);

        $r = $off->push();
        $this->assertSame([$a, $b], $r['subidas']);
        $this->assertSame(["barberapp/prueba/db/{$a}", "barberapp/prueba/db/{$a}.sha256", "barberapp/prueba/db/{$b}", "barberapp/prueba/db/{$b}.sha256"], $s3->keys('barberapp/prueba/db/'));
        $this->assertSame(file_get_contents($this->dir . '/' . $b), $s3->objects["barberapp/prueba/db/{$b}"]['body']);
        $this->assertTrue(BackupCipher::isEncrypted($this->dir . '/' . $b), 'lo que sale del servidor está cifrado');
        $this->assertSame(hash_file('sha256', $this->dir . '/' . $b), $s3->objects["barberapp/prueba/db/{$b}"]['meta']['x-amz-meta-sha256']);

        $again = $off->push();
        $this->assertSame([], $again['subidas']);
        $this->assertSame(2, $again['ya_estaban']);
        $this->assertSame([['nombre' => $b], ['nombre' => $a]], array_map(static fn ($c) => ['nombre' => $c['nombre']], $off->remote()));
    }

    public function testUnencryptedOrDamagedLocalCopiesAreNeverUploaded(): void
    {
        $s3 = new FakeS3();
        $svc = new BackupService($this->dir);
        Config::set('backup.key', '');
        $plain = $this->copyAt($svc, '2026-10-20T08:15:00Z');
        Config::set('backup.key', BackupCipher::generateKey());
        $enc = $this->copyAt($svc, '2026-10-21T08:15:00Z');
        $r = (new OffsiteBackup($s3->client(), $svc))->push(false);
        $this->assertSame([$plain], $r['omitidas_sin_cifrar']);
        $this->assertSame([$enc], $r['subidas']);

        $bad = $this->copyAt($svc, '2026-10-22T08:15:00Z');
        file_put_contents($this->dir . '/' . $bad, 'x', FILE_APPEND);
        try {
            (new OffsiteBackup($s3->client(), $svc))->push(false);
            $this->fail('Una copia que no coincide con su .sha256 no debe subirse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('dañada', $e->getMessage());
        }
        $this->assertArrayNotHasKey("barberapp/prueba/db/{$bad}", $s3->objects);
    }

    public function testRemoteRetentionKeepsTheNewestAndNeverReuploadsOldOnes(): void
    {
        Config::set('backup.remote', ['keep' => 2, 'media' => false, 'prefix' => 'barberapp/prueba']);
        $s3 = new FakeS3();
        $svc = new BackupService($this->dir);
        $names = [];
        foreach (['2026-10-18', '2026-10-19', '2026-10-20', '2026-10-21'] as $d) {
            $names[] = $this->copyAt($svc, $d . 'T08:15:00Z');
        }
        $off = new OffsiteBackup($s3->client(), $svc);
        $r = $off->push();
        $this->assertSame([$names[2], $names[3]], $r['subidas'], 'las dos más antiguas no se suben para borrarlas en seguida');
        $this->assertSame([], $r['borradas']);
        $new = $this->copyAt($svc, '2026-10-22T08:15:00Z');
        $r = $off->push();
        $this->assertSame([$new], $r['subidas']);
        $this->assertSame([$names[2]], $r['borradas']);
        $this->assertSame([$new, $names[3]], array_column($off->remote(), 'nombre'));
        $this->assertArrayNotHasKey("barberapp/prueba/db/{$names[2]}.sha256", $s3->objects);
    }

    public function testMediaSyncUploadsChangesAndMirrorsDeletions(): void
    {
        $s3 = new FakeS3();
        $svc = new BackupService($this->dir);
        @mkdir($this->media . '/tenants/7/galeria', 0777, true);
        file_put_contents($this->media . '/tenants/7/logo.webp', 'logo-v1');
        file_put_contents($this->media . '/tenants/7/galeria/a.webp', 'foto-a');
        file_put_contents($this->media . '/tenants/7/.htaccess', 'Require all denied');
        $off = new OffsiteBackup($s3->client(), $svc);

        $m = $off->push()['media'];
        $this->assertSame(2, $m['subidas']);
        $this->assertSame(['barberapp/prueba/media/tenants/7/galeria/a.webp', 'barberapp/prueba/media/tenants/7/logo.webp'], $s3->keys('barberapp/prueba/media/'));

        file_put_contents($this->media . '/tenants/7/logo.webp', 'logo-version-2');
        unlink($this->media . '/tenants/7/galeria/a.webp');
        $m = $off->push()['media'];
        $this->assertSame(['subidas' => 1, 'ya_estaban' => 0, 'borradas' => 1, 'no_borradas' => 0, 'bytes' => 14], $m);
        $this->assertSame('logo-version-2', $s3->objects['barberapp/prueba/media/tenants/7/logo.webp']['body']);
        $this->assertArrayNotHasKey('barberapp/prueba/media/tenants/7/galeria/a.webp', $s3->objects, 'una foto borrada (dato personal) no queda en el bucket');

        // Con una clave sin permiso de borrar, la sincronización sigue y lo informa.
        $s3->denyDelete = true;
        unlink($this->media . '/tenants/7/logo.webp');
        $m = $off->push()['media'];
        $this->assertSame(1, $m['no_borradas']);
    }

    public function testPullRecoversTheLatestCopyOnANewServerAndRejectsTampering(): void
    {
        $s3 = new FakeS3();
        $svc = new BackupService($this->dir);
        $this->copyAt($svc, '2026-10-20T08:15:00Z');
        $last = $this->copyAt($svc, '2026-10-21T08:15:00Z');
        (new OffsiteBackup($s3->client(), $svc))->push(false);

        // Servidor nuevo: carpeta de copias vacía, misma BACKUP_KEY guardada aparte.
        $fresh = new BackupService($this->dir . '/nuevo');
        $off = new OffsiteBackup($s3->client(), $fresh);
        $s3->corruptNextGet = "barberapp/prueba/db/{$last}";
        try {
            $off->pull();
            $this->fail('Una copia alterada en el bucket debe rechazarse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SHA-256', $e->getMessage());
        }
        $this->assertSame([], $fresh->list(), 'la copia rechazada no queda en el servidor');

        $path = $off->pull();
        $this->assertSame($last, basename($path));
        $this->assertSame(hash_file('sha256', $this->dir . '/' . $last), hash_file('sha256', $path));
        Config::set('backup.verify_database', BARBERAPP_TEST_DB . '_offsite_check');
        $v = $fresh->verify($path);
        $this->assertTrue($v['ok'], 'la copia traída de fuera se restaura con las mismas filas');

        $this->expectException(RuntimeException::class);
        $off->pull('../../etc/passwd');
    }

    public function testConfiguredRequiresBucketAndCredentials(): void
    {
        Config::set('backup.remote', ['bucket' => 'copias', 'access_key' => 'k', 'secret_key' => '']);
        $this->assertFalse(OffsiteBackup::configured());
        Config::set('backup.remote', ['bucket' => 'copias', 'access_key' => 'k', 'secret_key' => 's']);
        $this->assertTrue(OffsiteBackup::configured());
        Config::set('backup.remote', []);
        $this->expectExceptionMessage('BACKUP_S3_BUCKET');
        new OffsiteBackup();
    }

    /**
     * Contra un S3 real por HTTP (curl y el cliente de PHP). Ejemplo con moto:
     *   moto_server -p 9000 & curl -X PUT localhost:9000/copias-barberapp
     *   BARBERAPP_S3_PRUEBA_ENDPOINT=http://localhost:9000 vendor/bin/phpunit --filter RealS3
     */
    public function testAgainstARealS3CompatibleServer(): void
    {
        $endpoint = (string) getenv('BARBERAPP_S3_PRUEBA_ENDPOINT');
        if ($endpoint === '') {
            $this->markTestSkipped('Sin BARBERAPP_S3_PRUEBA_ENDPOINT (servidor S3 de prueba).');
        }
        $svc = new BackupService($this->dir);
        $name = $this->copyAt($svc, '2026-10-21T08:15:00Z');
        foreach (['curlRequest', 'streamRequest'] as $transport) {
            if ($transport === 'curlRequest' && !extension_loaded('curl')) {
                continue;
            }
            $prefix = 'barberapp/prueba-' . $transport . '-' . bin2hex(random_bytes(3));
            Config::set('backup.remote', ['keep' => 1, 'media' => false, 'prefix' => $prefix]);
            $client = new S3Client([
                'endpoint' => $endpoint, 'bucket' => (string) (getenv('BARBERAPP_S3_PRUEBA_BUCKET') ?: 'copias-barberapp'),
                'access_key' => (string) (getenv('BARBERAPP_S3_PRUEBA_KEY') ?: 'prueba'), 'secret_key' => (string) (getenv('BARBERAPP_S3_PRUEBA_SECRET') ?: 'prueba'),
                'path_style' => true, 'region' => 'us-east-1',
            ], [S3Client::class, $transport]);
            $off = new OffsiteBackup($client, $svc, $prefix);
            $this->assertSame([$name], $off->push()['subidas'], $transport);
            $this->assertSame(1, $off->push()['ya_estaban'], $transport);
            $this->assertSame($name, $off->remote()[0]['nombre']);
            $copy = (new OffsiteBackup($client, new BackupService($this->dir . '/' . $transport), $prefix))->pull($name);
            $this->assertSame(hash_file('sha256', $this->dir . '/' . $name), hash_file('sha256', $copy), $transport);
            $client->delete($prefix . '/db/' . $name);
            $client->delete($prefix . '/db/' . $name . '.sha256');
            $this->assertSame([], $off->remote());
        }
    }
}
