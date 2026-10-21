<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Services\Mail\Mailer;

/** Criterio: "Un usuario puede registrarse" (cliente y propietario). */
final class RegisterTest extends ApiTestCase
{
    /** @return array<string, mixed> */
    private function cliente(array $over = []): array
    {
        return $over + [
            'tipo' => 'cliente', 'email' => 'nuevo.cliente@barberapp.test', 'password' => 'Fade2026corte',
            'nombres' => 'Nuevo', 'apellidos' => 'Cliente', 'telefono' => '+51911111111', 'acepta_terminos' => true,
        ];
    }

    public function testClienteRegistersAndIsLoggedInWithWebSession(): void
    {
        $res = $this->call('POST', '/auth/register', $this->cliente());
        $this->assertSame(201, $res->status(), $res->body());
        $data = $this->data($res);

        $this->assertSame('nuevo.cliente@barberapp.test', $data['usuario']['email']);
        $this->assertFalse($data['usuario']['email_verificado']);
        $this->assertArrayNotHasKey('password_hash', $data['usuario']);
        $this->assertNotEmpty($data['csrf_token']);
        $this->assertArrayHasKey('barberapp_session', $this->cookies, 'Debe fijar la cookie de sesión');

        $cookie = $res->cookies()['barberapp_session'];
        $this->assertStringContainsString('HttpOnly', $cookie);
        $this->assertStringContainsString('SameSite=Lax', $cookie);

        $uid = $this->userId('nuevo.cliente@barberapp.test');
        $hash = (string) $this->scalar('SELECT password_hash FROM usuarios WHERE id = ?', [$uid]);
        $this->assertTrue(password_verify('Fade2026corte', $hash), 'La contraseña se guarda con password_hash()');
        $this->assertNotNull($this->scalar('SELECT acepto_privacidad_en FROM usuarios WHERE id = ?', [$uid]), 'Consentimiento Ley 29733');
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM clientes WHERE usuario_id = ?', [$uid]));

        // Se envió el correo de verificación y el token se guarda solo como hash.
        $token = $this->tokenFromLastMail();
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM tokens_usuario WHERE token_hash = ?', [$token]));
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM tokens_usuario WHERE token_hash = ?', [hash('sha256', $token)]));

        // La sesión funciona.
        $me = $this->call('GET', '/me');
        $this->assertSame(200, $me->status());
        $this->assertTrue($this->data($me)['es_cliente']);
        $this->assertSame(1, $this->auditCount('auth.registro', 'exito'));
    }

    public function testPropietarioRegistersWithBarberiaPendingApproval(): void
    {
        $res = $this->call('POST', '/auth/register', [
            'tipo' => 'propietario', 'email' => 'duena@barberapp.test', 'password' => 'Navaja2026x', 'nombres' => 'Dueña',
            'acepta_terminos' => true, 'canal' => 'movil',
            'barberia' => ['nombre_comercial' => 'Barbería Ñandú & Cía', 'telefono' => '+51 999 888 777', 'ruc' => '20123456789'],
        ]);
        $this->assertSame(201, $res->status(), $res->body());
        $data = $this->data($res);

        $this->assertSame('barberia-nandu-y-cia', $data['barberia']['slug']);
        $this->assertSame('pendiente', $data['barberia']['estado'], 'Con aprobación manual activa la barbería nace pendiente');
        $this->assertArrayHasKey('access_token', $data, 'Canal móvil recibe tokens');
        $this->assertArrayHasKey('refresh_token', $data);
        $this->assertSame([], $this->cookies, 'Canal móvil no usa cookie');

        $tenantId = (int) $data['barberia']['id'];
        $uid = $this->userId('duena@barberapp.test');
        $this->assertSame('propietario', $this->scalar(
            "SELECT r.codigo FROM membresias m JOIN roles r ON r.id = m.rol_id WHERE m.usuario_id = ? AND m.tenant_id = ? AND m.estado = 'activo'",
            [$uid, $tenantId]
        ));
        $this->assertSame($uid, (int) $this->scalar('SELECT propietario_usuario_id FROM tenants WHERE id = ?', [$tenantId]));
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM politicas_reserva WHERE tenant_id = ?', [$tenantId]));

        // La nueva barbería queda como contexto de la sesión y el panel responde aunque esté pendiente.
        $panel = $this->call('GET', '/panel/contexto', null, ['Authorization' => 'Bearer ' . $data['access_token']]);
        $this->assertSame(200, $panel->status(), $panel->body());
        $this->assertSame('pendiente', $this->data($panel)['tenant']['estado']);
    }

    public function testSlugCollisionsAndReservedSlugs(): void
    {
        $base = ['tipo' => 'propietario', 'password' => 'Navaja2026x', 'nombres' => 'Dueño', 'acepta_terminos' => true];
        $a = $this->call('POST', '/auth/register', $base + ['email' => 'a@barberapp.test', 'barberia' => ['nombre_comercial' => 'Demo Centro']]);
        $this->assertSame('demo-centro-2', $this->data($a)['barberia']['slug']);

        $this->cookies = [];
        $b = $this->call('POST', '/auth/register', $base + ['email' => 'b@barberapp.test', 'barberia' => ['nombre_comercial' => 'Otra', 'slug' => 'admin']]);
        $this->assertSame(422, $b->status());
        $this->assertArrayHasKey('barberia.slug', $b->decoded()['error']['details']['fields']);
    }

    public function testValidationErrors(): void
    {
        $weak = $this->call('POST', '/auth/register', $this->cliente(['password' => '12345678']));
        $this->assertSame(422, $weak->status());
        $this->assertArrayHasKey('password', $weak->decoded()['error']['details']['fields']);

        $withEmail = $this->call('POST', '/auth/register', $this->cliente(['password' => 'nuevo.cliente2026']));
        $this->assertSame(422, $withEmail->status(), 'La contraseña no puede contener el correo');

        $terms = $this->call('POST', '/auth/register', $this->cliente(['acepta_terminos' => false]));
        $this->assertSame(422, $terms->status());

        $noShop = $this->call('POST', '/auth/register', $this->cliente(['tipo' => 'propietario']));
        $this->assertSame(422, $noShop->status());

        $badType = $this->call('POST', '/auth/register', $this->cliente(['tipo' => 'superadmin']));
        $this->assertSame(422, $badType->status(), 'Nadie puede registrarse como superadmin');

        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM usuarios WHERE email = 'nuevo.cliente@barberapp.test'"));
        $this->assertSame([], Mailer::sent());
    }

    public function testDuplicateEmailIsRejectedCaseInsensitive(): void
    {
        $res = $this->call('POST', '/auth/register', $this->cliente(['email' => 'CLIENTE1@barberapp.test']));
        $this->assertSame(409, $res->status());
        $this->assertSame('email_taken', $this->errorCode($res));
    }

    public function testRegistrationIsRateLimitedPerIp(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->cookies = [];
            $res = $this->call('POST', '/auth/register', $this->cliente(['email' => "spam{$i}@barberapp.test"]));
            $this->assertSame(201, $res->status());
        }
        $this->cookies = [];
        $blocked = $this->call('POST', '/auth/register', $this->cliente(['email' => 'spam10@barberapp.test']));
        $this->assertSame(429, $blocked->status());
        $this->assertNotNull($blocked->header('Retry-After'));
    }
}
