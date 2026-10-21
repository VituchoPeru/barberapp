<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Helpers\Clock;
use App\Services\Auth\TokenSigner;
use DateTimeImmutable;
use DateTimeZone;

/** Criterio: "Puede iniciar y cerrar sesión" + sesiones seguras (web y móvil). */
final class LoginSessionTest extends ApiTestCase
{
    public function testWebLoginLogoutAndCsrf(): void
    {
        $csrf = $this->loginWeb('cliente1@barberapp.test');
        $this->assertSame(200, $this->call('GET', '/me')->status());

        // Sin token CSRF, una petición que modifica datos con cookie se rechaza.
        $noCsrf = $this->call('POST', '/auth/logout', []);
        $this->assertSame(403, $noCsrf->status());
        $this->assertSame('csrf_mismatch', $this->errorCode($noCsrf));
        $bad = $this->call('POST', '/auth/logout', [], ['X-CSRF-Token' => 'x' . substr($csrf, 1)]);
        $this->assertSame(403, $bad->status());
        $this->assertGreaterThanOrEqual(2, $this->auditCount('auth.csrf_invalido', 'denegado'));

        // GET /auth/csrf devuelve el mismo token (útil tras recargar la página).
        $this->assertSame($csrf, $this->data($this->call('GET', '/auth/csrf'))['csrf_token']);

        $out = $this->call('POST', '/auth/logout', [], ['X-CSRF-Token' => $csrf]);
        $this->assertSame(200, $out->status(), $out->body());
        $this->assertArrayNotHasKey('barberapp_session', $this->cookies, 'Logout borra la cookie');
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM sesiones WHERE motivo_revocacion = 'logout'"));
    }

    public function testStolenCookieIsUselessAfterLogout(): void
    {
        $csrf = $this->loginWeb('cliente1@barberapp.test');
        $stolen = $this->cookies['barberapp_session'];
        $this->call('POST', '/auth/logout', [], ['X-CSRF-Token' => $csrf]);

        $res = $this->call('GET', '/me', null, ['Cookie' => 'barberapp_session=' . $stolen]);
        $this->assertSame(401, $res->status());
        $this->assertSame('session_expired', $this->errorCode($res));
        $this->assertSame(1, $this->auditCount('auth.sesion_invalida', 'denegado'));
    }

    public function testWrongPasswordAndUnknownEmailGiveTheSameAnswer(): void
    {
        $wrong = $this->call('POST', '/auth/login', ['email' => 'cliente1@barberapp.test', 'password' => 'Incorrecta1']);
        $unknown = $this->call('POST', '/auth/login', ['email' => 'nadie@barberapp.test', 'password' => 'Incorrecta1']);
        $this->assertSame(401, $wrong->status());
        $this->assertSame(401, $unknown->status());
        $this->assertSame($wrong->decoded()['error'], $unknown->decoded()['error']);
        $this->assertSame(2, $this->auditCount('auth.login_fallido', 'denegado'), 'Los intentos fallidos quedan registrados');
        $this->assertSame([], $this->cookies);
    }

    public function testLoginIsLimitedAfterFiveFailures(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(401, $this->call('POST', '/auth/login', ['email' => 'cliente2@barberapp.test', 'password' => "Mala{$i}xx1"])->status());
        }
        // Ni siquiera la contraseña correcta entra mientras dura el bloqueo.
        $blocked = $this->call('POST', '/auth/login', ['email' => 'cliente2@barberapp.test', 'password' => self::PASSWORD]);
        $this->assertSame(429, $blocked->status());
        $this->assertSame('too_many_attempts', $this->errorCode($blocked));
        $this->assertGreaterThan(0, (int) $blocked->header('Retry-After'));
        $this->assertSame(1, $this->auditCount('auth.login_limitado', 'denegado'));

        // Otra IP no queda bloqueada para esa cuenta (evita que un atacante bloquee al dueño).
        $this->ip = '198.51.100.7';
        $this->assertSame(200, $this->call('POST', '/auth/login', ['email' => 'cliente2@barberapp.test', 'password' => self::PASSWORD])->status());

