<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Helpers\Clock;
use App\Services\Mail\Mailer;
use DateTimeImmutable;
use DateTimeZone;

/** Criterio: "Puede recuperar su contraseña" + verificación de correo. */
final class PasswordRecoveryTest extends ApiTestCase
{
    public function testFullPasswordResetFlowClosesAllSessions(): void
    {
        $mobile = $this->loginMobile('cliente1@barberapp.test');
        $this->cookies = [];

        $res = $this->call('POST', '/auth/forgot-password', ['email' => 'Cliente1@barberapp.test']);
        $this->assertSame(202, $res->status());
        $token = $this->tokenFromLastMail();
        $this->assertStringContainsString('restablecer.html?token=', Mailer::sent()[0]->text);

        $weak = $this->call('POST', '/auth/reset-password', ['token' => $token, 'password' => 'abc']);
        $this->assertSame(422, $weak->status(), 'Una contraseña débil no consume el token');

        $ok = $this->call('POST', '/auth/reset-password', ['token' => $token, 'password' => 'NuevaClave2026']);
        $this->assertSame(200, $ok->status(), $ok->body());

        // Todas las sesiones anteriores quedaron cerradas.
        $this->assertSame(401, $this->call('GET', '/me', null, ['Authorization' => 'Bearer ' . $mobile['access_token']])->status());
        $this->assertSame(401, $this->call('POST', '/auth/refresh', ['refresh_token' => $mobile['refresh_token']])->status());

        // La contraseña vieja ya no sirve y la nueva sí.
        $this->assertSame(401, $this->call('POST', '/auth/login', ['email' => 'cliente1@barberapp.test', 'password' => self::PASSWORD])->status());
        $this->loginWeb('cliente1@barberapp.test', 'NuevaClave2026');

        // El token es de un solo uso.
        $this->assertSame(400, $this->call('POST', '/auth/reset-password', ['token' => $token, 'password' => 'OtraClave2026'])->status());
        $this->assertSame(1, $this->auditCount('auth.password_restablecida', 'exito'));
        $sent = Mailer::sent();
        $this->assertSame('Tu contraseña de BARBERAPP cambió', end($sent)->subject);
    }

    public function testUnknownEmailGetsSameResponseAndNoMail(): void
    {
        $known = $this->call('POST', '/auth/forgot-password', ['email' => 'cliente2@barberapp.test']);
        Mailer::reset();
        $unknown = $this->call('POST', '/auth/forgot-password', ['email' => 'no-existe@barberapp.test']);
        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->decoded()['data'], $unknown->decoded()['data']);
        $this->assertSame([], Mailer::sent());
    }

    public function testOnlyLatestResetTokenIsValidAndTokensExpire(): void
    {
        $this->call('POST', '/auth/forgot-password', ['email' => 'cliente2@barberapp.test']);
        $first = $this->tokenFromLastMail();
        $this->call('POST', '/auth/forgot-password', ['email' => 'cliente2@barberapp.test']);
        $second = $this->tokenFromLastMail();

        $this->assertSame(400, $this->call('POST', '/auth/reset-password', ['token' => $first, 'password' => 'Clave2026nueva'])->status());

        Clock::freeze((new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+61 minutes'));
        $expired = $this->call('POST', '/auth/reset-password', ['token' => $second, 'password' => 'Clave2026nueva']);
        $this->assertSame(400, $expired->status());
        $this->assertSame('invalid_token', $this->errorCode($expired));
        $this->assertSame(2, $this->auditCount('auth.restablecer_token_invalido', 'denegado'));
    }

    public function testForgotPasswordIsRateLimitedPerEmail(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(202, $this->call('POST', '/auth/forgot-password', ['email' => 'cliente2@barberapp.test'])->status());
        }
        $this->assertSame(429, $this->call('POST', '/auth/forgot-password', ['email' => 'cliente2@barberapp.test'])->status());
        $this->assertCount(3, Mailer::sent());
    }

    public function testResetPasswordGuessingIsRateLimited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->call('POST', '/auth/reset-password', ['token' => str_repeat('A', 42) . $i, 'password' => 'Clave2026nueva']);
        }
        $this->assertSame(429, $this->call('POST', '/auth/reset-password', ['token' => str_repeat('B', 43), 'password' => 'Clave2026nueva'])->status());
    }

    public function testEmailVerification(): void
    {
        $this->call('POST', '/auth/register', [
            'tipo' => 'cliente', 'email' => 'verifica@barberapp.test', 'password' => 'Tijera2026x', 'nombres' => 'Verifica', 'acepta_terminos' => true,
        ]);
        $firstToken = $this->tokenFromLastMail();
        $csrf = $this->data($this->call('GET', '/auth/csrf'))['csrf_token'];

        // Reenvío: invalida el enlace anterior.
        $resend = $this->call('POST', '/auth/verify-email/resend', [], ['X-CSRF-Token' => $csrf]);
        $this->assertTrue($this->data($resend)['enviado']);
        $token = $this->tokenFromLastMail();
        $this->assertSame(400, $this->call('POST', '/auth/verify-email', ['token' => $firstToken])->status());

        $ok = $this->call('POST', '/auth/verify-email', ['token' => $token]);
        $this->assertSame(200, $ok->status(), $ok->body());
        $this->assertTrue($this->data($ok)['usuario']['email_verificado']);
        $this->assertTrue($this->data($this->call('GET', '/me'))['usuario']['email_verificado']);

        $again = $this->call('POST', '/auth/verify-email/resend', [], ['X-CSRF-Token' => $csrf]);
        $this->assertFalse($this->data($again)['enviado']);
    }

    public function testInvalidVerificationTokens(): void
    {
        foreach (['corto', str_repeat('x', 43), "' OR 1=1 --"] as $token) {
            $this->assertSame(400, $this->call('POST', '/auth/verify-email', ['token' => $token])->status(), $token);
        }
    }
}
