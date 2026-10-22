<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Helpers\Clock;
use App\Services\Backup\S3Client;
use App\Services\Ops\DeployConfig;
use App\Services\Ops\FrontendBuild;
use App\Services\Ops\Heartbeat;
use App\Services\Ops\LogPurge;
use App\Services\Ops\Maintenance;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Fase 15: firma de las copias fuera del servidor, configuración generada del servidor, frontend
 * de producción, latidos, mantenimiento y limpieza de registros. Sin base de datos.
 */
final class DeployUnitTest extends TestCase
{
    private string $tmp;

    /** @var array<string, mixed> */
    private array $saved = [];

    private const KEYS = ['app.url', 'app.frontend_url', 'app.admin_url', 'app.platform_url', 'media.path', 'app.log_path', 'backup.path',
        'ops.heartbeat_path', 'ops.maintenance_file'];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/barberapp-deploy-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
        foreach (self::KEYS as $k) {
            $this->saved[$k] = Config::get($k);
        }
        Config::set('app.url', 'https://api.barberapp.pe');
        Config::set('app.frontend_url', 'https://app.barberapp.pe');
        Config::set('app.admin_url', 'https://admin.barberapp.pe');
        Config::set('app.platform_url', 'https://platform.barberapp.pe');
        Config::set('media.path', '/var/www/barberapp/shared/media');
        Config::set('app.log_path', '/var/log/barberapp');
        Config::set('backup.path', '/var/backups/barberapp');
        Config::set('ops.heartbeat_path', $this->tmp . '/latidos');
        Config::set('ops.maintenance_file', $this->tmp . '/cache/mantenimiento.json');
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            Config::set($k, $v);
        }
        Clock::freeze(null);
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    // ------------------------------------------------------------ firma S3 (ejemplos oficiales de AWS)

    public function testSignatureMatchesAwsDocumentedExamples(): void
    {
        // Ejemplo de la guía "Signature Version 4" (IAM ListUsers).
        $iam = S3Client::authorization('GET', '/', ['Action' => 'ListUsers', 'Version' => '2010-05-08'], [
            'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8', 'Host' => 'iam.amazonaws.com', 'X-Amz-Date' => '20150830T123600Z',
        ], S3Client::EMPTY_SHA256, 'AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'iam', new DateTimeImmutable('2015-08-30T12:36:00Z'));
        $this->assertStringEndsWith('SignedHeaders=content-type;host;x-amz-date, Signature=5d672d79c15b13162d9279b0855cfba6789a8edb4c82c400e06b5924a6f2b5d7', $iam);

        // Ejemplos de la documentación de S3: GET de un objeto con Range y ListObjects.
        $at = new DateTimeImmutable('2013-05-24T00:00:00Z');
        $secret = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
        $get = S3Client::authorization('GET', '/test.txt', [], [
            'Host' => 'examplebucket.s3.amazonaws.com', 'Range' => 'bytes=0-9', 'x-amz-content-sha256' => S3Client::EMPTY_SHA256, 'x-amz-date' => '20130524T000000Z',
        ], S3Client::EMPTY_SHA256, 'AKIAIOSFODNN7EXAMPLE', $secret, 'us-east-1', 's3', $at);
        $this->assertSame('AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $get);
        $list = S3Client::authorization('GET', '/', ['max-keys' => '2', 'prefix' => 'J'], [
            'Host' => 'examplebucket.s3.amazonaws.com', 'x-amz-content-sha256' => S3Client::EMPTY_SHA256, 'x-amz-date' => '20130524T000000Z',
        ], S3Client::EMPTY_SHA256, 'AKIAIOSFODNN7EXAMPLE', $secret, 'us-east-1', 's3', $at);
        $this->assertStringEndsWith('Signature=34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7', $list);
    }

    public function testKeysAndQueriesAreEncodedOnceAndSorted(): void
    {
        $this->assertSame('barberapp/media/tenants/1/foto%20nueva%2B1.webp', S3Client::encodeKey('barberapp/media/tenants/1/foto nueva+1.webp'));
        $this->assertSame('continuation-token=a%2Fb%3D&list-type=2&prefix=barberapp%2Fdb%2F', S3Client::canonicalQuery(['prefix' => 'barberapp/db/', 'list-type' => '2', 'continuation-token' => 'a/b=']));
    }

    public function testS3ClientRejectsIncompleteConfiguration(): void
    {
        foreach ([['bucket' => 'Mal_Nombre'], ['access_key' => ''], ['endpoint' => 'https://s3.example.com/ruta']] as $bad) {
            try {
                new S3Client($bad + ['bucket' => 'copias-barberapp', 'access_key' => 'k', 'secret_key' => 's', 'endpoint' => 'https://s3.example.com']);
                $this->fail('Debió rechazar ' . json_encode($bad));
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------ configuración del servidor

    public function testGeneratedNginxConfigurationUsesTheEnvironmentValues(): void
    {
        $files = DeployConfig::generate(BARBERAPP_BASE . '/../infrastructure', $this->tmp . '/gen', ['ipv6' => 'no']);
        $this->assertContains('nginx/barberapp-api.conf', $files);
        $this->assertContains('nginx/barberapp-plataforma.conf', $files);
        $this->assertContains('systemd/barberapp-worker.service', $files);
        foreach ($files as $f) {
            $this->assertStringNotContainsString('{{', (string) file_get_contents($this->tmp . '/gen/' . $f), "{$f} quedó con marcadores sin reemplazar");
        }
        $api = (string) file_get_contents($this->tmp . '/gen/nginx/barberapp-api.conf');
        $this->assertStringContainsString('server_name api.barberapp.pe;', $api);
        $this->assertStringContainsString('root /var/www/barberapp/current/backend/public;', $api);
        $this->assertStringContainsString('fastcgi_pass unix:/run/php/barberapp.sock;', $api);
        $this->assertStringContainsString('alias /var/www/barberapp/shared/media/;', $api);
        $this->assertStringContainsString('$realpath_root/index.php', $api);
        $this->assertStringNotContainsString('[::]', $api);

        $admin = (string) file_get_contents($this->tmp . '/gen/nginx/barberapp-admin.conf');
        $this->assertStringContainsString('server_name admin.barberapp.pe;', $admin);
        $this->assertStringContainsString('return 302 /pages/panel/inicio.html;', $admin);
        $app = (string) file_get_contents($this->tmp . '/gen/nginx/barberapp-app.conf');
        $this->assertStringContainsString('try_files /index.html =404;', $app);
        $headers = (string) file_get_contents($this->tmp . '/gen/nginx/snippets/barberapp-cabeceras-web.conf');
        $this->assertStringContainsString("connect-src 'self' https://api.barberapp.pe;", $headers);

        $fpm = (string) file_get_contents($this->tmp . '/gen/php/barberapp-fpm.conf');
        $this->assertStringContainsString('open_basedir] = /var/www/barberapp/:/var/backups/barberapp/:/var/log/barberapp/', $fpm);
        $cron = (string) file_get_contents($this->tmp . '/gen/cron/barberapp');
        $this->assertStringContainsString('barberapp cd /var/www/barberapp/current/backend && /usr/bin/php8.3 bin/console backup:enviar', $cron);
        $this->assertStringNotContainsString('* * * * *     barberapp', $cron, 'con systemd el worker no va en el cron');
        $this->assertSame("api.barberapp.pe\napp.barberapp.pe\nadmin.barberapp.pe\nplatform.barberapp.pe\n", file_get_contents($this->tmp . '/gen/dominios.txt'));
    }

    public function testApacheVariantRestrictsThePlatformByIpAndCronRunsTheWorker(): void
    {
        $files = DeployConfig::generate(BARBERAPP_BASE . '/../infrastructure', $this->tmp . '/gen', [
            'servidor' => 'apache', 'worker' => 'cron', 'ips-plataforma' => '203.0.113.7, 198.51.100.0/24', 'php' => '8.2',
        ]);
        $this->assertNotContains('systemd/barberapp-worker.service', $files);
        $plat = (string) file_get_contents($this->tmp . '/gen/apache/barberapp-plataforma.conf');
        $this->assertStringContainsString('Require ip 203.0.113.7 198.51.100.0/24', $plat);
        $this->assertStringNotContainsString('Require all granted', substr($plat, (int) strpos($plat, '<Directory /var/www/barberapp/current/frontend/www>'), 200));
        $this->assertStringContainsString('RewriteRule "^/$" /pages/plataforma/index.html [R=302,L]', $plat);
        $this->assertStringContainsString('Require all granted', (string) file_get_contents($this->tmp . '/gen/apache/barberapp-app.conf'));
        $this->assertStringContainsString('SetHandler "proxy:unix:/run/php/barberapp.sock|fcgi://barberapp"', (string) file_get_contents($this->tmp . '/gen/apache/barberapp-api.conf'));
        $this->assertStringContainsString('* * * * *     barberapp cd /var/www/barberapp/current/backend && /usr/bin/php8.2 bin/console notificaciones:procesar', (string) file_get_contents($this->tmp . '/gen/cron/barberapp'));
    }

    public function testConfigurationRefusesUnsafeValues(): void
    {
        $cases = [
            ['app.url', 'http://api.barberapp.pe', 'https'],
            ['app.url', 'https://servidor.pe/barberapp/backend/public', 'sin ruta'],
            ['app.admin_url', 'https://app.barberapp.pe', 'distintos'],
            ['media.path', '/var/www/barberapp/releases/x/backend/public/media', 'dentro de una versión'],
        ];
        foreach ($cases as [$key, $value, $expected]) {
            $before = Config::get($key);
            Config::set($key, $value);
            try {
                DeployConfig::values();
                $this->fail("Debió rechazar {$key}={$value}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            } finally {
                Config::set($key, $before);
            }
        }
        $this->expectException(RuntimeException::class);
        DeployConfig::values(['usuario' => 'root; rm -rf /']);
    }

    public function testTemplatesFailOnUnknownMarkers(): void
    {
        $this->assertSame("a b\n\nc", DeployConfig::render("a {{X}}\n\n  \n\nc", ['X' => 'b']));
        $this->expectExceptionMessage('{{OTRO_1}}');
        DeployConfig::render('{{OTRO_1}}', []);
    }

    // ------------------------------------------------------------ frontend de producción

    public function testFrontendBuildSetsTheApiAndStampsTheServiceWorker(): void
    {
        $www = $this->tmp . '/www';
        mkdir($www . '/js', 0777, true);
        copy(BARBERAPP_BASE . '/../frontend/www/js/site-config.js', $www . '/js/site-config.js');
        copy(BARBERAPP_BASE . '/../frontend/www/sw.js', $www . '/sw.js');
        preg_match("/^const VERSION = '([^']+)';$/m", (string) file_get_contents($www . '/sw.js'), $m);

        $r = FrontendBuild::apply($www, 'https://api.barberapp.pe/api/v1/', '20261022-abc1234');
        $this->assertSame('https://api.barberapp.pe/api/v1', $r['api']);
        $this->assertSame($m[1] . '+20261022-abc1234', $r['version']);
        $this->assertStringContainsString('apiUrl: "https://api.barberapp.pe/api/v1"', (string) file_get_contents($www . '/js/site-config.js'));
        $r2 = FrontendBuild::apply($www, 'https://api.barberapp.pe/api/v1', 'otra');
        $this->assertSame($m[1] . '+otra', $r2['version'], 'un despliegue nuevo reemplaza la marca anterior');
        $this->assertSame(1, substr_count((string) file_get_contents($www . '/sw.js'), 'const VERSION'));

        $this->expectException(RuntimeException::class);
        FrontendBuild::apply($www, 'https://api.barberapp.pe/"};alert(1)//', 'x');
    }

    public function testRepositoryFrontendKeepsTheApiEmptyForXampp(): void
    {
        $this->assertStringContainsString('apiUrl: "",', (string) file_get_contents(BARBERAPP_BASE . '/../frontend/www/js/site-config.js'));
        $this->assertStringContainsString("'js/site-config.js'", (string) file_get_contents(BARBERAPP_BASE . '/../frontend/www/sw.js'));
    }

    // ------------------------------------------------------------ latidos, mantenimiento y registros

    public function testHeartbeatsRecordTheLastSuccessfulRun(): void
    {
        $this->assertNull(Heartbeat::ageMinutes('backup:crear'));
        Clock::freeze(new DateTimeImmutable('2026-10-22T08:15:00Z'));
        Heartbeat::record('backup:crear', ['filas' => 10]);
        Clock::freeze(new DateTimeImmutable('2026-10-22T10:45:30Z'));
        $this->assertSame(150, Heartbeat::ageMinutes('backup:crear'));
        $this->assertSame(['filas' => 10], Heartbeat::read('backup:crear')['datos']);
        $this->assertFileExists($this->tmp . '/latidos/backup-crear.json');
    }

    public function testMaintenanceFlag(): void
    {
        $this->assertNull(Maintenance::status());
        Maintenance::enable('actualizacion 2026', 1);
        $st = Maintenance::status();
        $this->assertSame('actualizacion 2026', $st['motivo']);
        $this->assertSame(5, $st['reintentar'], 'mínimo 5 segundos');
        $this->assertTrue(Maintenance::disable());
        $this->assertNull(Maintenance::status());
    }

    public function testLogPurgeKeepsOnlyTheRetentionWindow(): void
    {
        $dir = $this->tmp . '/logs';
        mkdir($dir);
        foreach (['2026-09-01', '2026-09-22', '2026-09-23', '2026-10-22'] as $d) {
            touch("{$dir}/app-{$d}.log");
        }
        touch("{$dir}/cron.log");
        Clock::freeze(new DateTimeImmutable('2026-10-22T09:40:00Z'));
        $r = LogPurge::run($dir, 30);
        $this->assertSame(2, $r['borrados']);
        $left = array_map('basename', glob($dir . '/*') ?: []);
        sort($left);
        $this->assertSame(['app-2026-09-23.log', 'app-2026-10-22.log', 'cron.log'], $left);
    }

    // ------------------------------------------------------------ scripts y plantillas en el repositorio

    public function testDeployScriptsAreExecutableAndStrict(): void
    {
        foreach (['instalar-vps.sh', 'desplegar.sh', 'rollback.sh'] as $s) {
            $path = BARBERAPP_BASE . '/../infrastructure/scripts/' . $s;
            $this->assertFileExists($path);
            $this->assertTrue(is_executable($path), "{$s} debe ser ejecutable");
            $this->assertStringContainsString('set -Eeuo pipefail', (string) file_get_contents($path));
        }
        $example = (string) file_get_contents(BARBERAPP_BASE . '/.env.production.example');
        foreach (['APP_ENV=production', 'APP_DEBUG=false', 'FORCE_HTTPS=true', 'SESSION_SECURE_COOKIE=true', 'APP_KEY=', 'BACKUP_KEY=', 'DB_PASSWORD='] as $line) {
            $this->assertMatchesRegularExpression('/^' . preg_quote($line, '/') . '$/m', $example);
        }
        $this->assertDoesNotMatchRegularExpression('/^(APP_KEY|BACKUP_KEY|DB_PASSWORD|MP_ACCESS_TOKEN|BACKUP_S3_SECRET)=.+$/m', $example, 'el ejemplo no trae secretos');
    }
}
