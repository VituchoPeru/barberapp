<?php

declare(strict_types=1);

namespace Tests\Integration\Privacy;

use App\Services\Mail\Mailer;
use App\Services\Privacy\AccountDeletion;
use Tests\Integration\Booking\BookingTestCase;

/**
 * Eliminar la cuenta desde la app (Fase 13): requisito de App Store y Google Play y derecho de
 * cancelación de la Ley 29733.
 */
final class AccountDeletionTest extends BookingTestCase
{
    public function testClientDeletesAccountAndPersonalDataIsErased(): void
    {
        $h = $this->client(self::CLIENTE1);
        $userId = $this->userId(self::CLIENTE1);
        $clienteId = (int) $this->scalar('SELECT id FROM clientes WHERE usuario_id = ?', [$userId]);

        // Una cita futura y un favorito.
        $res = $this->book($this->body($this->day(3, 3), '10:00') + ['notas' => 'Llego en moto'], $h);
        $this->assertSame(201, $res->status(), $res->body());
        $bookingId = (int) $this->data($res)['id'];
        $this->assertSame(200, $this->call('PUT', '/me/favoritos/demo-centro', [], $h)->status());

        $preview = $this->data($this->call('GET', '/me/eliminacion', null, $h));
        $this->assertSame([], $preview['bloqueos']);
        $this->assertGreaterThanOrEqual(1, $preview['citas_futuras']);

        // Sin contraseña correcta o sin escribir ELIMINAR no pasa nada.
        $bad = $this->call('POST', '/me/eliminar', ['password' => 'otra-clave-1', 'confirmacion' => 'ELIMINAR'], $h);
        $this->assertSame(422, $bad->status());
        $this->assertSame(1, $this->auditCount('privacidad.reautenticacion_fallida', 'denegado'));
        $this->assertSame(422, $this->call('POST', '/me/eliminar', ['password' => self::PASSWORD, 'confirmacion' => 'si'], $h)->status());
        $this->assertSame('activo', $this->scalar('SELECT estado FROM usuarios WHERE id = ?', [$userId]));

        Mailer::reset();
        $res = $this->call('POST', '/me/eliminar', ['password' => self::PASSWORD, 'confirmacion' => 'eliminar'], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $out = $this->data($res);
        $this->assertGreaterThanOrEqual(1, $out['citas_canceladas']);
        $this->assertStringStartsWith('DP', $out['solicitud']);
        $this->assertArrayNotHasKey('barberapp_session', $this->cookies, 'La cookie de sesión se borra');

        // La cita futura quedó cancelada (horario libre) y sin las notas del cliente.
        $this->assertSame('cancelada', $this->scalar('SELECT estado FROM reservas WHERE id = ?', [$bookingId]));
        $this->assertSame('sistema', $this->scalar('SELECT cancelada_por_tipo FROM reservas WHERE id = ?', [$bookingId]));
        $this->assertNull($this->scalar('SELECT notas_cliente FROM reservas WHERE id = ?', [$bookingId]));

        // La cuenta queda anónima y el correo libre.
        $u = $this->row('SELECT * FROM usuarios WHERE id = ?', [$userId]);
        $this->assertSame('eliminado', $u['estado']);
        $this->assertSame('eliminado-' . $userId . '@' . AccountDeletion::EMAIL_DOMAIN, $u['email']);
        $this->assertNull($u['password_hash']);
        $this->assertNull($u['telefono']);
        $this->assertNotNull($u['deleted_at']);
        $c = $this->row('SELECT * FROM clientes WHERE id = ?', [$clienteId]);
        $this->assertNull($c['usuario_id']);
        $this->assertSame('Cliente eliminado', $c['nombres']);
        $this->assertNull($c['email']);
        $this->assertNull($c['telefono']);
        foreach (['sesiones', 'dispositivos_push', 'preferencias_notificacion', 'notificaciones', 'tokens_usuario', 'idempotency_keys'] as $t) {
            $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM {$t} WHERE usuario_id = ?", [$userId]), $t);
        }
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM favoritos WHERE cliente_id = ?', [$clienteId]));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM auditoria WHERE accion = 'privacidad.cuenta_eliminada' AND datos_nuevos LIKE ?", ['%' . self::CLIENTE1 . '%']));

        // Constancia: solicitud de cancelación atendida, solo con el hash del correo.
        $s = $this->row("SELECT * FROM solicitudes_datos WHERE usuario_id = ? AND tipo = 'cancelacion'", [$userId]);
        $this->assertSame('atendida', $s['estado']);
        $this->assertSame(hash('sha256', self::CLIENTE1), $s['email_hash']);

        // Correo de constancia a la dirección anterior.
        $mails = array_filter(Mailer::sent(), static fn ($m) => $m->to === self::CLIENTE1);
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('eliminada', end($mails)->subject);

        // Ya no puede entrar, y puede registrarse de nuevo con el mismo correo.
        $this->cookies = [];
        $this->assertSame(401, $this->call('POST', '/auth/login', ['email' => self::CLIENTE1, 'password' => self::PASSWORD])->status());
        $reg = $this->call('POST', '/auth/register', ['tipo' => 'cliente', 'email' => self::CLIENTE1, 'password' => 'NuevaClave2026', 'nombres' => 'Otra Persona', 'acepta_terminos' => true]);
        $this->assertSame(201, $reg->status(), $reg->body());
    }

    public function testMobileSessionDeletesAccountAndTokensStopWorking(): void
    {
        $tokens = $this->loginMobile(self::CLIENTE2);
        $bearer = ['Authorization' => 'Bearer ' . $tokens['access_token']];
        $res = $this->call('POST', '/me/eliminar', ['password' => self::PASSWORD, 'confirmacion' => 'ELIMINAR'], $bearer);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame(401, $this->call('GET', '/me', null, $bearer)->status());
        $this->assertSame(401, $this->call('POST', '/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->status());
    }

    public function testSoleOwnerAndLastSuperadminCannotDelete(): void
    {
        $h = ['X-CSRF-Token' => $this->loginWeb('propietario.centro@barberapp.test')];
        $preview = $this->data($this->call('GET', '/me/eliminacion', null, $h));
        $this->assertSame('unico_propietario', $preview['bloqueos'][0]['codigo']);
        $this->assertNotEmpty($preview['bloqueos'][0]['barberias']);
        $res = $this->call('POST', '/me/eliminar', ['password' => self::PASSWORD, 'confirmacion' => 'ELIMINAR'], $h);
        $this->assertSame(409, $res->status());
        $this->assertSame('account_deletion_blocked', $this->errorCode($res));
        $this->assertSame('activo', $this->scalar('SELECT estado FROM usuarios WHERE email = ?', ['propietario.centro@barberapp.test']));

        $this->cookies = [];
        $h = ['X-CSRF-Token' => $this->loginWeb('superadmin@barberapp.test')];
        $res = $this->call('POST', '/me/eliminar', ['password' => self::PASSWORD, 'confirmacion' => 'ELIMINAR'], $h);
        $this->assertSame(409, $res->status());
        $this->assertSame('ultimo_superadmin', $this->decodedDetails($res)['bloqueos'][0]['codigo']);
    }

    public function testOwnerWithAnotherActiveOwnerCanDelete(): void
    {
        $tenant = $this->tenantId('demo-norte');
        $other = $this->userId('cliente2@barberapp.test');
        $this->exec("INSERT INTO membresias (tenant_id, usuario_id, rol_id, estado) SELECT ?, ?, id, 'activo' FROM roles WHERE codigo = 'propietario'", [$tenant, $other]);
        $owner = $this->userId('propietario.norte@barberapp.test');
        $h = ['X-CSRF-Token' => $this->loginWeb('propietario.norte@barberapp.test')];
        $preview = $this->data($this->call('GET', '/me/eliminacion', null, $h));
        if ($preview['bloqueos'] !== []) {
            $this->markTestSkipped('propietario.norte es dueño único de otra barbería demo.');
        }
        $this->assertSame(200, $this->call('POST', '/me/eliminar', ['password' => self::PASSWORD, 'confirmacion' => 'ELIMINAR'], $h)->status());
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM membresias WHERE usuario_id = ? AND estado <> 'revocado'", [$owner]));
    }

    public function testBarberAccountIsUnlinkedButTheBarberProfileStays(): void
    {
        $userId = $this->userId('barbero2.centro@barberapp.test');
        $barberId = (int) $this->scalar('SELECT id FROM barberos WHERE usuario_id = ?', [$userId]);
        $h = ['X-CSRF-Token' => $this->loginWeb('barbero2.centro@barberapp.test')];
        $this->assertSame(200, $this->call('POST', '/me/eliminar', ['password' => self::PASSWORD, 'confirmacion' => 'ELIMINAR'], $h)->status());
        $this->assertNull($this->scalar('SELECT usuario_id FROM barberos WHERE id = ?', [$barberId]));
        $this->assertNotNull($this->scalar('SELECT nombre_publico FROM barberos WHERE id = ?', [$barberId]));
        $this->assertSame('revocado', $this->scalar('SELECT estado FROM membresias WHERE usuario_id = ? LIMIT 1', [$userId]));
    }

    public function testDeletionRequiresSessionAndCsrf(): void
    {
        $this->assertSame(401, $this->call('POST', '/me/eliminar', ['password' => self::PASSWORD, 'confirmacion' => 'ELIMINAR'])->status());
        $this->loginWeb(self::CLIENTE1);
        $res = $this->call('POST', '/me/eliminar', ['password' => self::PASSWORD, 'confirmacion' => 'ELIMINAR']);
        $this->assertSame(403, $res->status());
        $this->assertSame('csrf_mismatch', $this->errorCode($res));
    }

    /** @return array<string, mixed> */
    private function row(string $sql, array $params): array
    {
        $stmt = \App\Core\Database::connection()->prepare($sql);
        $stmt->execute($params);
        return (array) $stmt->fetch();
    }

    /** @return array<string, mixed> */
    private function decodedDetails(\App\Core\Response $res): array
    {
        return (array) ($res->decoded()['error']['details'] ?? []);
    }
}
