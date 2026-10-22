<?php

declare(strict_types=1);

namespace Tests\Integration\Platform;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Services\Platform\PlatformUserService;

/** Usuarios de plataforma: equipo, búsqueda, bloqueo y roles (Fase 9). */
final class PlatformUsersTest extends PlatformTestCase
{
    public function testSearchIsLimitedAndAudited(): void
    {
        $h = $this->as(self::SUPER);
        $short = $this->get('/usuarios/buscar?q=cl', $h);
        $this->assertSame(422, $short->status());

        $res = $this->ok($this->get('/usuarios/buscar?q=cliente1@barberapp.test', $h));
        $this->assertSame('cliente1@barberapp.test', $res[0]['email']);
        $this->assertArrayNotHasKey('telefono', $res[0]);
        $this->assertArrayNotHasKey('reservas', $res[0]);
        $this->assertSame(1, $this->auditCount('plataforma.usuarios.busqueda', 'exito'));

        $barbero = $this->ok($this->get('/usuarios/buscar?q=barbero2.centro', $h))[0];
        $this->assertEqualsCanonicalizing(['demo-centro', 'demo-norte'], array_column($barbero['barberias'], 'slug'));
        $this->assertLessThanOrEqual(20, count($this->ok($this->get('/usuarios/buscar?q=barberapp.test', $h))));

        $team = $this->ok($this->get('/usuarios', $h));
        $this->assertSame(['superadmin@barberapp.test', 'operador@barberapp.test'], array_column($team, 'email'));
    }

    public function testBlockingClosesSessionsAndPreventsLogin(): void
    {
        $client = $this->as(self::CLIENTE);
        $clientCookies = $this->cookies;
        $this->assertSame(200, $this->call('GET', '/me', null, $client)->status());

        $h = $this->as(self::SUPER);
        $uid = $this->userId(self::CLIENTE);
        $this->assertSame(422, $this->post("/usuarios/{$uid}/bloquear", [], $h)->status());
        $r = $this->ok($this->post("/usuarios/{$uid}/bloquear", ['motivo' => 'Reservas falsas reiteradas'], $h));
        $this->assertSame('bloqueado', $r['estado']);
        $this->assertSame('Reservas falsas reiteradas', $r['motivo_bloqueo']);
        $this->assertGreaterThanOrEqual(1, $r['sesiones_cerradas']);
        $this->assertSame(409, $this->post("/usuarios/{$uid}/bloquear", ['motivo' => 'Otra vez más'], $h)->status());

        $this->cookies = $clientCookies;
        $this->assertSame(401, $this->call('GET', '/me', null, $client)->status(), 'Su sesión abierta ya no sirve');
        $this->cookies = [];
        $login = $this->call('POST', '/auth/login', ['email' => self::CLIENTE, 'password' => self::PASSWORD]);
        $this->assertNotSame(200, $login->status());

        $this->ok($this->post("/usuarios/{$uid}/desbloquear", [], $this->as(self::SUPER)));
        $this->assertSame(200, $this->call('POST', '/auth/login', ['email' => self::CLIENTE, 'password' => self::PASSWORD])->status());
        $this->assertSame(1, $this->auditCount('plataforma.usuario.bloqueado', 'exito'));
        $this->assertSame(1, $this->auditCount('plataforma.usuario.desbloqueado', 'exito'));
    }

    public function testSafetyRulesForPlatformRoles(): void
    {
        $h = $this->as(self::SUPER);
        $me = $this->userId(self::SUPER);
        $this->assertSame('cannot_block_self', $this->errorCode($this->post("/usuarios/{$me}/bloquear", ['motivo' => 'Prueba de seguridad'], $h)));
        $this->assertSame('cannot_change_own_role', $this->errorCode($this->put("/usuarios/{$me}/rol", ['rol' => null], $h)));
        $this->assertSame(422, $this->put("/usuarios/{$me}/rol", ['rol' => 'dios'], $h)->status());

        // Nunca se queda la plataforma sin superadministrador activo (defensa en el servicio).
        $req = new Request('POST', '/api/v1/plataforma/usuarios/x/bloquear');
        $req->withAttribute('auth_user', ['id' => $this->userId(self::OPERADOR)]);
        try {
            (new PlatformUserService())->block($me, 'Intento de dejar sin superadmin', $req);
            $this->fail('Debió impedirse');
        } catch (HttpException $e) {
            $this->assertSame('last_superadmin', $e->errorCode());
        }
        $this->assertSame('activo', $this->scalar('SELECT estado FROM usuarios WHERE id = ?', [$me]));
    }

    public function testGrantingAndRemovingPlatformRole(): void
    {
        $h = $this->as(self::SUPER);
        $prop = $this->userId(self::PROPIETARIO);
        $propH = $this->as(self::PROPIETARIO);
        $propCookies = $this->cookies;
        $this->assertSame(403, $this->get('/dashboard', $propH)->status());

        $h = $this->as(self::SUPER);
        $r = $this->ok($this->put("/usuarios/{$prop}/rol", ['rol' => 'operador'], $h));
        $this->assertSame('operador', $r['rol_plataforma']);
        $this->cookies = $propCookies;
        $this->assertSame(401, $this->get('/dashboard', $propH)->status(), 'El cambio de rol cierra sus sesiones');
        $propH = $this->as(self::PROPIETARIO);
        $this->assertSame(200, $this->get('/dashboard', $propH)->status());
        $this->assertSame(403, $this->get('/usuarios', $propH)->status(), 'El operador no gestiona usuarios');
        // Sigue siendo propietario de su barbería.
        $this->assertSame(200, $this->call('GET', '/panel/contexto', null, $propH + ['X-Tenant' => 'demo-centro'])->status());

        $this->ok($this->put("/usuarios/{$prop}/rol", ['rol' => null], $this->as(self::SUPER)));
        $this->assertNull($this->scalar('SELECT rol_plataforma_id FROM usuarios WHERE id = ?', [$prop]));
        $this->assertSame(2, $this->auditCount('plataforma.usuario.rol', 'exito'));

        $this->exec('UPDATE usuarios SET email_verificado_en = NULL WHERE email = ?', [self::CLIENTE]);
        $res = $this->put('/usuarios/' . $this->userId(self::CLIENTE) . '/rol', ['rol' => 'operador'], $this->as(self::SUPER));
        $this->assertSame('account_not_eligible', $this->errorCode($res));
    }
}
