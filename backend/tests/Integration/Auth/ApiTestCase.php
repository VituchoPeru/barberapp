<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Helpers\Clock;
use App\Middleware\CorsMiddleware;
use App\Middleware\JsonBodyMiddleware;
use App\Middleware\RequestIdMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Services\Auth\Gate;
use App\Services\Mail\Mailer;
use Tests\Integration\DatabaseTestCase;

/**
 * Pruebas de la API completa en proceso: mismas rutas y middleware globales que producción,
 * dentro de la transacción de DatabaseTestCase (todo se revierte al terminar cada prueba).
 */
abstract class ApiTestCase extends DatabaseTestCase
{
    protected const PASSWORD = 'Demo2026!';

    private Application $app;

    /** @var array<string, string> cookies del "navegador" de la prueba */
    protected array $cookies = [];

    protected string $ip = '203.0.113.10';

    protected function setUp(): void
    {
        parent::setUp();
        $router = new Router();
        (require BARBERAPP_BASE . '/routes/api.php')($router);
        $this->app = new Application($router, [
            RequestIdMiddleware::class, SecurityHeadersMiddleware::class, CorsMiddleware::class, \App\Middleware\HttpsMiddleware::class,
            \App\Middleware\MaintenanceMiddleware::class, JsonBodyMiddleware::class, \App\Middleware\WriteRateLimitMiddleware::class,
        ]);
        Mailer::reset();
        Gate::flush();
        \App\Services\Billing\Entitlements::flush();
        \App\Services\Billing\Gateway\Gateways::use(null);
        $this->cookies = [];
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    protected function call(string $method, string $path, ?array $body = null, array $headers = []): Response
    {
        return $this->send($method, $path, $body !== null ? (string) json_encode($body) : null, $headers + ($body !== null ? ['Content-Type' => 'application/json'] : []));
    }

    /**
     * Petición con cuerpo crudo (p. ej. subida de imágenes). La query (?a=b) se separa de la ruta.
     *
     * @param array<string, string> $headers
     */
    protected function send(string $method, string $path, ?string $rawBody = null, array $headers = []): Response
    {
        $query = [];
        if (str_contains($path, '?')) {
            [$path, $qs] = explode('?', $path, 2);
            parse_str($qs, $query);
        }
        if ($this->cookies !== [] && !isset($headers['Cookie'])) {
            $headers['Cookie'] = implode('; ', array_map(static fn ($k, $v) => $k . '=' . rawurlencode($v), array_keys($this->cookies), $this->cookies));
        }
        $headers += ['User-Agent' => 'PHPUnit'];
        $request = new Request($method, '/api/v1' . $path, $query, $headers, $rawBody ?? '', ['REMOTE_ADDR' => $this->ip]);
        $response = $this->app->handle($request);

        foreach ($response->cookies() as $name => $line) {
            preg_match('/^[^=]+=([^;]*)/', $line, $m);
            $value = rawurldecode($m[1] ?? '');
            if ($value === '' || str_contains($line, 'Max-Age=0')) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }
        return $response;
    }

    /** @return array<string, mixed> */
    protected function data(Response $response): array
    {
        $decoded = $response->decoded();
        $this->assertIsArray($decoded, 'Respuesta no JSON: ' . $response->body());
        return (array) ($decoded['data'] ?? []);
    }

    protected function errorCode(Response $response): ?string
    {
        return $response->decoded()['error']['code'] ?? null;
    }

    /** Inicia sesión web y devuelve el token CSRF. */
    protected function loginWeb(string $email, string $password = self::PASSWORD): string
    {
        $res = $this->call('POST', '/auth/login', ['email' => $email, 'password' => $password]);
        $this->assertSame(200, $res->status(), $res->body());
        return (string) $this->data($res)['csrf_token'];
    }

    /** @return array<string, mixed> Tokens de una sesión móvil */
    protected function loginMobile(string $email, string $password = self::PASSWORD): array
    {
        $res = $this->call('POST', '/auth/login', ['email' => $email, 'password' => $password, 'canal' => 'movil', 'dispositivo' => 'Pixel de prueba']);
        $this->assertSame(200, $res->status(), $res->body());
        return $this->data($res);
    }

    protected function userId(string $email): int
    {
        return (int) $this->scalar('SELECT id FROM usuarios WHERE email = ?', [$email]);
    }

    protected function auditCount(string $accion, string $resultado): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM auditoria WHERE accion = ? AND resultado = ?', [$accion, $resultado]);
    }

    /** Extrae el token del último correo enviado (driver array). */
    protected function tokenFromLastMail(): string
    {
        $sent = Mailer::sent();
        $this->assertNotEmpty($sent, 'No se envió ningún correo.');
        $this->assertTrue((bool) preg_match('/token=([A-Za-z0-9\-_]+)/', end($sent)->text, $m), 'El correo no contiene token.');
        return $m[1];
    }
}
