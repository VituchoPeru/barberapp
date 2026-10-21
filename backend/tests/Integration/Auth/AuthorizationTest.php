<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Core\Request;
use App\Services\Auth\Gate;

/**
 * Criterios: "Solo accede a recursos autorizados", "No puede consultar datos de otro tenant"
 * y "Los intentos no autorizados quedan registrados". Roles, permisos y contexto de barbería.
 */
final class AuthorizationTest extends ApiTestCase
{
    public function testMembershipsListRolesPerTenant(): void
    {
        // barbero2 es barbero en demo-centro y administrador en demo-norte.
        $this->loginWeb('barbero2.centro@barberapp.test');
        $memberships = $this->data($this->call('GET', '/me/memberships'));
        $roles = [];
        foreach ($memberships as $m) {
            $roles[$m['tenant']['slug']] = $m['roles'];
        }
        $this->assertSame(['barbero'], $roles['demo-centro']);
        $this->assertSame(['administrador'], $roles['demo-norte']);
        $this->assertCount(2, $memberships);
    }

    public function testContextSelectionDrivesPanelWithoutHeader(): void
    {
        $csrf = $this->loginWeb('barbero2.centro@barberapp.test');
        // Con dos barberías no hay contexto automático.
        $this->assertNull($this->data($this->call('GET', '/me'))['contexto']);
        $this->assertSame(400, $this->call('GET', '/panel/contexto')->status());

        $ctx = $this->call('POST', '/me/context', ['tenant' => 'demo-norte'], ['X-CSRF-Token' => $csrf]);
        $this->assertSame(200, $ctx->status(), $ctx->body());
        $this->assertSame(['administrador'], $this->data($ctx)['contexto']['roles']);
        $this->assertContains('reservas.ver_todas', $this->data($ctx)['contexto']['permisos']);

        $panel = $this->data($this->call('GET', '/panel/contexto'));
        $this->assertSame('demo-norte', $panel['tenant']['slug']);

        // La cabecera X-Tenant tiene prioridad sobre el contexto guardado.
        $centro = $this->data($this->call('GET', '/panel/contexto', null, ['X-Tenant' => 'demo-centro']));
        $this->assertSame(['barbero'], $centro['roles']);
        $this->assertNotContains('reservas.ver_todas', $centro['permisos']);
    }

    public function testCannotSelectOrEnterAnotherTenant(): void
    {
        $csrf = $this->loginWeb('propietario.norte@barberapp.test');
        $sel = $this->call('POST', '/me/context', ['tenant' => 'demo-centro'], ['X-CSRF-Token' => $csrf]);
        $this->assertSame(404, $sel->status());
        $this->assertSame(1, $this->auditCount('authz.contexto_denegado', 'denegado'));

        foreach (['/panel/contexto', '/panel/personal'] as $path) {
            $res = $this->call('GET', $path, null, ['X-Tenant' => 'demo-centro']);
            $this->assertSame(404, $res->status(), $path . ' debe responder 404 sin revelar datos');
            $this->assertStringNotContainsString('Demo Centro', $res->body());
        }
        $invite = $this->call('POST', '/panel/personal', ['email' => 'x@barberapp.test', 'rol' => 'barbero', 'nombres' => 'Equis'], ['X-Tenant' => 'demo-centro', 'X-CSRF-Token' => $csrf]);
        $this->assertSame(404, $invite->status());
        $this->assertSame(3, $this->auditCount('authz.tenant_denegado', 'denegado'));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM usuarios WHERE email = 'x@barberapp.test'"));
    }

    public function testSuperadminHasNoImplicitAccessToBarberias(): void
    {
        $this->loginWeb('superadmin@barberapp.test');
        $me = $this->data($this->call('GET', '/me'));
        $this->assertSame('superadmin', $me['plataforma']['rol']);
        $this->assertContains('plataforma.tenants.gestionar', $me['plataforma']['permisos']);
        $this->assertSame(404, $this->call('GET', '/panel/personal', null, ['X-Tenant' => 'demo-centro'])->status());
    }

