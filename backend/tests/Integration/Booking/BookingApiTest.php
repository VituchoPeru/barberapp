<?php

declare(strict_types=1);

namespace Tests\Integration\Booking;

use App\Helpers\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Motor de reservas (Fase 5) a través de la API, en proceso.
 * Las pruebas con solicitudes realmente simultáneas están en ConcurrencyTest.
 */
final class BookingApiTest extends BookingTestCase
{
    public function testFortyFiveMinuteServiceOnlyOffersSlotsThatFit(): void
    {
        $f = $this->day(3);
        $hours = $this->hours($f, ['Corte y barba']);
        // Barbero Uno: 09–20 con almuerzo 13–14. 45 min en grilla de 15.
        $this->assertSame('09:00', $hours[0]);
        $this->assertContains('12:15', $hours, '12:15–13:00 cabe antes del almuerzo');
        $this->assertNotContains('12:30', $hours, '12:30 + 45 cruzaría el almuerzo');
        $this->assertSame('19:15', end($hours), 'La última termina a las 20:00');

        $h = $this->client();
        $res = $this->book($this->body($f, '12:15', ['Corte y barba']), $h);
        $this->assertSame(201, $res->status(), $res->body());
        $r = $this->data($res);
        $this->assertSame(['12:15', '13:00', 45], [$r['hora_inicio'], $r['hora_fin'], $r['duracion_total_minutos']]);
        $this->assertSame('confirmada', $r['estado'], 'La política demo confirma automáticamente');
        $this->assertSame(10, strlen($r['codigo']));
        $this->assertSame('40.00', $r['precio_total']);

        $res = $this->book($this->body($f, '12:30', ['Corte y barba']), $this->client(self::CLIENTE2));
        $this->assertSame(409, $res->status());
        $this->assertSame('slot_unavailable', $this->errorCode($res));
    }

    public function testSeveralServicesAreConsecutiveWithTheSameBarber(): void
    {
        $f = $this->day(4);
        $h = $this->client();
        $res = $this->book($this->body($f, '10:00', ['Corte clásico', 'Perfilado de barba']) + ['notas' => 'Degradado bajo'], $h);
        $this->assertSame(201, $res->status(), $res->body());
        $r = $this->data($res);
        $this->assertSame(50, $r['duracion_total_minutos']);
        $this->assertSame('40.00', $r['precio_total']);
        $this->assertSame([['Corte clásico', '10:00', '10:30'], ['Perfilado de barba', '10:30', '10:50']], array_map(static fn ($s) => [$s['nombre'], $s['hora_inicio'], $s['hora_fin']], $r['servicios']));
        $this->assertSame('Degradado bajo', $r['notas_cliente']);
        $rows = $this->db->query("SELECT barbero_id FROM reservas WHERE id = {$r['id']}")->fetchAll();
        $this->assertSame($this->barberId('Barbero Uno Demo'), (int) $rows[0]['barbero_id']);
        $this->assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM reserva_servicios WHERE reserva_id = ?', [$r['id']]));

        // Barbero Dos no hace "Corte y barba": la combinación con él se rechaza.
        $res = $this->book($this->body($f, '15:00', ['Corte y barba', 'Perfilado de barba'], 'Barbero Dos Demo'), $h);
        $this->assertSame(422, $res->status());
        $this->assertArrayHasKey('barbero_id', $res->decoded()['error']['details']['fields']);
    }

    public function testCancelReleasesTheSlotAndAnotherClientBooksIt(): void
    {
        $f = $this->day(5);
        $h1 = $this->client();
        $r = $this->data($this->book($this->body($f, '16:00'), $h1));
        $this->assertNotContains('16:00', $this->hours($f));

        $h2 = $this->client(self::CLIENTE2);
        $this->assertSame(409, $this->book($this->body($f, '16:00'), $h2)->status());

        $h1 = $this->client();
        $res = $this->call('POST', "/mis-reservas/{$r['id']}/cancelar", ['motivo' => 'Me surgió un viaje'], $h1);
        $this->assertSame(200, $res->status(), $res->body());
        $c = $this->data($res);
        $this->assertSame('cancelada', $c['estado']);
        $this->assertSame(['cliente', 'Me surgió un viaje'], [$c['cancelacion']['por'], $c['cancelacion']['motivo']]);
        $this->assertFalse($c['acciones']['activa']);
        $this->assertSame(200, $this->call('POST', "/mis-reservas/{$r['id']}/cancelar", [], $h1)->status(), 'Cancelar de nuevo es idempotente');
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM reserva_eventos WHERE reserva_id = ? AND tipo = 'cancelada'", [$r['id']]));
        $this->assertSame(1, $this->auditCount('reserva.cancelada', 'exito'));

        $this->assertContains('16:00', $this->hours($f), 'El intervalo vuelve a ofrecerse');
        $h2 = $this->client(self::CLIENTE2);
        $res = $this->book($this->body($f, '16:00'), $h2);
        $this->assertSame(201, $res->status(), $res->body());
    }

