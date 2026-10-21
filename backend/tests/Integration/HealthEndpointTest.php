<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Application;
use App\Core\Request;
use App\Core\Router;
use App\Middleware\CorsMiddleware;
use App\Middleware\JsonBodyMiddleware;
use App\Middleware\RequestIdMiddleware;
use App\Middleware\SecurityHeadersMiddleware;

final class HealthEndpointTest extends DatabaseTestCase
{
    private function app(): Application
    {
        $router = new Router();
        (require BARBERAPP_BASE . '/routes/api.php')($router);
        return new Application($router, [
            RequestIdMiddleware::class, SecurityHeadersMiddleware::class, CorsMiddleware::class, JsonBodyMiddleware::class,
        ]);
    }

    public function testHealthReportsDatabaseAndMigrations(): void
    {
        $res = $this->app()->handle(new Request('GET', '/api/v1/health'));
        $body = $res->decoded();

        $this->assertSame(200, $res->status());
        $this->assertTrue($body['ok']);
        $this->assertSame('ok', $body['data']['status']);
        $this->assertSame('ok', $body['data']['checks']['database']['status']);
        $this->assertSame('+00:00', $body['data']['checks']['database']['session_time_zone']);
        $this->assertSame(0, $body['data']['checks']['migrations']['pendientes']);
        $this->assertSame(32, strlen((string) $res->header('X-Request-Id')));
        $this->assertSame($res->header('X-Request-Id'), $body['meta']['request_id']);
        $this->assertSame('nosniff', $res->header('X-Content-Type-Options'));
    }

    public function testUnknownRouteAndWrongMethodUseErrorEnvelope(): void
    {
        $res = $this->app()->handle(new Request('GET', '/api/v1/no-existe'));
        $this->assertSame(404, $res->status());
        $this->assertSame('route_not_found', $res->decoded()['error']['code']);

        $res = $this->app()->handle(new Request('POST', '/api/v1/health'));
        $this->assertSame(405, $res->status());
        $this->assertSame('GET, HEAD', $res->header('Allow'));
    }

    public function testInvalidJsonBodyIsRejected(): void
    {
        $res = $this->app()->handle(new Request('POST', '/api/v1/health', [], ['Content-Type' => 'application/json'], '{malo'));
        $this->assertSame(400, $res->status());
        $this->assertSame('invalid_json', $res->decoded()['error']['code']);
    }

    public function testRealHttpServerServesHealth(): void
    {
        $this->db->rollBack();
        $port = random_int(20000, 40000);
        $env = array_merge(getenv(), ['DB_DATABASE' => BARBERAPP_TEST_DB, 'APP_ENV' => 'testing']);
        $cmd = [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', BARBERAPP_BASE . '/public', BARBERAPP_BASE . '/public/index.php'];
        $proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        $this->assertIsResource($proc);
        try {
            $body = false;
            for ($i = 0; $i < 50 && $body === false; $i++) {
                usleep(100000);
                $body = @file_get_contents("http://127.0.0.1:{$port}/api/v1/health");
            }
            $this->assertNotFalse($body, 'El servidor PHP no respondió');
            $data = json_decode((string) $body, true);
            $this->assertSame('ok', $data['data']['status']);
            $this->assertSame('testing', $data['data']['environment']);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            $this->db->beginTransaction();
        }
    }
}
