<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\MiddlewareInterface;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $r = new Router();
        $r->group('/api/v1', static function (Router $r): void {
            $r->get('/reservas/{id:\d+}', static fn (Request $req) => Response::success(['id' => $req->param('id')]));
            $r->post('/reservas', static fn () => Response::success('creada', 201));
            $r->get('/b/{tenant}/servicios', static fn (Request $req) => Response::success($req->param('tenant')));
        });
        return $r;
    }

    public function testMatchesParametersWithConstraint(): void
    {
        $res = $this->router()->dispatch(new Request('GET', '/api/v1/reservas/42'));
        $this->assertSame(200, $res->status());
        $this->assertSame(['ok' => true, 'data' => ['id' => '42']], $res->decoded());
    }

    public function testConstraintRejectsNonNumeric(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('La ruta solicitada no existe.');
        $this->router()->dispatch(new Request('GET', '/api/v1/reservas/abc'));
    }

    public function testTrailingSlashAndDuplicateSlashesNormalized(): void
    {
        $res = $this->router()->dispatch(new Request('GET', '//api/v1/b/demo-centro/servicios/'));
        $this->assertSame('demo-centro', $res->decoded()['data']);
    }

    public function testMethodNotAllowedReturns405WithAllowHeader(): void
    {
        try {
            $this->router()->dispatch(new Request('DELETE', '/api/v1/reservas'));
            $this->fail('Se esperaba 405');
        } catch (HttpException $e) {
            $this->assertSame(405, $e->status());
            $this->assertSame('POST', $e->headers()['Allow']);
        }
    }

    public function testHeadUsesGetRoute(): void
    {
        $res = $this->router()->dispatch(new Request('HEAD', '/api/v1/reservas/1'));
        $this->assertSame(200, $res->status());
    }

    public function testMiddlewareRunsInOrderAndCanShortCircuit(): void
    {
        $log = [];
        $mw = static function (string $name, bool $stop = false) use (&$log): MiddlewareInterface {
            return new class ($name, $stop, $log) implements MiddlewareInterface {
                /** @var array<int, string> */
                private array $log;

                /** @param array<int, string> $log */
                public function __construct(private string $name, private bool $stop, array &$log)
                {
                    $this->log = &$log;
                }

                public function handle(Request $request, callable $next): Response
                {
                    $this->log[] = $this->name;
                    return $this->stop ? Response::error(401, 'unauthenticated', 'stop') : $next($request);
                }
            };
        };

        $r = new Router();
        $r->group('/g', static function (Router $r) use ($mw): void {
            $r->get('/ok', static fn () => Response::success('fin'), [$mw('ruta')]);
            $r->get('/stop', static fn () => Response::success('no-debe-llegar'), [$mw('bloqueo', true)]);
        }, [$mw('grupo')]);

        $this->assertSame(200, $r->dispatch(new Request('GET', '/g/ok'))->status());
        $this->assertSame(['grupo', 'ruta'], $log);

        $log = [];
        $this->assertSame(401, $r->dispatch(new Request('GET', '/g/stop'))->status());
        $this->assertSame(['grupo', 'bloqueo'], $log);
    }
}