    public function testPartiallyOverlappingBookingIsRejectedWithAlternatives(): void
    {
        $f = $this->day(2, 3);
        $this->assertSame(201, $this->book($this->body($f, '10:00', ['Corte y barba']), $this->client())->status());
        $res = $this->book($this->body($f, '10:30'), $this->client(self::CLIENTE2));
        $this->assertSame(409, $res->status());
        $alts = $res->decoded()['error']['details']['alternativas'];
        $this->assertContains('10:45', $alts, 'Sugiere el primer horario libre tras la cita');
        foreach ($alts as $a) {
            $this->assertTrue($a < '09:31' || $a >= '10:45', "{$a} se cruzaría");
        }
        // Un horario fuera de la grilla tampoco se acepta aunque esté libre.
        $this->assertSame(409, $this->book($this->body($f, '11:07'), $this->client(self::CLIENTE2))->status());
    }

    public function testFullDayBlockRemovesAvailability(): void
    {
        $f = $this->day(1, 3);
        $this->assertNotEmpty($this->hours($f));
        $res = $this->call('POST', '/panel/bloqueos', [
            'tipo' => 'ausencia', 'barbero_id' => $this->barberId('Barbero Uno Demo'), 'dia_completo' => true, 'fecha_inicio' => $f, 'motivo' => 'Cita médica',
        ], $this->owner());
        $this->assertSame(201, $res->status(), $res->body());
        $this->assertSame([], $this->hours($f));
        $res = $this->book($this->body($f, '10:00'), $this->client());
        $this->assertSame(409, $res->status());
        $this->assertSame([], $res->decoded()['error']['details']['alternativas']);

        // Con "cualquiera", Barbero Dos sigue disponible ese día.
        $any = $this->hours($f, ['Corte clásico'], null);
        $this->assertNotEmpty($any);
        $res = $this->book($this->body($f, '10:00', ['Corte clásico'], null), $this->client());
        $this->assertSame(201, $res->status(), $res->body());
        $this->assertSame('Barbero Dos Demo', $this->data($res)['barbero']['nombre']);
        $this->assertTrue($this->data($res)['barbero_asignado_auto']);
    }

    public function testBarberScheduleChangeIsReflectedImmediately(): void
    {
        $f = $this->day(2, 4);
        $this->assertContains('09:00', $this->hours($f));
        $uno = $this->barberId('Barbero Uno Demo');
        $sede = $this->branchId('sede-cercado');
        $tramos = [];
        for ($d = 1; $d <= 6; $d++) {
            $tramos[] = ['dia_semana' => $d, 'hora_inicio' => '15:00', 'hora_fin' => '20:00', 'sucursal_id' => $sede];
        }
        $res = $this->call('PUT', "/panel/barberos/{$uno}/horario", ['tramos' => $tramos, 'descansos' => []], $this->owner());
        $this->assertSame(200, $res->status(), $res->body());
        $hours = $this->hours($f);
        $this->assertSame('15:00', $hours[0], 'La mañana ya no se ofrece');
        $res = $this->book($this->body($f, '09:00'), $this->client());
        $this->assertSame(409, $res->status());
    }

    public function testRetryWithSameIdempotencyKeyDoesNotDuplicate(): void
    {
        $f = $this->day(3, 4);
        $h = $this->client();
        $body = $this->body($f, '17:00');
        $first = $this->book($body, $h, 'retry-key-0001');
        $this->assertSame(201, $first->status(), $first->body());
        $again = $this->book($body, $h, 'retry-key-0001');
        $this->assertSame(201, $again->status(), $again->body());
        $this->assertSame('true', $again->header('Idempotent-Replayed'));
        $this->assertSame($this->data($first)['codigo'], $this->data($again)['codigo']);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM reservas WHERE inicio_utc = ? AND estado <> 'cancelada'", [
            (new DateTimeImmutable("{$f} 17:00", new DateTimeZone('America/Lima')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]));