        // Pasada la ventana de 15 minutos, la primera IP vuelve a poder entrar.
        $this->ip = '203.0.113.10';
        Clock::freeze((new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+16 minutes'));
        $this->assertSame(200, $this->call('POST', '/auth/login', ['email' => 'cliente2@barberapp.test', 'password' => self::PASSWORD])->status());
    }

    public function testBlockedAccountCannotLogInAndLosesActiveSessions(): void
    {
        $tokens = $this->loginMobile('cliente2@barberapp.test');
        $this->exec("UPDATE usuarios SET estado = 'bloqueado' WHERE email = 'cliente2@barberapp.test'");

        $res = $this->call('POST', '/auth/login', ['email' => 'cliente2@barberapp.test', 'password' => self::PASSWORD]);
        $this->assertSame(403, $res->status());
        $this->assertSame('account_disabled', $this->errorCode($res));
        $this->assertSame(401, $this->call('GET', '/me', null, ['Authorization' => 'Bearer ' . $tokens['access_token']])->status());
    }

    public function testMobileAccessTokenAndRefreshRotation(): void
    {
        $tokens = $this->loginMobile('cliente1@barberapp.test');
        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertSame(900, $tokens['expires_in']);
        $auth = ['Authorization' => 'Bearer ' . $tokens['access_token']];

        $me = $this->call('GET', '/me', null, $auth);
        $this->assertSame(200, $me->status());
        $this->assertSame('movil', $this->data($me)['sesion']['canal']);

        // Bearer no usa cookie, por eso no necesita CSRF.
        $this->assertSame(200, $this->call('POST', '/me/context', ['tenant' => null], $auth)->status());

        // Rotación: el refresh token se cambia en cada uso.
        $r1 = $this->call('POST', '/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
        $this->assertSame(200, $r1->status(), $r1->body());
        $new = $this->data($r1);
        $this->assertNotSame($tokens['refresh_token'], $new['refresh_token']);
        $this->assertSame(200, $this->call('GET', '/me', null, ['Authorization' => 'Bearer ' . $new['access_token']])->status());

        // Reutilizar el refresh token viejo = posible robo → se revoca toda la sesión.
        $reuse = $this->call('POST', '/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
        $this->assertSame(401, $reuse->status());
        $this->assertSame(1, $this->auditCount('auth.refresh_reutilizado', 'denegado'));
        $this->assertSame(401, $this->call('GET', '/me', null, ['Authorization' => 'Bearer ' . $new['access_token']])->status(), 'El access token nuevo también deja de valer');
        $this->assertSame(401, $this->call('POST', '/auth/refresh', ['refresh_token' => $new['refresh_token']])->status());
    }

    public function testAccessTokenExpiresAfterFifteenMinutes(): void
    {
        $tokens = $this->loginMobile('cliente1@barberapp.test');
        Clock::freeze((new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+16 minutes'));
        $res = $this->call('GET', '/me', null, ['Authorization' => 'Bearer ' . $tokens['access_token']]);
        $this->assertSame(401, $res->status());
        $this->assertSame('invalid_token', $this->errorCode($res));

        // El refresh token sigue vigente y entrega un access token nuevo.
        $refreshed = $this->call('POST', '/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
        $this->assertSame(200, $refreshed->status());
        $this->assertSame(200, $this->call('GET', '/me', null, ['Authorization' => 'Bearer ' . $this->data($refreshed)['access_token']])->status());
    }

    public function testForgedOrTamperedTokensAreRejected(): void
    {
        $tokens = $this->loginMobile('cliente1@barberapp.test');
        [$h, $p, $s] = explode('.', $tokens['access_token']);
        $claims = json_decode((string) base64_decode(strtr($p, '-_', '+/')), true);
        $claims['sub'] = $this->userId('superadmin@barberapp.test');
        $forgedPayload = rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=');

        $none = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=');
        foreach ([$h . '.' . $forgedPayload . '.' . $s, $none . '.' . $p . '.', 'basura', $h . '.' . $p . '.AAAA'] as $bad) {
            $this->assertSame(401, $this->call('GET', '/me', null, ['Authorization' => 'Bearer ' . $bad])->status(), $bad);
        }
        // Un token bien firmado pero con sub de otra persona tampoco sirve (la sesión no es suya).
        $valid = TokenSigner::sign(['sub' => $claims['sub'], 'sid' => $claims['sid'], 'typ' => 'access', 'exp' => time() + 60]);
        $this->assertSame(401, $this->call('GET', '/me', null, ['Authorization' => 'Bearer ' . $valid])->status());
        $this->assertGreaterThanOrEqual(5, $this->auditCount('auth.token_invalido', 'denegado'));
    }

    public function testWebSessionExpiresAfterInactivity(): void
    {
        $this->loginWeb('cliente1@barberapp.test');
        Clock::freeze((new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+121 minutes'));
        $res = $this->call('GET', '/me');
        $this->assertSame(401, $res->status());
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM sesiones WHERE motivo_revocacion = 'inactividad'"));
    }

    public function testLogoutEverywhereAndSessionManagement(): void
    {
        $mobile = $this->loginMobile('cliente1@barberapp.test');
        $csrf = $this->loginWeb('cliente1@barberapp.test');

        $list = $this->data($this->call('GET', '/me/sessions'));
        $this->assertCount(2, $list);
        $other = array_values(array_filter($list, static fn ($s) => !$s['actual']))[0];
        $this->assertSame('Pixel de prueba', $other['nombre_dispositivo']);

        // Cerrar la sesión del celular desde la web.
        $this->assertSame(204, $this->call('DELETE', '/me/sessions/' . $other['id'], null, ['X-CSRF-Token' => $csrf])->status());
        $this->assertSame(401, $this->call('GET', '/me', null, ['Authorization' => 'Bearer ' . $mobile['access_token']])->status());

        // No se pueden cerrar sesiones de otra persona.
        $foreign = $this->loginMobile('cliente2@barberapp.test');
        $this->assertSame(404, $this->call('DELETE', '/me/sessions/' . $foreign['sesion']['id'], null, ['X-CSRF-Token' => $csrf])->status());

        $second = $this->loginMobile('cliente1@barberapp.test');
        $all = $this->call('POST', '/auth/logout', ['todas' => true], ['Authorization' => 'Bearer ' . $second['access_token']]);
        $this->assertSame(200, $all->status());
        $this->assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM sesiones WHERE usuario_id = ? AND revocada_en IS NULL', [$this->userId('cliente1@barberapp.test')]
        ));
        $this->assertSame(200, $this->call('GET', '/me', null, ['Authorization' => 'Bearer ' . $foreign['access_token']])->status(), 'Las sesiones de otros usuarios siguen');
    }

    public function testProtectedEndpointsRequireAuthentication(): void
    {
        foreach (['/me', '/me/memberships', '/me/sessions', '/auth/csrf', '/panel/contexto'] as $path) {
            $res = $this->call('GET', $path, null, ['X-Tenant' => 'demo-centro']);
            $this->assertSame(401, $res->status(), $path);
        }
        $this->assertSame(401, $this->call('POST', '/auth/logout', [])->status());
    }
}