    public function testPermissionsByRole(): void
    {
        $this->loginWeb('barbero1.centro@barberapp.test');
        $res = $this->call('GET', '/panel/personal', null, ['X-Tenant' => 'demo-centro']);
        $this->assertSame(403, $res->status(), 'Un barbero no gestiona personal');
        $this->assertSame(1, $this->auditCount('authz.permiso_denegado', 'denegado'));
        $row = $this->db->query("SELECT tenant_id, usuario_id, JSON_UNQUOTE(JSON_EXTRACT(datos_nuevos, '$.permiso')) AS permiso FROM auditoria WHERE accion = 'authz.permiso_denegado'")->fetch();
        $this->assertSame('personal.gestionar', $row['permiso']);
        $this->assertSame($this->tenantId('demo-centro'), (int) $row['tenant_id']);
        $this->assertSame($this->userId('barbero1.centro@barberapp.test'), (int) $row['usuario_id']);

        $this->cookies = [];
        $this->loginWeb('admin.centro@barberapp.test');
        $this->assertSame(403, $this->call('GET', '/panel/personal', null, ['X-Tenant' => 'demo-centro'])->status(), 'El administrador tampoco');

        $this->cookies = [];
        $this->loginWeb('propietario.centro@barberapp.test');
        $staff = $this->call('GET', '/panel/personal', null, ['X-Tenant' => 'demo-centro']);
        $this->assertSame(200, $staff->status());
        $emails = array_column($this->data($staff), 'email');
        $this->assertContains('barbero1.centro@barberapp.test', $emails);
        $this->assertNotContains('barbero1.norte@barberapp.test', $emails, 'Solo personal de su barbería');
    }

    public function testGateRules(): void
    {
        $req = new Request('GET', '/x');
        $this->assertFalse(Gate::allows($req, 'cliente.reservar'), 'Sin usuario no hay permisos');

        $req->withAttribute('auth_user', ['id' => 1, 'rol_plataforma_id' => null]);
        $this->assertTrue(Gate::allows($req, 'cliente.reservar'), 'Toda cuenta puede actuar como cliente');
        $this->assertFalse(Gate::allows($req, 'plataforma.planes.gestionar'));
        $this->assertFalse(Gate::allows($req, 'reservas.ver_todas'), 'Sin contexto de barbería se niega');

        $req->withAttribute('membership', ['id' => 1, 'roles' => ['barbero']]);
        $this->assertTrue(Gate::allows($req, 'reservas.ver_propias'));
        $this->assertFalse(Gate::allows($req, 'reservas.ver_todas'));
        $this->assertFalse(Gate::allows($req, 'reportes.financieros'));
        $this->assertFalse(Gate::allows($req, 'permiso.inexistente'));
    }

    public function testInvitationFlowForNewPerson(): void
    {
        $csrf = $this->loginWeb('propietario.centro@barberapp.test');
        $res = $this->call('POST', '/panel/personal', ['email' => 'Nueva.Barbera@barberapp.test', 'rol' => 'barbero', 'nombres' => 'Nueva'], ['X-Tenant' => 'demo-centro', 'X-CSRF-Token' => $csrf]);
        $this->assertSame(201, $res->status(), $res->body());
        $inv = $this->data($res);
        $this->assertTrue($inv['cuenta_nueva']);
        $this->assertSame('invitado', $inv['estado']);

        // La persona invitada crea su contraseña con el enlace del correo.
        $token = $this->tokenFromLastMail();
        $this->cookies = [];
        $this->assertSame(401, $this->call('POST', '/auth/login', ['email' => 'nueva.barbera@barberapp.test', 'password' => 'Cualquiera1'])->status(), 'Sin contraseña no se puede entrar');
        $this->assertSame(200, $this->call('POST', '/auth/reset-password', ['token' => $token, 'password' => 'Barbera2026x'])->status());
        $csrf2 = $this->loginWeb('nueva.barbera@barberapp.test', 'Barbera2026x');

        // Mientras no acepte, no entra al panel.
        $this->assertSame(404, $this->call('GET', '/panel/contexto', null, ['X-Tenant' => 'demo-centro'])->status());
        $memberships = $this->data($this->call('GET', '/me/memberships'));
        $this->assertSame([], $memberships[0]['roles']);
        $this->assertSame($inv['membresia_id'], $memberships[0]['invitaciones'][0]['membresia_id']);

        $accept = $this->call('POST', '/me/memberships/' . $inv['membresia_id'] . '/aceptar', [], ['X-CSRF-Token' => $csrf2]);
        $this->assertSame(200, $accept->status(), $accept->body());
        $panel = $this->call('GET', '/panel/contexto', null, ['X-Tenant' => 'demo-centro']);
        $this->assertSame(200, $panel->status());
        $this->assertSame(['barbero'], $this->data($panel)['roles']);
        $this->assertSame(404, $this->call('POST', '/me/memberships/' . $inv['membresia_id'] . '/aceptar', [], ['X-CSRF-Token' => $csrf2])->status(), 'Solo se responde una vez');
    }

