<?php

declare(strict_types=1);

namespace Tests\Integration\Notifications;

use App\Helpers\Clock;
use App\Services\Mail\Mailer;
use App\Services\Notifications\AttendanceToken;
use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\Push\ArrayPushTransport;

/**
 * API de avisos (Fase 8): reglas configurables por barbería, preferencias de cada persona,
 * bandeja, dispositivos, confirmación de asistencia desde el aviso, métricas y aislamiento.
 */
final class NotificationsApiTest extends NotificationsTestCase
{
    private const C1 = 'cliente1@barberapp.test';
    private const C2 = 'cliente2@barberapp.test';

    public function testRulesAreConfigurableOnlyWithPermissionAndValidated(): void
    {
        $h = $this->actingAs(self::BARBERO1);
        $this->assertSame(403, $this->call('PUT', '/panel/notificaciones/reglas', ['canal_email' => false], $h)->status());
        $this->assertSame(403, $this->call('GET', '/panel/notificaciones/metricas', null, $h)->status());

        $h = $this->actingAs(self::ADMIN);
        $res = $this->call('GET', '/panel/notificaciones/reglas', null, $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame([24, 2], $this->data($res)['recordatorios_horas'], 'Por defecto: el parámetro global de la plataforma');

        foreach ([[[0]], [[73]], [[1, 2, 3, 4]], ['24'], [['x']]] as [$bad]) {
            $res = $this->call('PUT', '/panel/notificaciones/reglas', ['recordatorios_horas' => $bad], $h);
            $this->assertSame(422, $res->status(), json_encode($bad));
        }
        $res = $this->call('PUT', '/panel/notificaciones/reglas', ['recordatorios_horas' => [3, 48, 3], 'canal_email' => false, 'pedir_confirmacion' => false], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame([48, 3], $this->data($res)['recordatorios_horas'], 'Sin repetidos y de mayor a menor');
        $this->assertFalse($this->data($res)['canal_email']);
        $this->assertSame(1, $this->auditCount('notificaciones.reglas_actualizadas', 'exito'));

        // Otra barbería conserva sus reglas.
        $this->assertSame([24, 2], (new NotificationSettings($this->tenantId('demo-norte')))->hours());

        // Las reservas nuevas usan las reglas vigentes: 48 h y 3 h, sin correo.
        NotificationSettings::flush();
        $r = $this->bookAs(self::C1, $this->day(3, 3), '10:00');
        $tipos = $this->db->query("SELECT DISTINCT canal FROM notificaciones WHERE reserva_id = {$r['id']}")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertNotContains('email', $tipos);
        $this->reminder((int) $r['id'], 48, 'push');
        $this->reminder((int) $r['id'], 3, 'interna');
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM notificaciones WHERE reserva_id = ? AND dedupe_key LIKE '%:24h:%'", [$r['id']]));
    }

    public function testRemovedRuleCancelsPendingRemindersAndPlannerCreatesNewOnes(): void
    {
        $r = $this->bookAs(self::C1, $this->day(3, 3), '10:00');
        $id = (int) $r['id'];
        $h = $this->actingAs(self::ADMIN);
        $this->assertSame(200, $this->call('PUT', '/panel/notificaciones/reglas', ['recordatorios_horas' => [12]], $h)->status());
        NotificationSettings::flush();

        $this->freezeAt($this->inicio($id), -24 * 60 + 1);
        $this->work();
        $this->assertSame('cancelada', $this->reminder($id, 24, 'push')['estado']);
        $this->assertStringContainsString('quitó este recordatorio', (string) $this->reminder($id, 24, 'push')['ultimo_error']);

        // El planificador crea el de 12 h cuando se acerca su hora (la cita es anterior a la regla).
        $this->freezeAt($this->inicio($id), -12 * 60 - 10);
        $stats = $this->work();
        $this->assertGreaterThanOrEqual(3, $stats['planificados']);
        $this->freezeAt($this->inicio($id), -12 * 60);
        $this->work();
        $this->assertSame('enviada', $this->reminder($id, 12, 'push')['estado']);
    }

    public function testPersonalPreferencesAreRespectedEvenAfterScheduling(): void
    {
        $r = $this->bookAs(self::C1, $this->day(3, 3), '10:00');
        $id = (int) $r['id'];
        $h = $this->client();
        $res = $this->call('GET', '/me/preferencias-notificacion', null, $h);
        $this->assertSame(200, $res->status(), $res->body());
        $cats = array_column($this->data($res)['categorias'], null, 'categoria');
        $this->assertSame(['recordatorios', 'cambios'], array_keys($cats), 'Un cliente sin membresías no ve "agenda"');
        $this->assertTrue($cats['recordatorios']['email']);

        $res = $this->call('PUT', '/me/preferencias-notificacion', ['recordatorios' => ['email' => false]], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame(422, $this->call('PUT', '/me/preferencias-notificacion', ['recordatorios' => ['sms' => true]], $h)->status());
        $this->assertSame(422, $this->call('PUT', '/me/preferencias-notificacion', ['recordatorios' => ['push' => 'no']], $h)->status());

        // El recordatorio por correo ya estaba en cola: al enviarlo se respeta la preferencia nueva.
        $this->freezeAt($this->inicio($id), -24 * 60 + 1);
        Mailer::reset();
        $this->work();
        $mail = $this->reminder($id, 24, 'email');
        $this->assertSame('omitida', $mail['estado']);
        $this->assertSame([], array_filter(Mailer::sent(), static fn ($m) => str_starts_with($m->subject, 'Recordatorio')));
        $this->assertSame('enviada', $this->reminder($id, 24, 'push')['estado']);

        // La barbería apaga el push: tampoco sale aunque la persona lo tenga activo.
        $this->freezeAt($this->inicio($id), -24 * 60 + 2);
        $this->assertSame(200, $this->call('PUT', '/panel/notificaciones/reglas', ['canal_push' => false], $this->actingAs('propietario.centro@barberapp.test'))->status());
        NotificationSettings::flush();
        $this->freezeAt($this->inicio($id), -120);
        $this->work();
        $this->assertSame('omitida', $this->reminder($id, 2, 'push')['estado']);
        $this->assertSame('enviada', $this->reminder($id, 2, 'interna')['estado'], 'La bandeja siempre recibe el aviso');
    }

    public function testAttendanceCanBeConfirmedFromTheReminderLink(): void
    {
        $f = $this->day(3, 3);
        $r = $this->bookAs(self::C1, $f, '10:00');
        $id = (int) $r['id'];
        $this->freezeAt($this->inicio($id), -24 * 60 + 1);
        Mailer::reset();
        $this->work();
        $mail = array_values(array_filter(Mailer::sent(), static fn ($m) => $m->to === self::C1 && str_starts_with($m->subject, 'Recordatorio')))[0];
        $this->assertTrue((bool) preg_match('/#t=([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)/', $mail->text, $m));
        $token = $m[1];
        $this->cookies = []; // sin sesión

        $res = $this->call('POST', '/asistencia/consultar', ['token' => $token]);
        $this->assertSame(200, $res->status(), $res->body());
        $d = $this->data($res);
        $this->assertSame(['10:00', true, false], [$d['hora'], $d['puede_confirmar'], $d['asistencia_confirmada']]);
        $this->assertArrayNotHasKey('telefono', $d);

        $res = $this->call('POST', '/asistencia/confirmar', ['token' => $token]);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertTrue($this->data($res)['asistencia_confirmada']);
        $this->assertNotNull($this->scalar('SELECT asistencia_confirmada_en FROM reservas WHERE id = ?', [$id]));
        $this->assertSame(200, $this->call('POST', '/asistencia/confirmar', ['token' => $token])->status(), 'Idempotente');
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM reserva_eventos WHERE reserva_id = ? AND tipo = 'asistencia_confirmada'", [$id]));

        // El recordatorio de 2 h ya no pide confirmar.
        $this->freezeAt($this->inicio($id), -120);
        ArrayPushTransport::reset();
        $this->work();
        $push = array_values(array_filter(ArrayPushTransport::$sent, static fn ($p) => ($p['message']['data']['reserva_id'] ?? '') === (string) $id));
        $this->assertArrayNotHasKey('accion', $push[0]['message']['data']);
        $this->assertStringContainsString('Ya confirmaste tu asistencia', $push[0]['message']['body']);

        // Token alterado o de otra cita: 404 (y cuenta para el límite por IP).
        [$p, $s] = explode('.', $token);
        $this->assertSame(404, $this->call('POST', '/asistencia/consultar', ['token' => $p . '.' . strrev($s)])->status());
        $forged = AttendanceToken::issue($this->tenantId('demo-norte'), $id, $this->inicio($id));
        $this->assertSame(404, $this->call('POST', '/asistencia/confirmar', ['token' => $forged])->status(), 'La reserva no es de esa barbería');
        $this->assertSame(404, $this->call('POST', '/asistencia/confirmar', ['token' => 'basura'])->status());

        // Tras reprogramar, el enlace viejo deja de servir.
        Clock::freeze(null);
        $res = $this->call('POST', "/mis-reservas/{$id}/reprogramar", ['fecha' => $f, 'hora' => '17:00'], $this->client() + ['Idempotency-Key' => 'att-resch']);
        $this->assertSame(200, $res->status(), $res->body());
        $this->cookies = [];
        $res = $this->call('POST', '/asistencia/consultar', ['token' => $token]);
        $this->assertSame(410, $res->status());
        $this->assertSame('booking_changed', $this->errorCode($res));
    }

    public function testInvalidAttendanceTokensAreRateLimitedPerIp(): void
    {
        $this->ip = '203.0.113.77';
        for ($i = 0; $i < 30; $i++) {
            $this->assertSame(404, $this->call('POST', '/asistencia/consultar', ['token' => "x{$i}.y"])->status());
        }
        $this->assertSame(429, $this->call('POST', '/asistencia/consultar', ['token' => 'otra.mas'])->status());
    }

    public function testInboxIsPrivateAndCanBeMarkedRead(): void
    {
        $r = $this->bookAs(self::C1, $this->day(3, 3), '10:00');
        $h1 = $this->client();
        $res = $this->call('GET', '/me/notificaciones/resumen', null, $h1);
        $this->assertSame(200, $res->status());
        $antes = $this->data($res)['no_leidas'];
        $this->assertGreaterThanOrEqual(1, $antes);
        $mine = $this->data($this->call('GET', '/me/notificaciones?solo_no_leidas=1', null, $h1));
        $item = array_values(array_filter($mine, static fn ($n) => $n['reserva_id'] === (int) $r['id']))[0];
        $this->assertSame('demo-centro', $item['barberia']['slug']);

        // Otra persona no puede leerlo ni marcarlo.
        $h2 = $this->client(self::C2);
        $this->assertSame(404, $this->call('POST', "/me/notificaciones/{$item['id']}/leer", [], $h2)->status());
        $ids2 = array_column($this->data($this->call('GET', '/me/notificaciones', null, $h2)), 'id');
        $this->assertNotContains($item['id'], $ids2);
        // El barbero ve el aviso de su propia bandeja, pero sin enlace a la reserva del cliente.
        $hb = $this->actingAs(self::BARBERO1);
        $barb = $this->data($this->call('GET', '/me/notificaciones', null, $hb));
        $nueva = array_values(array_filter($barb, static fn ($n) => $n['tipo'] === 'reserva_creada'))[0];
        $this->assertNull($nueva['reserva_id']);
        $this->assertStringStartsWith('pages/barbero/index.html?cita=', (string) $nueva['url']);

        $h1 = $this->client();
        $res = $this->call('POST', "/me/notificaciones/{$item['id']}/leer", [], $h1);
        $this->assertSame($antes - 1, $this->data($res)['no_leidas']);
        $res = $this->call('POST', '/me/notificaciones/leer-todas', [], $h1);
        $this->assertSame(0, $this->data($res)['no_leidas']);
        $this->cookies = [];
        $this->assertSame(401, $this->call('GET', '/me/notificaciones')->status());
    }

    public function testDevicesRegisterMoveBetweenAccountsAndUnregister(): void
    {
        $token = 'fcm-token-' . str_repeat('a', 40);
        $this->registerDevice(self::C2, $token);
        $c2 = $this->userId(self::C2);
        $this->assertSame($c2, (int) $this->scalar('SELECT usuario_id FROM dispositivos_push WHERE token_hash = ?', [hash('sha256', $token)]));

        // El mismo teléfono inicia sesión con otra cuenta: el token pasa a esa cuenta.
        $this->registerDevice(self::C1, $token, 'ios');
        $this->assertSame($this->userId(self::C1), (int) $this->scalar('SELECT usuario_id FROM dispositivos_push WHERE token_hash = ?', [hash('sha256', $token)]));
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM dispositivos_push WHERE token_hash = ?', [hash('sha256', $token)]));

        $h = $this->client();
        $list = $this->data($this->call('GET', '/me/dispositivos', null, $h));
        $this->assertArrayNotHasKey('token', $list[0], 'El token nunca vuelve en la API');
        $this->assertSame(422, $this->call('POST', '/me/dispositivos', ['token' => 'corto', 'plataforma' => 'android'], $h)->status());
        $this->assertSame(422, $this->call('POST', '/me/dispositivos', ['token' => str_repeat('b', 30), 'plataforma' => 'symbian'], $h)->status());

        $this->assertSame(204, $this->call('DELETE', '/me/dispositivos', ['token' => $token], $h)->status());
        $this->assertSame(0, (int) $this->scalar('SELECT activo FROM dispositivos_push WHERE token_hash = ?', [hash('sha256', $token)]));
        $this->assertSame(204, $this->call('DELETE', '/me/dispositivos', ['token' => 'no-existe-' . str_repeat('c', 30)], $h)->status());

        // Tope de 10 dispositivos activos por cuenta.
        for ($i = 0; $i < 12; $i++) {
            $this->assertSame(201, $this->call('POST', '/me/dispositivos', ['token' => "tope-{$i}-" . str_repeat('d', 30), 'plataforma' => 'android'], $h)->status());
        }
        $this->assertSame(10, (int) $this->scalar('SELECT COUNT(*) FROM dispositivos_push WHERE usuario_id = ? AND activo = 1', [$this->userId(self::C1)]));
    }

    public function testStaffGetsPushForTheirAgendaAndCanOptOut(): void
    {
        $this->registerDevice(self::BARBERO1, 'barbero-token-' . str_repeat('e', 30));
        $r = $this->bookAs(self::C2, $this->day(3, 3), '11:00');
        $push = $this->db->query("SELECT * FROM notificaciones WHERE reserva_id = {$r['id']} AND canal = 'push' AND usuario_id = " . $this->userId(self::BARBERO1))->fetchAll();
        $this->assertCount(1, $push);
        $this->assertSame('Nueva cita', $push[0]['titulo']);
        $this->work();
        $sent = array_values(array_filter(ArrayPushTransport::$sent, static fn ($p) => $p['token'] === 'barbero-token-' . str_repeat('e', 30)));
        $this->assertCount(1, $sent);
        $this->assertSame('Nueva cita', $sent[0]['message']['title']);

        // El barbero ve la categoría "agenda" (sin correo) y la desactiva.
        $hb = $this->actingAs(self::BARBERO1);
        $cats = array_column($this->data($this->call('GET', '/me/preferencias-notificacion', null, $hb))['categorias'], null, 'categoria');
        $this->assertArrayHasKey('agenda', $cats);
        $this->assertArrayNotHasKey('email', $cats['agenda']);
        $this->assertSame(422, $this->call('PUT', '/me/preferencias-notificacion', ['agenda' => ['email' => true]], $hb)->status());
        $this->assertSame(200, $this->call('PUT', '/me/preferencias-notificacion', ['agenda' => ['push' => false]], $hb)->status());
        $r2 = $this->bookAs(self::C2, $this->day(3, 3), '15:00');
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM notificaciones WHERE reserva_id = ? AND canal = 'push' AND usuario_id = ?", [$r2['id'], $this->userId(self::BARBERO1)]));
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notificaciones WHERE reserva_id = ? AND canal = 'interna' AND usuario_id = ?", [$r2['id'], $this->userId(self::BARBERO1)]), 'La bandeja sigue recibiendo');
    }

    public function testMetricsComeFromRealDataAndAreIsolatedPerTenant(): void
    {
        $f = $this->day(3, 3);
        $a = $this->bookAs(self::C1, $f, '10:00');
        $b = $this->bookAs(self::C2, $f, '12:00');
        $this->work();
        $this->freezeAt($this->inicio((int) $a['id']), -24 * 60 + 1);
        $this->work();
        $this->freezeAt($this->inicio((int) $b['id']), -24 * 60 + 1);
        $this->work();
        // Cliente Uno confirma; ambas citas pasan: una atendida y otra con inasistencia.
        $this->assertSame(200, $this->call('POST', "/mis-reservas/{$a['id']}/confirmar-asistencia", [], $this->client())->status());
        $this->freezeAt($this->inicio((int) $b['id']), 90);
        $h = $this->actingAs(self::ADMIN);
        $this->assertSame(200, $this->call('POST', "/panel/reservas/{$a['id']}/completar", [], $h)->status());
        $this->assertSame(200, $this->call('POST', "/panel/reservas/{$b['id']}/no-asistio", [], $h)->status());

        $res = $this->call('GET', "/panel/notificaciones/metricas?desde={$f}&hasta={$f}", null, $h);
        $this->assertSame(200, $res->status(), $res->body());
        $m = $this->data($res);
        // Por fecha programada: los recordatorios de 24 h caen el día anterior; los de 2 h, ese día (anulados al atender).
        $this->assertSame(2, $m['citas']['total']);
        $this->assertSame(2, $m['citas']['con_recordatorio']);
        $this->assertSame(1, $m['citas']['confirmadas_tras_recordatorio']);
        $this->assertSame(50.0, $m['citas']['tasa_confirmacion']);
        $this->assertSame(['atendidas' => 1, 'no_asistio' => 1], array_intersect_key($m['inasistencia'], ['atendidas' => 0, 'no_asistio' => 0]));
        $this->assertSame(50.0, $m['inasistencia']['tasa']);
        $this->assertSame(0.0, $m['inasistencia']['tasa_confirmaron']);
        $this->assertSame(100.0, $m['inasistencia']['tasa_no_confirmaron']);

        $ayer = (new \DateTimeImmutable($f))->modify('-1 day')->format('Y-m-d');
        $m2 = $this->data($this->call('GET', "/panel/notificaciones/metricas?desde={$ayer}&hasta={$ayer}", null, $h));
        $this->assertGreaterThanOrEqual(4, $m2['recordatorios']['enviados'], '24 h: bandeja + correo de dos citas (+ push de Cliente Uno)');
        $this->assertSame(0, $m2['recordatorios']['fallidos']);
        $this->assertArrayHasKey('email', $m2['recordatorios']['por_canal']);
        $this->assertSame(100.0, $m2['recordatorios']['tasa_entrega']);

        // Otra barbería no ve nada de esto.
        $hn = $this->actingAs('propietario.norte@barberapp.test', 'demo-norte');
        $mn = $this->data($this->call('GET', "/panel/notificaciones/metricas?desde={$ayer}&hasta={$f}", null, $hn));
        $this->assertSame(0, $mn['recordatorios']['programados']);
        $this->assertSame(0, $mn['citas']['total']);

        $h = $this->actingAs(self::ADMIN);
        $this->assertSame(422, $this->call('GET', "/panel/notificaciones/metricas?desde={$f}&hasta={$ayer}", null, $h)->status());
    }
}
