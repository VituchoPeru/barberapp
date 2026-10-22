<?php

declare(strict_types=1);

namespace Tests\Integration\Privacy;

use App\Core\Config;
use App\Services\Mail\Mailer;
use App\Services\Privacy\LegalInfo;
use Tests\Integration\Auth\ApiTestCase;

/** Rectificación, contraseña, correo, descarga de datos y textos legales (Fase 13). */
final class AccountDataTest extends ApiTestCase
{
    private const CLIENTE = 'cliente1@barberapp.test';

    public function testUpdateProfileAlsoUpdatesTheClientProfile(): void
    {
        $h = ['X-CSRF-Token' => $this->loginWeb(self::CLIENTE)];
        $bad = $this->call('PUT', '/me/perfil', ['nombres' => 'A', 'telefono' => 'abc'], $h);
        $this->assertSame(422, $bad->status());
        $this->assertArrayHasKey('nombres', $bad->decoded()['error']['details']['fields']);
        $this->assertArrayHasKey('telefono', $bad->decoded()['error']['details']['fields']);

        $res = $this->call('PUT', '/me/perfil', ['nombres' => 'Lucía', 'apellidos' => 'Quispe', 'telefono' => '+51 987-654-321'], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame('Lucía', $this->data($res)['nombres']);
        $id = $this->userId(self::CLIENTE);
        $this->assertSame('+51987654321', $this->scalar('SELECT telefono FROM usuarios WHERE id = ?', [$id]));
        $this->assertSame('Quispe', $this->scalar('SELECT apellidos FROM clientes WHERE usuario_id = ?', [$id]));
        $this->assertSame(1, $this->auditCount('cuenta.perfil_actualizado', 'exito'));
    }

    public function testChangePasswordClosesOtherSessions(): void
    {
        $other = $this->loginMobile(self::CLIENTE);
        $this->cookies = [];
        $h = ['X-CSRF-Token' => $this->loginWeb(self::CLIENTE)];

        $this->assertSame(422, $this->call('POST', '/me/password', ['password_actual' => 'equivocada1', 'password_nueva' => 'NuevaClave2026'], $h)->status());
        $weak = $this->call('POST', '/me/password', ['password_actual' => self::PASSWORD, 'password_nueva' => '12345678'], $h);
        $this->assertSame(422, $weak->status());
        $this->assertArrayHasKey('password_nueva', $weak->decoded()['error']['details']['fields']);

        Mailer::reset();
        $res = $this->call('POST', '/me/password', ['password_actual' => self::PASSWORD, 'password_nueva' => 'NuevaClave2026'], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertGreaterThanOrEqual(1, $this->data($res)['sesiones_cerradas']);
        $this->assertCount(1, Mailer::sent());
        // Esta sesión sigue; la del teléfono no.
        $this->assertSame(200, $this->call('GET', '/me', null, $h)->status());
        $this->assertSame(401, $this->call('GET', '/me', null, ['Authorization' => 'Bearer ' . $other['access_token']])->status());
        $this->cookies = [];
        $this->assertSame(401, $this->call('POST', '/auth/login', ['email' => self::CLIENTE, 'password' => self::PASSWORD])->status());
        $this->loginWeb(self::CLIENTE, 'NuevaClave2026');
    }

    public function testReauthenticationIsRateLimited(): void
    {
        $h = ['X-CSRF-Token' => $this->loginWeb(self::CLIENTE)];
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(422, $this->call('POST', '/me/password', ['password_actual' => 'equivocada' . $i, 'password_nueva' => 'NuevaClave2026'], $h)->status());
        }
        $res = $this->call('POST', '/me/password', ['password_actual' => self::PASSWORD, 'password_nueva' => 'NuevaClave2026'], $h);
        $this->assertSame(429, $res->status(), 'Ni siquiera la contraseña correcta pasa mientras dura el bloqueo');
    }

    public function testEmailChangeIsConfirmedFromTheNewAddress(): void
    {
        $h = ['X-CSRF-Token' => $this->loginWeb(self::CLIENTE)];
        $taken = $this->call('POST', '/me/email', ['email' => 'cliente2@barberapp.test', 'password' => self::PASSWORD], $h);
        $this->assertSame(422, $taken->status());

        Mailer::reset();
        $res = $this->call('POST', '/me/email', ['email' => 'Nuevo.Correo@Ejemplo.pe', 'password' => self::PASSWORD], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertStringContainsString('n', $this->data($res)['mensaje']);
        $sent = Mailer::sent();
        $this->assertCount(2, $sent, 'Enlace al correo nuevo y aviso al anterior');
        $this->assertSame('nuevo.correo@ejemplo.pe', $sent[0]->to);
        $this->assertSame(self::CLIENTE, $sent[1]->to);
        $this->assertSame(self::CLIENTE, $this->scalar('SELECT email FROM usuarios WHERE id = ?', [$this->userId(self::CLIENTE)]), 'Aún no cambia');
        $this->assertTrue((bool) preg_match('/token=([A-Za-z0-9\-_]+)/', $sent[0]->text, $m));
        $this->assertStringContainsString('cambio=1', $sent[0]->text);

        $this->cookies = [];
        $ok = $this->call('POST', '/auth/confirm-email', ['token' => $m[1]]);
        $this->assertSame(200, $ok->status(), $ok->body());
        $this->assertSame('nuevo.correo@ejemplo.pe', $this->data($ok)['usuario']['email']);
        $this->assertSame(400, $this->call('POST', '/auth/confirm-email', ['token' => $m[1]])->status(), 'Un solo uso');
        $this->assertSame('nuevo.correo@ejemplo.pe', $this->scalar('SELECT email FROM clientes WHERE nombres = ? AND apellidos = ?', ['Cliente Uno', 'Demo']));
        $this->assertSame(401, $this->call('POST', '/auth/login', ['email' => self::CLIENTE, 'password' => self::PASSWORD])->status());
        $this->loginWeb('nuevo.correo@ejemplo.pe');
    }

    public function testExportContainsOnlyOwnDataAndIsRecorded(): void
    {
        $h = ['X-CSRF-Token' => $this->loginWeb(self::CLIENTE)];
        $this->assertSame(422, $this->call('POST', '/me/datos/exportar', ['password' => 'equivocada1'], $h)->status());

        $res = $this->call('POST', '/me/datos/exportar', ['password' => self::PASSWORD], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertStringContainsString('attachment; filename="barberapp-mis-datos-', (string) $res->header('Content-Disposition'));
        $this->assertSame('no-store', $res->header('Cache-Control'));
        $json = json_decode($res->body(), true);
        $this->assertSame('barberapp.datos-personales.v1', $json['formato']);
        $this->assertSame(self::CLIENTE, $json['cuenta']['email']);
        $this->assertNotEmpty($json['reservas'], 'cliente1 tiene reservas demo');
        $this->assertNotEmpty($json['sesiones']);
        $this->assertStringNotContainsString('cliente2@barberapp.test', $res->body());
        $this->assertStringNotContainsString('password_hash', $res->body());
        $this->assertStringNotContainsString('notas_internas', $res->body());
        $this->assertSame('atendida', $this->scalar("SELECT estado FROM solicitudes_datos WHERE usuario_id = ? AND tipo = 'acceso'", [$this->userId(self::CLIENTE)]));
        $this->assertSame(1, $this->auditCount('privacidad.datos_exportados', 'exito'));
    }

    public function testExportIsLimitedPerHour(): void
    {
        $h = ['X-CSRF-Token' => $this->loginWeb(self::CLIENTE)];
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->call('POST', '/me/datos/exportar', ['password' => self::PASSWORD], $h)->status());
        }
        $this->assertSame(429, $this->call('POST', '/me/datos/exportar', ['password' => self::PASSWORD], $h)->status());
    }

    public function testLegalVersionsAreRecordedAndNewVersionsMustBeAccepted(): void
    {
        $pub = $this->call('GET', '/legal');
        $this->assertSame(200, $pub->status());
        $legal = $this->data($pub);
        $this->assertSame(LegalInfo::privacidadVersion(), $legal['privacidad_version']);
        $this->assertFalse($legal['configuracion_completa'], 'Sin datos legales cargados no se inventan');
        $this->assertSame(20, $legal['plazos_dias_habiles']['acceso']);

        $reg = $this->call('POST', '/auth/register', ['tipo' => 'cliente', 'email' => 'nueva@ejemplo.pe', 'password' => 'ClaveSegura2026', 'nombres' => 'Nueva', 'acepta_terminos' => true]);
        $this->assertSame(201, $reg->status(), $reg->body());
        $this->assertSame([], $this->data($reg)['usuario']['legal_pendiente']);
        $this->assertSame(LegalInfo::terminosVersion(), $this->scalar('SELECT terminos_version FROM usuarios WHERE email = ?', ['nueva@ejemplo.pe']));

        // Una versión nueva de la política: se pide aceptarla.
        Config::set('privacy.privacidad_version', '2099-01-01');
        try {
            $h = ['X-CSRF-Token' => (string) $this->data($reg)['csrf_token']];
            $me = $this->data($this->call('GET', '/me', null, $h));
            $this->assertSame(['privacidad'], $me['usuario']['legal_pendiente']);
            $this->assertSame(422, $this->call('POST', '/me/legal/aceptar', ['acepta' => false], $h)->status());
            $ok = $this->call('POST', '/me/legal/aceptar', ['acepta' => true], $h);
            $this->assertSame(200, $ok->status(), $ok->body());
            $this->assertSame([], $this->data($ok)['legal_pendiente']);
            $this->assertSame('2099-01-01', $this->scalar('SELECT privacidad_version FROM usuarios WHERE email = ?', ['nueva@ejemplo.pe']));
        } finally {
            Config::set('privacy.privacidad_version', '2026-10-22');
        }
    }

    public function testCloseOtherSessions(): void
    {
        $mobile = $this->loginMobile(self::CLIENTE);
        $this->cookies = [];
        $h = ['X-CSRF-Token' => $this->loginWeb(self::CLIENTE)];
        $res = $this->call('POST', '/me/sessions/cerrar-otras', [], $h);
        $this->assertSame(200, $res->status());
        $this->assertGreaterThanOrEqual(1, $this->data($res)['sesiones_cerradas']);
        $this->assertSame(200, $this->call('GET', '/me', null, $h)->status());
        $this->assertSame(401, $this->call('GET', '/me', null, ['Authorization' => 'Bearer ' . $mobile['access_token']])->status());
    }
}