    public function testInvitationOfExistingUserAndRevocation(): void
    {
        $csrf = $this->loginWeb('propietario.centro@barberapp.test');
        $h = ['X-Tenant' => 'demo-centro', 'X-CSRF-Token' => $csrf];

        $dup = $this->call('POST', '/panel/personal', ['email' => 'barbero1.centro@barberapp.test', 'rol' => 'barbero'], $h);
        $this->assertSame(409, $dup->status());

        $inv = $this->data($this->call('POST', '/panel/personal', ['email' => 'cliente1@barberapp.test', 'rol' => 'administrador'], $h));
        $this->assertFalse($inv['cuenta_nueva']);

        // Otra persona no puede aceptar una invitación ajena.
        $this->cookies = [];
        $csrfOther = $this->loginWeb('cliente2@barberapp.test');
        $this->assertSame(404, $this->call('POST', '/me/memberships/' . $inv['membresia_id'] . '/aceptar', [], ['X-CSRF-Token' => $csrfOther])->status());

        // El propietario revoca el acceso de un barbero: pierde el panel al instante.
        $this->cookies = [];
        $this->loginWeb('barbero1.centro@barberapp.test');
        $this->assertSame(200, $this->call('GET', '/panel/contexto', null, ['X-Tenant' => 'demo-centro'])->status());
        $barberCookie = $this->cookies;

        $this->cookies = [];
        $csrf = $this->loginWeb('propietario.centro@barberapp.test');
        $h['X-CSRF-Token'] = $csrf;
        $mid = (int) $this->scalar(
            "SELECT m.id FROM membresias m JOIN usuarios u ON u.id = m.usuario_id WHERE u.email = 'barbero1.centro@barberapp.test' AND m.tenant_id = ?",
            [$this->tenantId('demo-centro')]
        );
        $this->assertSame(204, $this->call('DELETE', '/panel/personal/' . $mid, null, $h)->status());

        $this->cookies = $barberCookie;
        $this->assertSame(404, $this->call('GET', '/panel/contexto', null, ['X-Tenant' => 'demo-centro'])->status());

        // No puede revocarse una membresía de otra barbería usando su id.
        $this->cookies = [];
        $csrf = $this->loginWeb('propietario.centro@barberapp.test');
        $norteMember = (int) $this->scalar('SELECT id FROM membresias WHERE tenant_id = ? LIMIT 1', [$this->tenantId('demo-norte')]);
        $this->assertSame(404, $this->call('DELETE', '/panel/personal/' . $norteMember, null, ['X-Tenant' => 'demo-centro', 'X-CSRF-Token' => $csrf])->status());
        $this->assertSame('activo', $this->scalar('SELECT estado FROM membresias WHERE id = ?', [$norteMember]));

        // Y el único propietario no puede quitarse a sí mismo.
        $own = (int) $this->scalar(
            "SELECT m.id FROM membresias m JOIN usuarios u ON u.id = m.usuario_id WHERE u.email = 'propietario.centro@barberapp.test'"
        );
        $this->assertSame(422, $this->call('DELETE', '/panel/personal/' . $own, null, ['X-Tenant' => 'demo-centro', 'X-CSRF-Token' => $csrf])->status());
    }

    public function testInvitingRequiresVerifiedEmail(): void
    {
        $this->exec("UPDATE usuarios SET email_verificado_en = NULL WHERE email = 'propietario.centro@barberapp.test'");
        $csrf = $this->loginWeb('propietario.centro@barberapp.test');
        $res = $this->call('POST', '/panel/personal', ['email' => 'z@barberapp.test', 'rol' => 'barbero', 'nombres' => 'Zeta'], ['X-Tenant' => 'demo-centro', 'X-CSRF-Token' => $csrf]);
        $this->assertSame(403, $res->status());
        $this->assertSame('email_not_verified', $this->errorCode($res));
    }

    public function testOwnersCannotInviteOwnersOrPlatformRoles(): void
    {
        $csrf = $this->loginWeb('propietario.centro@barberapp.test');
        foreach (['propietario', 'superadmin', 'cliente'] as $rol) {
            $res = $this->call('POST', '/panel/personal', ['email' => 'r@barberapp.test', 'rol' => $rol, 'nombres' => 'Erre'], ['X-Tenant' => 'demo-centro', 'X-CSRF-Token' => $csrf]);
            $this->assertSame(422, $res->status(), $rol);
        }
    }
}