        $other = $this->book($this->body($f, '18:00'), $h, 'retry-key-0001');
        $this->assertSame(422, $other->status());
        $this->assertSame('idempotency_key_reused', $this->errorCode($other));

        $res = $this->call('POST', '/reservas', $body + ['slug' => 'demo-centro'], $h);
        $this->assertSame(428, $res->status());
        $this->assertSame('idempotency_key_required', $this->errorCode($res));
    }

    public function testCannotBookOrReadAcrossTenants(): void
    {
        $f = $this->day(3, 5);
        $h = $this->client();
        $norte = $this->tenantId('demo-norte');
        $sedeNorte = (int) $this->scalar('SELECT id FROM sucursales WHERE tenant_id = ?', [$norte]);
        $barberoNorte = $this->barberId('Barbero Tres Demo');
        $servNorte = $this->serviceId('Corte moderno');

        // Sucursal, barbero o servicio de otra barbería con el slug de demo-centro.
        $res = $this->book(['sucursal_id' => $sedeNorte] + $this->body($f, '10:00'), $h);
        $this->assertSame(404, $res->status(), $res->body());
        $res = $this->book(['barbero_id' => $barberoNorte] + $this->body($f, '10:00'), $h);
        $this->assertSame(422, $res->status());
        $res = $this->book(['servicio_ids' => [$servNorte]] + $this->body($f, '10:00'), $h);
        $this->assertSame(422, $res->status());
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM reservas WHERE tenant_id = ? AND barbero_id = ? AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 MINUTE', [$this->tenantId('demo-centro'), $barberoNorte]));

        // Barbería suspendida: no recibe reservas.
        $res = $this->call('POST', '/reservas', ['slug' => 'demo-suspendida'] + $this->body($f, '10:00'), $h + ['Idempotency-Key' => 'susp-key-001']);
        $this->assertSame(404, $res->status());

        // Reserva de cliente1: cliente2 no la ve ni la cancela, y el intento queda auditado.
        $r = $this->data($this->book($this->body($f, '11:00'), $h));
        $h2 = $this->client(self::CLIENTE2);
        $this->assertSame(404, $this->call('GET', "/mis-reservas/{$r['id']}", null, $h2)->status());
        $this->assertSame(404, $this->call('POST', "/mis-reservas/{$r['id']}/cancelar", [], $h2)->status());
        $this->assertSame(2, $this->auditCount('reserva.acceso_ajeno', 'denegado'));
        $mine = $this->data($this->call('GET', '/mis-reservas', null, $h2));
        $this->assertNotContains($r['id'], array_column($mine, 'id'));
        $this->assertSame('activa', $this->data($this->call('GET', "/mis-reservas/{$r['id']}", null, $this->client()))['acciones']['activa'] ? 'activa' : 'no');
    }

    public function testAnyBarberPicksAFreeBarberAndBalancesTheDay(): void
    {
        $f = $this->day(4, 5);
        $h = $this->client();
        $a = $this->data($this->book($this->body($f, '15:00', ['Corte clásico'], null), $h));
        $b = $this->data($this->book($this->body($f, '15:00', ['Corte clásico'], null), $this->client(self::CLIENTE2)));
        $this->assertNotSame($a['barbero']['id'], $b['barbero']['id'], 'El segundo va al otro barbero libre');
        $this->assertNotContains('15:00', $this->hours($f, ['Corte clásico'], null), 'Sin barberos libres, la hora desaparece');
        $res = $this->call('GET', '/disponibilidad?' . http_build_query([
            'slug' => 'demo-centro', 'sucursal_id' => $this->branchId('sede-cercado'), 'servicio_ids' => (string) $this->serviceId('Corte clásico'), 'desde' => $f, 'hasta' => $f,
        ]));
        $dia = $this->data($res)['dias'][0];
        $this->assertSame($f, $dia['fecha']);
        $this->assertTrue($dia['reservable']);
        $this->assertSame('09:00', $dia['primera_hora']);
        $this->assertGreaterThan(30, $dia['disponibles']);
    }

    public function testPolicyRules(): void
    {
        $h = $this->client();
        // Anticipación mínima (60 min).
        $soon = $this->localNow()->modify('+30 minutes');
        $res = $this->book($this->body($soon->format('Y-m-d'), $soon->format('H:i')), $h);
        $this->assertSame(422, $res->status(), $res->body());
        $this->assertSame('too_soon', $this->errorCode($res));
        // Horizonte (30 días).
        $res = $this->book($this->body($this->localNow()->modify('+31 days')->format('Y-m-d'), '10:00'), $h);
        $this->assertSame('too_far', $this->errorCode($res));
        // Máximo de citas activas por cliente (3; cliente1 ya tiene 1 en demo-centro).
        $f = $this->day(5, 6);
        $this->assertSame(201, $this->book($this->body($f, '09:00'), $h)->status());
        $this->assertSame(201, $this->book($this->body($f, '10:00'), $h)->status());
        $res = $this->book($this->body($f, '11:00'), $h);
        $this->assertSame(422, $res->status());
        $this->assertSame('active_bookings_limit', $this->errorCode($res));
        // El mismo cliente no puede tener dos citas a la misma hora (aunque sea con otro barbero).
        $this->exec("UPDATE politicas_reserva SET max_reservas_activas_cliente = 10 WHERE tenant_id = ?", [$this->tenantId('demo-centro')]);
        $res = $this->book($this->body($f, '09:00', ['Corte clásico'], 'Barbero Dos Demo'), $h);
        $this->assertSame(409, $res->status());
        $this->assertSame('client_overlap', $this->errorCode($res));
    }

    public function testCancellationAndRescheduleWindowsAndLimits(): void
    {
        $f = $this->day(1, 6);
        $h = $this->client();
        $r = $this->data($this->book($this->body($f, '18:00'), $h));
        $id = $r['id'];
        // Dos reprogramaciones permitidas; la tercera no.
        $res = $this->call('POST', "/mis-reservas/{$id}/reprogramar", ['fecha' => $f, 'hora' => '18:30'], $h + ['Idempotency-Key' => 'resch-0001']);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame(['18:30', 1, 1], [$this->data($res)['hora_inicio'], $this->data($res)['reprogramaciones'], $this->data($res)['acciones']['reprogramaciones_restantes']]);
        $this->assertSame(200, $this->call('POST', "/mis-reservas/{$id}/reprogramar", ['fecha' => $f, 'hora' => '19:00'], $h + ['Idempotency-Key' => 'resch-0002'])->status());
        $res = $this->call('POST', "/mis-reservas/{$id}/reprogramar", ['fecha' => $f, 'hora' => '17:00'], $h + ['Idempotency-Key' => 'resch-0003']);
        $this->assertSame('reschedule_limit', $this->errorCode($res));

        // A 1 hora de la cita ya no se puede cancelar en línea (límite 2 h).
        Clock::freeze((new DateTimeImmutable("{$f} 18:00", new DateTimeZone('America/Lima')))->setTimezone(new DateTimeZone('UTC')));
        $h = $this->client();
        $res = $this->call('POST', "/mis-reservas/{$id}/cancelar", [], $h);
        $this->assertSame(422, $res->status());
        $this->assertSame('cancellation_window_closed', $this->errorCode($res));
        $this->assertStringContainsString('2 horas', $res->decoded()['error']['message']);
        $d = $this->data($this->call('GET', "/mis-reservas/{$id}", null, $h));
        $this->assertFalse($d['acciones']['puede_cancelar']);
        $this->assertSame(['creada', 'confirmada', 'reprogramada', 'reprogramada'], array_column($d['historial'], 'tipo'));
    }

    public function testRescheduleKeepsTheOldSlotUntilTheNewOneIsConfirmed(): void
    {
        $f = $this->day(2, 6);
        $h1 = $this->client();
        $mine = $this->data($this->book($this->body($f, '09:00'), $h1));
        $this->data($this->book($this->body($f, '11:00'), $this->client(self::CLIENTE2)));

        $h1 = $this->client();
        // Al horario ocupado: falla y la cita original sigue intacta.
        $res = $this->call('POST', "/mis-reservas/{$mine['id']}/reprogramar", ['fecha' => $f, 'hora' => '11:00'], $h1 + ['Idempotency-Key' => 'resch-busy-1']);
        $this->assertSame(409, $res->status());
        $d = $this->data($this->call('GET', "/mis-reservas/{$mine['id']}", null, $h1));
        $this->assertSame(['09:00', 0], [$d['hora_inicio'], $d['reprogramaciones']]);
        $this->assertNotContains('09:00', $this->hours($f));

        // La disponibilidad para reprogramar no cuenta la propia cita: puede moverse 15 min.
        $av = $this->data($this->call('GET', "/mis-reservas/{$mine['id']}/disponibilidad?fecha={$f}", null, $h1));
        $this->assertContains('09:15', array_column($av['horarios'], 'hora'));
        $this->assertNotContains('11:00', array_column($av['horarios'], 'hora'));
        $this->assertSame('09:00', $av['reserva']['hora']);
        $res = $this->call('POST', "/mis-reservas/{$mine['id']}/reprogramar", ['fecha' => $f, 'hora' => '09:15'], $h1 + ['Idempotency-Key' => 'resch-ok-1']);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertNotContains('09:00', $this->hours($f), '09:00–09:30 se cruza con la nueva hora 09:15');
        $res = $this->call('POST', "/mis-reservas/{$mine['id']}/reprogramar", ['fecha' => $f, 'hora' => '09:45'], $h1 + ['Idempotency-Key' => 'resch-ok-2']);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertContains('09:00', $this->hours($f), 'El horario anterior se libera tras confirmar');
        $ev = $this->db->query("SELECT inicio_anterior_utc, inicio_nuevo_utc, actor_tipo FROM reserva_eventos WHERE reserva_id = {$mine['id']} AND tipo = 'reprogramada'")->fetch();
        $this->assertSame('cliente', $ev['actor_tipo']);
        $this->assertNotSame($ev['inicio_anterior_utc'], $ev['inicio_nuevo_utc']);
    }

    public function testConfirmAttendanceNotificationsAndReminders(): void
    {
        $f = $this->day(3, 6);
        $h = $this->client();
        $r = $this->data($this->book($this->body($f, '14:00'), $h));
        $barberoUser = $this->userId('barbero1.centro@barberapp.test');
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notificaciones WHERE reserva_id = ? AND usuario_id = ? AND tipo = 'reserva_creada'", [$r['id'], $barberoUser]));
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notificaciones WHERE reserva_id = ? AND usuario_id = ? AND tipo = 'reserva_creada'", [$r['id'], $this->userId(self::CLIENTE1)]));

        $res = $this->call('POST', "/mis-reservas/{$r['id']}/confirmar-asistencia", [], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertTrue($this->data($res)['asistencia_confirmada']);
        $this->assertFalse($this->data($res)['acciones']['puede_confirmar_asistencia']);
        $this->assertSame(200, $this->call('POST', "/mis-reservas/{$r['id']}/confirmar-asistencia", [], $h)->status());
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM reserva_eventos WHERE reserva_id = ? AND tipo = 'asistencia_confirmada'", [$r['id']]));

        // Un recordatorio programado (Fase 8) se anula al cancelar.
        $this->exec(
            "INSERT INTO notificaciones (tenant_id, usuario_id, reserva_id, canal, tipo, cuerpo, estado, programada_para_utc, dedupe_key)
             VALUES (?, ?, ?, 'push', 'recordatorio_24h', 'Recordatorio', 'programada', UTC_TIMESTAMP() + INTERVAL 1 DAY, ?)",
            [$this->tenantId('demo-centro'), $this->userId(self::CLIENTE1), $r['id'], "reserva:{$r['id']}:recordatorio_24h:push"]
        );
        $this->call('POST', "/mis-reservas/{$r['id']}/cancelar", [], $h);
        $this->assertSame('cancelada', $this->scalar("SELECT estado FROM notificaciones WHERE dedupe_key = ?", ["reserva:{$r['id']}:recordatorio_24h:push"]));
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notificaciones WHERE reserva_id = ? AND usuario_id = ? AND tipo = 'reserva_cancelada'", [$r['id'], $barberoUser]));
    }

    public function testMyBookingsListsUpcomingAndPastAcrossBarbershops(): void
    {
        $h = $this->client();
        $res = $this->call('GET', '/mis-reservas', null, $h);
        $this->assertSame(200, $res->status());
        $up = $this->data($res);
        $this->assertSame(['demo-centro', 'demo-norte'], array_values(array_unique(array_column(array_column($up, 'barberia'), 'slug'))));
        $this->assertSame(array_column($up, 'inicio_utc'), (static function (array $x) { sort($x); return $x; })(array_column($up, 'inicio_utc')), 'La más cercana primero');
        $past = $this->data($this->call('GET', '/mis-reservas?tipo=pasadas', null, $h));
        $this->assertSame(['completada'], array_column($past, 'estado'));
        $this->cookies = [];
        $this->assertSame(401, $this->call('GET', '/mis-reservas')->status());
    }

    public function testUnverifiedAccountCannotBookAndCounterClientIsLinkedOnceVerified(): void
    {
        // Cuenta nueva con el correo de una ficha de mostrador.
        $this->exec("UPDATE clientes SET email = 'mostrador@barberapp.test' WHERE nombres = 'Cliente Mostrador'");
        $res = $this->call('POST', '/auth/register', [
            'nombres' => 'Mostrador', 'apellidos' => 'Demo', 'email' => 'mostrador@barberapp.test', 'password' => 'Clave-Segura-2026', 'tipo' => 'cliente',
            'acepta_terminos' => true, 'acepta_privacidad' => true, 'canal' => 'web',
        ]);
        $this->assertSame(201, $res->status(), $res->body());
        $h = ['X-CSRF-Token' => (string) $this->data($res)['csrf_token']];
        $res = $this->book($this->body($this->day(4, 6), '10:00'), $h);
        $this->assertSame(403, $res->status());
        $this->assertSame('email_not_verified', $this->errorCode($res));

        $this->exec("UPDATE usuarios SET email_verificado_en = UTC_TIMESTAMP() WHERE email = 'mostrador@barberapp.test'");
        $past = $this->data($this->call('GET', '/mis-reservas?tipo=pasadas', null, $h));
        $this->assertSame(['cancelada'], array_column($past, 'estado'), 'Ve la cita que la barbería le registró en mostrador');
        $this->assertSame('personal', $past[0]['cancelacion']['por']);
        $res = $this->book($this->body($this->day(4, 6), '10:00'), $h);
        $this->assertSame(201, $res->status(), $res->body());
        $this->assertSame(
            (int) $this->scalar("SELECT id FROM clientes WHERE nombres = 'Cliente Mostrador'"),
            (int) $this->scalar('SELECT cliente_id FROM reservas WHERE id = ?', [$this->data($res)['id']])
        );
        $this->assertSame(1, $this->auditCount('cliente.vinculado_mostrador', 'exito'));
    }

    public function testPanelBookingPolicyIsConfigurable(): void
    {
        $h = $this->owner();
        $res = $this->call('GET', '/panel/politicas-reserva', null, $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame(15, $this->data($res)['general']['intervalo_minutos']);

        $base = $this->data($res)['general'];
        unset($base['sucursal_id']);
        $sede = $this->branchId('sede-cercado');
        $res = $this->call('PUT', '/panel/politicas-reserva', ['sucursal_id' => $sede, 'intervalo_minutos' => 30, 'margen_entre_citas_minutos' => 10, 'confirmacion_automatica' => false] + $base, $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame([$sede, 30], [$this->data($res)['sucursal_id'], $this->data($res)['intervalo_minutos']]);

        $f = $this->day(5, 7);
        $hours = $this->hours($f);
        $this->assertSame(['09:00', '09:30', '10:00'], array_slice($hours, 0, 3), 'Grilla de 30 en esa sucursal');
        $r = $this->data($this->book($this->body($f, '09:00'), $this->client()));
        $this->assertSame('pendiente', $r['estado'], 'Sin confirmación automática nace pendiente');
        $this->assertSame('09:30', $r['hora_fin']);
        $this->assertNotContains('09:30', $this->hours($f), 'Margen de 10 min tras la cita');
        $this->assertContains('09:40', $this->hours($f));

        $this->assertSame(422, $this->call('PUT', '/panel/politicas-reserva', ['intervalo_minutos' => 7] + $base, $this->owner())->status());
        $this->assertSame(204, $this->call('DELETE', "/panel/politicas-reserva/{$sede}", null, $this->owner())->status());
        $this->assertSame(403, $this->call('GET', '/panel/politicas-reserva', null, $this->actingAs('admin.centro@barberapp.test'))->status(), 'El administrador no gestiona políticas');
    }
}
