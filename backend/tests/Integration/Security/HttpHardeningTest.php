<?php

declare(strict_types=1);

namespace Tests\Integration\Security;

use App\Core\Config;
use Tests\Integration\Auth\ApiTestCase;

/**
 * HTTPS obligatorio, proxies de confianza, Origin, límite de escrituras y cabeceras (Fase 13).
 *
 * @group seguridad
 */
final class HttpHardeningTest extends ApiTestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['app.force_https', 'security.trusted_proxies', 'security.write_limit', 'cors.allowed_origins'] as $k) {
            $this->saved[$k] = Config::get($k);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            Config::set($k, $v);
        }
        parent::tearDown();
    }

    public function testHttpsIsEnforced(): void
    {
        Config::set('app.force_https', true);
        $res = $this->call('GET', '/marketplace/filtros', null, ['Host' => 'api.barberapp.pe']);
        $this->assertSame(308, $res->status());
        $this->assertStringStartsWith('https://api.barberapp.pe/', (string) $res->header('Location'));

        $res = $this->call('POST', '/auth/login', ['email' => 'cliente1@barberapp.test', 'password' => self::PASSWORD]);
        $this->assertSame(403, $res->status());
        $this->assertSame('https_required', $this->errorCode($res));

        // Detrás de un proxy de confianza que informa https: pasa y recibe HSTS.
        Config::set('security.trusted_proxies', ['203.0.113.0/24']);
        $res = $this->call('GET', '/marketplace/filtros', null, ['X-Forwarded-Proto' => 'https']);
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString('max-age=', (string) $res->header('Strict-Transport-Security'));

        // El mismo encabezado desde una IP que no es proxy de confianza no vale.
        Config::set('security.trusted_proxies', ['10.0.0.1']);
        $this->assertSame(308, $this->call('GET', '/marketplace/filtros', null, ['X-Forwarded-Proto' => 'https', 'Host' => 'x.pe'])->status());
    }

    public function testForwardedForOnlyFromTrustedProxies(): void
    {
        // Sin proxy de confianza, X-Forwarded-For no cambia la IP (no sirve para saltarse límites).
        for ($i = 0; $i < 5; $i++) {
            $this->call('POST', '/auth/login', ['email' => 'cliente1@barberapp.test', 'password' => 'mala' . $i], ['X-Forwarded-For' => "198.51.100.{$i}"]);
        }
        $this->assertSame(429, $this->call('POST', '/auth/login', ['email' => 'cliente1@barberapp.test', 'password' => 'mala9'], ['X-Forwarded-For' => '198.51.100.99'])->status());

        Config::set('security.trusted_proxies', ['203.0.113.10']);
        $res = $this->call('POST', '/auth/login', ['email' => 'cliente1@barberapp.test', 'password' => self::PASSWORD], ['X-Forwarded-For' => '198.51.100.7']);
        $this->assertSame(200, $res->status(), 'Con el proxy de confianza cuenta la IP real del cliente');
        $this->assertSame('198.51.100.7', inet_ntop((string) $this->scalar("SELECT ip FROM auditoria WHERE accion = 'auth.login' ORDER BY id DESC LIMIT 1")));
    }

    public function testWritesFromForeignOriginsAreRejected(): void
    {
        Config::set('cors.allowed_origins', ['https://app.barberapp.pe', 'capacitor://localhost']);
        $res = $this->call('POST', '/auth/login', ['email' => 'cliente1@barberapp.test', 'password' => self::PASSWORD], ['Origin' => 'https://evil.example']);
        $this->assertSame(403, $res->status());
        $this->assertSame('origin_not_allowed', $this->errorCode($res));
        $this->assertNull($res->header('Access-Control-Allow-Origin'));

        $ok = $this->call('POST', '/auth/login', ['email' => 'cliente1@barberapp.test', 'password' => self::PASSWORD], ['Origin' => 'capacitor://localhost']);
        $this->assertSame(200, $ok->status());
        $this->assertSame('capacitor://localhost', $ok->header('Access-Control-Allow-Origin'));
        // Página servida desde el mismo host que la API: el navegador envía su propio origen.
        $same = $this->call('POST', '/auth/login', ['email' => 'cliente1@barberapp.test', 'password' => self::PASSWORD], ['Origin' => 'http://barberia.local:8080', 'Host' => 'barberia.local:8080']);
        $this->assertSame(200, $same->status());
        $spoof = $this->call('POST', '/auth/login', ['email' => 'cliente1@barberapp.test', 'password' => self::PASSWORD], ['Origin' => 'http://barberia.local:8080', 'Host' => 'otro.local']);
        $this->assertSame(403, $spoof->status());
        // Las lecturas no se bloquean (solo no reciben cabeceras CORS).
        $this->assertSame(200, $this->call('GET', '/legal', null, ['Origin' => 'https://evil.example'])->status());
    }

    public function testGeneralWriteLimit(): void
    {
        Config::set('security.write_limit', [3, 60]);
        for ($i = 0; $i < 3; $i++) {
            $this->assertNotSame(429, $this->call('POST', '/auth/forgot-password', ['email' => "x{$i}@ejemplo.pe"])->status());
        }
        $res = $this->call('POST', '/auth/register', ['tipo' => 'cliente']);
        $this->assertSame(429, $res->status());
        $this->assertNotNull($res->header('Retry-After'));
        $this->assertSame(200, $this->call('GET', '/legal')->status(), 'Las lecturas no cuentan');
    }

    public function testSecurityHeaders(): void
    {
        $res = $this->call('GET', '/legal');
        $this->assertSame('nosniff', $res->header('X-Content-Type-Options'));
        $this->assertSame('DENY', $res->header('X-Frame-Options'));
        $this->assertStringContainsString("default-src 'none'", (string) $res->header('Content-Security-Policy'));
        $this->assertStringContainsString('geolocation=()', (string) $res->header('Permissions-Policy'));
        $this->assertSame('same-origin', $res->header('Cross-Origin-Opener-Policy'));
    }
}
