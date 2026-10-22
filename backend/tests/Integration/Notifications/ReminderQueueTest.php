<?php

declare(strict_types=1);

namespace Tests\Integration\Notifications;

use App\Helpers\Clock;
use App\Services\Mail\Mailer;
use App\Services\Notifications\Push\ArrayPushTransport;
use App\Services\Notifications\Push\PushResult;

/**
 * Cola persistente, recordatorios y worker (Fase 8): programación al reservar, envío por
 * canal, anulación al cancelar o reprogramar, reintentos con backoff, tokens inválidos,
 * deduplicación y recuperación de un worker caído.
 */
final class ReminderQueueTest extends NotificationsTestCase
{
    private const C1 = 'cliente1@barberapp.test';

    public function testBookingSchedulesConfirmationAndRemindersAtConfiguredHours(): void
    {
        $r = $this->bookAs(self::C1, $this->day(3, 3), '10:00');
        $inicio = $this->inicio((int) $r['id']);

        // Comprobante inmediato: bandeja + push (Cliente Uno tiene la app demo) + correo verificado.
        $uid = $this->userId(self::C1);
        $conf = array_column(array_filter($this->rows((int) $r['id']), static fn ($n) => in_array($n['tipo'], ['reserva_creada', 'reserva_confirmada'], true) && (int) $n['usuario_id'] === $uid), 'canal');
        sort($conf);
        $this->assertSame(['email', 'interna', 'push'], $conf);

        // Recordatorios 24 h y 2 h por los tres canales, a la hora exacta.
        foreach ([24, 2] as $h) {
            foreach (['interna', 'push', 'email'] as $canal) {
                $n = $this->reminder((int) $r['id'], $h, $canal);
                $this->assertSame('programada', $n['estado']);
                $this->assertSame(gmdate('Y-m-d H:i:s', strtotime($inicio . ' UTC') - $h * 3600), $n['programada_para_utc']);
                $this->assertSame("rec:{$r['id']}:0:{$h}h:{$canal}", $n['dedupe_key']);
            }
        }
        // El planificador no duplica lo ya programado.
        $total = count($this->rows((int) $r['id']));
        $this->work();
        $this->work();
        $this->assertSame($total, count($this->rows((int) $r['id'])), 'Repetir el worker no duplica avisos');
    }

    public function testWorkerSendsRemindersWhenDueEvenWithTheAppClosed(): void
    {
        $r = $this->bookAs(self::C1, $this->day(3, 3), '10:00');
        $id = (int) $r['id'];
        $this->work(); // comprobante de la reserva
        ArrayPushTransport::reset();
        Mailer::reset();

        $this->freezeAt($this->inicio($id), -24 * 60 + 1);
        $stats = $this->work();
        $this->assertGreaterThanOrEqual(3, $stats['enviada']);
        foreach (['interna', 'push', 'email'] as $canal) {
            $n = $this->reminder($id, 24, $canal);
            $this->assertSame('enviada', $n['estado'], "{$canal}: " . $n['ultimo_error']);
            $this->assertStringEndsWith('mañana a las 10:00', $n['titulo'], 'El texto se arma al enviar, con la fecha relativa vigente');
        }
        $this->assertSame('programada', $this->reminder($id, 2, 'push')['estado'], 'El de 2 h espera su hora');

        // Push con acción "Confirmar asistencia" y token firmado para la app.
        $push = array_values(array_filter(ArrayPushTransport::$sent, static fn ($p) => ($p['message']['data']['reserva_id'] ?? '') === (string) $id));
        $this->assertCount(1, $push);
        $this->assertSame('confirmar_asistencia', $push[0]['message']['data']['accion']);
        $this->assertNotEmpty($push[0]['message']['data']['token']);
        $this->assertSame('demo-token-no-valido', $push[0]['token']);

        // Correo con botón de confirmar (enlace con el token en el fragmento #).
        $mails = array_values(array_filter(Mailer::sent(), static fn ($m) => $m->to === self::C1));
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('Recordatorio: tu cita en', $mails[0]->subject);
        $this->assertMatchesRegularExpression('#pages/asistencia\.html\#t=[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+#', $mails[0]->text);
        $this->assertStringContainsString('Confirmar asistencia', (string) $mails[0]->html);

        // Bandeja: el recordatorio aparece con el botón de confirmar.
        $this->freezeAt($this->inicio($id), -24 * 60 + 2);
        $h = $this->client();
        $res = $this->call('GET', '/me/notificaciones', null, $h);
        $this->assertSame(200, $res->status(), $res->body());
        $item = array_values(array_filter($this->data($res), static fn ($n) => $n['tipo'] === 'recordatorio' && $n['reserva_id'] === $id))[0];
        $this->assertSame('confirmar_asistencia', $item['accion']);
        $this->assertFalse($item['leida']);

        // Cada intento queda registrado.
        $this->assertSame(3, (int) $this->scalar("SELECT COUNT(*) FROM notificacion_envios e JOIN notificaciones n ON n.id = e.notificacion_id WHERE n.reserva_id = ? AND n.tipo = 'recordatorio' AND e.resultado = 'enviada'", [$id]));
    }

