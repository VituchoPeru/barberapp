<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\TenantContextMiddleware;

/** Aislamiento multiempresa a nivel de middleware. */
final class TenantContextMiddlewareTest extends DatabaseTestCase
{
    private function router(): Router
    {
        $r = new Router();
        $echo = static function (Request $req): Response {
            return Response::success(['tenant' => $req->attribute('tenant'), 'membership' => $req->attribute('membership')]);
        };
        $r->get('/b/{tenant}/info', $echo, [new TenantContextMiddleware()]);
        $r->get('/panel/info', $echo, [
            // Simula el middleware de autenticación de la Fase 2
            new class () implements \App\Middleware\MiddlewareInterface {
                public function handle(Request $request, callable $next): Response
                {
                    $uid = $request->header('x-test-user');
                    if ($uid !== null) {
                        $request->withAttribute('auth_user', ['id' => (int) $uid]);
                    }
                    return $next($request);
                }
            },
            new TenantContextMiddleware(true),
        ]);
        return $r;
    }

    private function status(Request $req): int
    {
        try {
            return $this->router()->dispatch($req)->status();
        } catch (HttpException $e) {
            return $e->status();
        }
    }

    private function userId(string $email): string
    {
        return (string) $this->scalar('SELECT id FROM usuarios WHERE email = ?', [$email]);
    }

    public function testResolvesActiveTenantFromRouteSlug(): void
    {
        $res = $this->router()->dispatch(new Request('GET', '/b/demo-centro/info'));
        $data = $res->decoded()['data'];
        $this->assertSame('demo-centro', $data['tenant']['slug']);
        $this->assertSame($this->tenantId('demo-centro'), $data['tenant']['id']);
    }

    public function testUnknownSuspendedAndMalformedTenants(): void
    {
        $this->assertSame(404, $this->status(new Request('GET', '/b/no-existe/info')));
        $this->assertSame(403, $this->status(new Request('GET', '/b/demo-suspendida/info')));
        $this->assertSame(404, $this->status(new Request('GET', "/b/x'%20OR%201=1/info")));
    }

    public function testPanelRequiresAuthenticationAndTenant(): void
    {
        $this->assertSame(400, $this->status(new Request('GET', '/panel/info')));
        $this->assertSame(401, $this->status(new Request('GET', '/panel/info', [], ['X-Tenant' => 'demo-centro'])));
    }

    public function testMemberGetsRolesOfThatTenantOnly(): void
    {
        $uid = $this->userId('barbero2.centro@barberapp.test');
        $centro = $this->router()->dispatch(new Request('GET', '/panel/info', [], ['X-Tenant' => 'demo-centro', 'X-Test-User' => $uid]));
        $norte = $this->router()->dispatch(new Request('GET', '/panel/info', [], ['X-Tenant' => 'demo-norte', 'X-Test-User' => $uid]));
        $this->assertSame(['barbero'], $centro->decoded()['data']['membership']['roles']);
        $this->assertSame(['administrador'], $norte->decoded()['data']['membership']['roles']);
    }

    public function testUserCannotEnterAnotherTenantsPanel(): void
    {
        $propNorte = $this->userId('propietario.norte@barberapp.test');
        $this->assertSame(404, $this->status(new Request('GET', '/panel/info', [], ['X-Tenant' => 'demo-centro', 'X-Test-User' => $propNorte])));

        // Superadmin tampoco tiene acceso implícito al panel de una barbería.
        $super = $this->userId('superadmin@barberapp.test');
        $this->assertSame(404, $this->status(new Request('GET', '/panel/info', [], ['X-Tenant' => 'demo-centro', 'X-Test-User' => $super])));
    }

    public function testRevokedMembershipLosesAccess(): void
    {
        $uid = $this->userId('admin.centro@barberapp.test');
        $this->exec("UPDATE membresias SET estado = 'revocado' WHERE usuario_id = ?", [$uid]);
        $this->assertSame(404, $this->status(new Request('GET', '/panel/info', [], ['X-Tenant' => 'demo-centro', 'X-Test-User' => $uid])));
    }
}