    public function testReminderLeftAfterTheAppointmentStartIsNeverSentAndNotConfirmingDoesNotCancel(): void
    {
        $r = $this->bookAs(self::C1, $this->day(3, 3), '10:00');
        $id = (int) $r['id'];
        // El worker estuvo caído hasta después de la cita.
        $this->freezeAt($this->inicio($id), 5);
        $this->work();
        $this->assertSame('cancelada', $this->reminder($id, 24, 'push')['estado']);
        $this->assertStringContainsString('ya empezó', (string) $this->reminder($id, 2, 'email')['ultimo_error']);
        $this->assertSame('confirmada', $this->scalar('SELECT estado FROM reservas WHERE id = ?', [$id]), 'No confirmar nunca cancela la cita');
    }

    public function testCancellingOrReschedulingInvalidatesPendingRemindersAndNotifiesTheClient(): void
    {
        $f = $this->day(3, 3);
        $r = $this->bookAs(self::C1, $f, '10:00');
        $id = (int) $r['id'];

        // La barbería reprograma: los recordatorios viejos se anulan y se programan los nuevos.
        $h = $this->actingAs(self::ADMIN);
        $res = $this->call('POST', "/panel/reservas/{$id}/reprogramar", ['fecha' => $f, 'hora' => '16:00'], $h + ['Idempotency-Key' => 'n-resch-1']);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM notificaciones WHERE reserva_id = ? AND tipo = 'recordatorio' AND dedupe_key LIKE 'rec:%:0:%' AND estado <> 'cancelada'", [$id]));
        $nuevo = $this->reminder($id, 2, 'push');
        $this->assertSame("rec:{$id}:1:2h:push", $nuevo['dedupe_key']);
        $this->assertSame(gmdate('Y-m-d H:i:s', strtotime($this->inicio($id) . ' UTC') - 7200), $nuevo['programada_para_utc']);
        // "Cuando el administrador modifica una cita": aviso al cliente por push y correo.
        $mod = array_values(array_filter($this->rows($id, 'reserva_modificada'), static fn ($n) => $n['canal'] !== 'interna'));
        $this->assertEqualsCanonicalizing(['push', 'email'], array_column($mod, 'canal'));
        $this->assertStringContainsString('16:00', $mod[0]['cuerpo']);

        // La barbería cancela con motivo: se anulan los recordatorios y el cliente recibe el aviso.
        $res = $this->call('POST', "/panel/reservas/{$id}/cancelar", ['motivo' => 'El barbero está enfermo'], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM notificaciones WHERE reserva_id = ? AND tipo = 'recordatorio' AND estado = 'programada'", [$id]));
        $cancel = array_values(array_filter($this->rows($id, 'reserva_cancelada'), static fn ($n) => $n['canal'] !== 'interna'));
        $this->assertEqualsCanonicalizing(['push', 'email'], array_column($cancel, 'canal'));
        $this->assertStringContainsString('Motivo: El barbero está enfermo', $cancel[0]['cuerpo']);

        // El worker envía el aviso de cancelación y no ningún recordatorio.
        Mailer::reset();
        $this->work();
        $this->assertSame(['enviada', 'enviada'], array_column($cancel = array_values(array_filter($this->rows($id, 'reserva_cancelada'), static fn ($n) => $n['canal'] !== 'interna')), 'estado'));
        $this->assertSame(1, $this->mailsTo(self::C1));
        $this->freezeAt($this->inicio($id), -120);
        $this->work();
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM notificaciones WHERE reserva_id = ? AND tipo = 'recordatorio' AND estado = 'enviada'", [$id]));
    }

    public function testClientsOwnCancellationDoesNotPushTheClient(): void
    {
        $r = $this->bookAs(self::C1, $this->day(3, 3), '11:00');
        $id = (int) $r['id'];
        $res = $this->call('POST', "/mis-reservas/{$id}/cancelar", [], $this->client());
        $this->assertSame(200, $res->status(), $res->body());
        $propios = array_filter($this->rows($id, 'reserva_cancelada'), fn ($n) => (int) $n['usuario_id'] === $this->userId(self::C1));
        $this->assertSame([], array_values($propios), 'Quien cancela no recibe aviso de su propia acción');
        $this->assertNotEmpty($this->rows($id, 'reserva_cancelada'), 'El barbero sí recibe el aviso en su bandeja');
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM notificaciones WHERE reserva_id = ? AND tipo = 'recordatorio' AND estado = 'programada'", [$id]));
    }

    public function testTransientErrorsRetryWithBackoffAndThenFail(): void
    {
        $r = $this->bookAs(self::C1, $this->day(3, 3), '10:00');
        $id = (int) $r['id'];
        ArrayPushTransport::$responses['demo-token-no-valido'] = new PushResult(PushResult::RETRY, 'UNAVAILABLE', 'Servicio no disponible');
        $start = strtotime($this->inicio($id) . ' UTC') - 24 * 3600 + 60;
        Clock::freeze((new \DateTimeImmutable('@' . $start)));
        $this->work();
        $n = $this->reminder($id, 24, 'push');
        $this->assertSame(['programada', 1], [$n['estado'], (int) $n['intentos']]);
        $wait = strtotime($n['proximo_intento_utc'] . ' UTC') - $start;
        $this->assertGreaterThanOrEqual(60, $wait);
        $this->assertLessThanOrEqual(66, $wait, '1 min + 10 % de variación');
        $this->assertSame('Servicio no disponible', $n['ultimo_error']);

        // Antes de su turno no se reintenta.
        $this->work();
        $this->assertSame(1, (int) $this->reminder($id, 24, 'push')['intentos']);

        // Los siguientes intentos esperan cada vez más hasta agotar max_intentos (5).
        $waits = [];
        for ($i = 2; $i <= 5; $i++) {
            $n = $this->reminder($id, 24, 'push');
            Clock::freeze(new \DateTimeImmutable('@' . strtotime($n['proximo_intento_utc'] . ' UTC')));
            $this->work();
            $after = $this->reminder($id, 24, 'push');
            if ($after['estado'] === 'programada') {
                $waits[] = strtotime($after['proximo_intento_utc'] . ' UTC') - Clock::nowUtc()->getTimestamp();
            }
        }
        $this->assertSame('fallida', $after['estado']);
        $this->assertSame(5, (int) $after['intentos']);
        $this->assertCount(3, $waits);
        $this->assertTrue($waits[0] >= 300 && $waits[1] >= 900 && $waits[2] >= 3600, 'Backoff 5 min, 15 min, 1 h');
        $log = $this->db->query("SELECT resultado FROM notificacion_envios WHERE notificacion_id = {$after['id']} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['error_temporal', 'error_temporal', 'error_temporal', 'error_temporal', 'error_permanente'], $log);
        // La cita sigue intacta y el correo del mismo recordatorio sí salió.
        $this->assertSame('enviada', $this->reminder($id, 24, 'email')['estado']);
    }

    public function testRejectedTokenDeactivatesTheDeviceAndSkipsThePush(): void
    {
        $r = $this->bookAs(self::C1, $this->day(3, 3), '10:00');
        $id = (int) $r['id'];
        ArrayPushTransport::$responses['demo-token-no-valido'] = new PushResult(PushResult::INVALID_TOKEN, 'UNREGISTERED', 'Requested entity was not found.');
        $this->work();
        $n = array_values(array_filter($this->rows($id), static fn ($x) => $x['canal'] === 'push' && $x['tipo'] !== 'recordatorio'))[0];
        $this->assertSame('omitida', $n['estado']);
        $dev = $this->db->query("SELECT activo, motivo_desactivado FROM dispositivos_push WHERE token = 'demo-token-no-valido'")->fetch();
        $this->assertSame(0, (int) $dev['activo']);
        $this->assertStringContainsString('UNREGISTERED', (string) $dev['motivo_desactivado']);

        // Sin dispositivos activos, el recordatorio push queda omitido (la bandeja y el correo sí salen).
        $this->freezeAt($this->inicio($id), -24 * 60 + 1);
        $this->work();
        $this->assertSame('omitida', $this->reminder($id, 24, 'push')['estado']);
        $this->assertSame('enviada', $this->reminder($id, 24, 'interna')['estado']);
    }

    public function testStaleProcessingRowsFromACrashedWorkerAreRecovered(): void
    {
        $r = $this->bookAs(self::C1, $this->day(3, 3), '10:00');
        $id = (int) $r['id'];
        $n = $this->reminder($id, 24, 'email');
        $due = strtotime($n['programada_para_utc'] . ' UTC');
        $this->exec("UPDATE notificaciones SET estado = 'procesando', bloqueada_por = 'worker-caido', bloqueada_en = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', $due + 60), $n['id']]);
        Clock::freeze(new \DateTimeImmutable('@' . ($due + 5 * 60)));
        $this->work();
        $this->assertSame('procesando', $this->reminder($id, 24, 'email')['estado'], 'Con 4 min todavía puede estar trabajando');
        Clock::freeze(new \DateTimeImmutable('@' . ($due + 12 * 60)));
        $stats = $this->work();
        $this->assertSame(1, $stats['recuperados']);
        $this->assertSame('enviada', $this->reminder($id, 24, 'email')['estado']);
    }

    public function testDedupeKeyPreventsDuplicatesOnRetriedRequests(): void
    {
        $f = $this->day(3, 3);
        $h = $this->client();
        $body = $this->body($f, '12:00');
        $a = $this->book($body, $h, 'misma-clave-red');
        $b = $this->book($body, $h, 'misma-clave-red');
        $this->assertSame($this->data($a)['id'], $this->data($b)['id']);
        $id = (int) $this->data($a)['id'];
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notificaciones WHERE reserva_id = ? AND tipo = 'recordatorio' AND canal = 'push' AND CAST(JSON_EXTRACT(datos, '$.horas') AS UNSIGNED) = 24", [$id]));
        $this->assertSqlFails(1062, "INSERT INTO notificaciones (tenant_id, usuario_id, reserva_id, canal, tipo, cuerpo, programada_para_utc, dedupe_key) SELECT tenant_id, usuario_id, reserva_id, canal, tipo, cuerpo, programada_para_utc, dedupe_key FROM notificaciones WHERE reserva_id = {$id} LIMIT 1");
    }
}
