<?php

declare(strict_types=1);

namespace Tests\Integration\Panel;

/**
 * Agenda del panel (Fase 6): vista, alcance por rol, alta manual, confirmación, atención,
 * cancelación y reprogramación por el personal, notas y cobros.
 */
final class PanelAgendaTest extends PanelTestCase
{
    public function testOwnerSeesDayAgendaWithBarbersWorkingHoursAndStates(): void
    {
        $r1 = $this->demoBooking('confirmada');
        $fecha = $this->localDateOf((string) $r1['inicio_utc']);
        $res = $this->call('GET', '/panel/agenda?fecha=' . $fecha, null, $this->owner());
        $this->assertSame(200, $res->status(), $res->body());
        $a = $this->data($res);
        $this->assertSame('barberia', $a['alcance']);
        $this->assertSame([$fecha, $fecha], [$a['desde'], $a['hasta']]);
        $this->assertSame('America/Lima', $a['sucursal']['zona_horaria']);
        $nombres = array_column($a['barberos'], 'nombre');
        $this->assertContains('Barbero Uno Demo', $nombres);
        $this->assertContains('Barbero Dos Demo', $nombres);
        $this->assertNotEmpty(array_filter($a['jornadas'], fn ($j) => $j['barbero_id'] === $this->barberId('Barbero Uno Demo')), 'Muestra la jornada del barbero');

        $byCode = array_column($a['reservas'], null, 'codigo');
        $this->assertSame('10:00', $byCode[$r1['codigo']]['hora_inicio']);
        $this->assertSame('Cliente Uno Demo', $byCode[$r1['codigo']]['cliente']['nombre']);
        $this->assertSame('+51900000001', $byCode[$r1['codigo']]['cliente']['telefono']);
        $this->assertTrue($byCode[$r1['codigo']]['acciones']['cancelar']);
        $this->assertTrue($byCode[$r1['codigo']]['acciones']['reprogramar']);
        $this->assertFalse($byCode[$r1['codigo']]['acciones']['completar'], 'Aún no es hora de atender');
        $this->assertSame(1, $a['resumen']['confirmada']);
        $this->assertSame(1, $a['resumen']['pendiente']);
        $this->assertSame(1, $a['resumen']['cancelada']);
        $this->assertSame(2, $a['resumen']['total'], 'Las canceladas no cuentan en el total');

        // Vista semanal: lunes a domingo.
        $w = $this->data($this->call('GET', '/panel/agenda?vista=semana&fecha=' . $fecha, null, $this->owner()));
        $this->assertSame('1', (new \DateTimeImmutable($w['desde']))->format('N'));
        $this->assertSame('7', (new \DateTimeImmutable($w['hasta']))->format('N'));
        $this->assertContains($r1['codigo'], array_column($w['reservas'], 'codigo'));

        $res = $this->call('GET', '/panel/agenda?desde=2026-01-01&hasta=2026-01-20', null, $this->owner());
        $this->assertSame(422, $res->status(), 'Máximo 7 días');
    }

    public function testBarberOnlySeesAndOperatesOwnBookings(): void
    {
        $r1 = $this->demoBooking('confirmada'); // Barbero Uno
        $fecha = $this->localDateOf((string) $r1['inicio_utc']);
        $h = $this->actingAs(self::BARBERO1);
        $a = $this->data($this->call('GET', '/panel/agenda?fecha=' . $fecha . '&barbero_id=' . $this->barberId('Barbero Dos Demo'), null, $h));
        $this->assertSame('propia', $a['alcance']);
        $this->assertSame(['Barbero Uno Demo'], array_column($a['barberos'], 'nombre'), 'Ignora el filtro de otro barbero');
        $this->assertSame([$this->barberId('Barbero Uno Demo')], array_values(array_unique(array_column(array_column($a['reservas'], 'barbero'), 'id'))));
        $this->assertFalse($a['reservas'][0]['acciones']['cancelar'], 'El barbero no cancela');

        $cancelada = $this->demoBooking('cancelada'); // de Barbero Dos
        $this->assertSame(404, $this->call('GET', "/panel/reservas/{$cancelada['id']}", null, $h)->status());
        $this->assertSame(403, $this->call('POST', "/panel/reservas/{$r1['id']}/cancelar", ['motivo' => 'Prueba'], $h)->status());
        $this->assertSame(403, $this->call('GET', '/panel/clientes', null, $h)->status());
        $this->assertSame(403, $this->call('GET', '/panel/reportes', null, $h)->status());
        $this->assertSame(403, $this->call('POST', "/panel/reservas/{$r1['id']}/pagos", ['monto' => 10, 'metodo' => 'efectivo'], $h)->status());

        // Un cliente sin membresía no entra al panel.
        $this->assertSame(404, $this->call('GET', '/panel/agenda', null, $this->actingAs(self::CLIENTE1))->status());
    }

    public function testOtherTenantCannotSeeOrTouchBookings(): void
    {
        $r1 = $this->demoBooking('confirmada');
        $h = $this->actingAs('propietario.norte@barberapp.test', 'demo-norte');
        $this->assertSame(404, $this->call('GET', "/panel/reservas/{$r1['id']}", null, $h)->status());
        $this->assertSame(404, $this->call('POST', "/panel/reservas/{$r1['id']}/cancelar", ['motivo' => 'Ajena'], $h)->status());
        $this->assertSame(404, $this->call('POST', "/panel/reservas/{$r1['id']}/pagos", ['monto' => 10, 'metodo' => 'efectivo'], $h)->status());
        $this->assertSame('confirmada', $this->scalar('SELECT estado FROM reservas WHERE id = ?', [$r1['id']]));
        $c = $this->data($this->call('GET', '/panel/clientes', null, $h));
        $this->assertSame(['Cliente Uno Demo'], array_column($c, 'nombre'), 'Solo sus clientes vinculados');
        $this->assertSame(404, $this->call('GET', '/panel/clientes/' . (int) $this->scalar("SELECT id FROM clientes WHERE nombres = 'Cliente Dos'"), null, $h)->status());
    }

    public function testManualBookingWithWalkInClientUsesTheSameEngine(): void
    {
        $f = $this->day(3);
        $h = $this->actingAs(self::ADMIN);
        $res = $this->panelBook($this->panelBody($f, '12:00', ['cliente' => ['nombres' => 'Pedro', 'apellidos' => 'Mostrador', 'telefono' => '987 654 321'], 'notas_internas' => 'Prefiere tijera']), $h);
        $this->assertSame(201, $res->status(), $res->body());
        $r = $this->data($res);
        $this->assertSame(['confirmada', 'panel', '12:00', '12:30'], [$r['estado'], $r['canal'], $r['hora_inicio'], $r['hora_fin']]);
        $this->assertSame('Pedro Mostrador', $r['cliente']['nombre']);
        $this->assertSame('+51987654321', $r['cliente']['telefono']);
        $this->assertFalse($r['cliente']['tiene_cuenta']);
        $this->assertSame('Prefiere tijera', $r['notas_internas']);
        $this->assertSame('panel', $this->scalar('SELECT origen FROM cliente_tenants WHERE cliente_id = ?', [$r['cliente']['id']]));
        $this->assertNotContains('12:00', $this->hours($f), 'El horario deja de ofrecerse en línea');
        $res = $this->book($this->body($f, '12:00'), $this->client());
        $this->assertSame(409, $res->status(), $res->body());
        $h = $this->actingAs(self::ADMIN);

        // Mismo teléfono: reutiliza la ficha; horario ocupado: 409 con alternativas.
        $res = $this->panelBook($this->panelBody($f, '12:15', ['cliente' => ['nombres' => 'Pedro M.', 'telefono' => '+51987654321']]), $h);
        $this->assertSame(409, $res->status());
        $this->assertSame('slot_unavailable', $this->errorCode($res));
        $res = $this->panelBook($this->panelBody($f, '14:00', ['cliente' => ['nombres' => 'Pedro M.', 'telefono' => '+51987654321']]), $h);
        $this->assertSame(201, $res->status(), $res->body());
        $this->assertSame($r['cliente']['id'], $this->data($res)['cliente']['id']);

        // El cliente no puede tener dos citas a la vez, ni siquiera desde el panel.
        $res = $this->panelBook($this->panelBody($f, '14:00', ['cliente_id' => $r['cliente']['id']], ['Corte clásico'], 'Barbero Dos Demo'), $h);
        $this->assertSame(409, $res->status());
        $this->assertSame('client_overlap', $this->errorCode($res));

        // Cliente de otra barbería: no se puede usar por id.
        $ajeno = (int) $this->scalar("SELECT id FROM clientes WHERE nombres = 'Pedro'") + 999;
        $res = $this->panelBook($this->panelBody($f, '16:00', ['cliente_id' => $ajeno]), $h);
        $this->assertSame(422, $res->status());
        $this->assertSame(403, $this->panelBook($this->panelBody($f, '16:00', ['cliente_id' => $r['cliente']['id']]), $this->actingAs(self::BARBERO1))->status());

        // Repetir la misma petición (red) no duplica.
        $body = $this->panelBody($f, '17:00', ['cliente_id' => $r['cliente']['id']]);
        $h = $this->actingAs(self::ADMIN);
        $a = $this->panelBook($body, $h, 'panel-retry-key-1');
        $b = $this->panelBook($body, $h, 'panel-retry-key-1');
        $this->assertSame([201, 201], [$a->status(), $b->status()]);
        $this->assertSame($this->data($a)['id'], $this->data($b)['id']);
        $this->assertSame(1, $this->auditCount('cliente.registrado_mostrador', 'exito'));
    }

    public function testStaffBookingIgnoresMinimumNoticeButNotThePast(): void
    {
        $f = $this->day(3);
        // 15 minutos antes de la hora: en línea no se puede (anticipación 60 min), en el panel sí.
        $this->freezeAt(\App\Helpers\Clock::localToUtc($f . ' 09:45:00', 'America/Lima')->format('Y-m-d H:i:s'));
        $res = $this->book($this->body($f, '10:00'), $this->client());
        $this->assertSame(422, $res->status());
        $this->assertSame('too_soon', $this->errorCode($res));
        $h = $this->actingAs(self::ADMIN);
        $res = $this->panelBook($this->panelBody($f, '10:00', ['cliente_id' => (int) $this->scalar("SELECT id FROM clientes WHERE nombres = 'Cliente Mostrador'")]), $h);
        $this->assertSame(201, $res->status(), $res->body());
        $res = $this->panelBook($this->panelBody($f, '09:00', ['cliente_id' => (int) $this->scalar("SELECT id FROM clientes WHERE nombres = 'Cliente Mostrador'")]), $h);
        $this->assertSame(422, $res->status(), 'Nunca en el pasado');
    }

    public function testConfirmAttendanceCompleteAndNoShowWithCorrections(): void
    {
        $r1 = $this->demoBooking('confirmada');
        $r2 = $this->demoBooking('pendiente');
        $h = $this->actingAs(self::BARBERO1);

        $res = $this->call('POST', "/panel/reservas/{$r2['id']}/confirmar", [], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame('confirmada', $this->data($res)['estado']);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notificaciones WHERE reserva_id = ? AND tipo = 'reserva_confirmada' AND canal = 'interna' AND usuario_id = ?", [$r2['id'], $this->userId(self::CLIENTE2)]));
        // Fase 8: además de la bandeja, el aviso sale por correo (Cliente Dos no tiene app con push).
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notificaciones WHERE reserva_id = ? AND tipo = 'reserva_confirmada' AND canal = 'email' AND usuario_id = ?", [$r2['id'], $this->userId(self::CLIENTE2)]));

        $res = $this->call('POST', "/panel/reservas/{$r1['id']}/iniciar", [], $h);
        $this->assertSame(422, $res->status(), 'Muy temprano');
        $this->assertSame(422, $this->call('POST', "/panel/reservas/{$r1['id']}/no-asistio", [], $h)->status());

        $this->freezeAt((string) $r1['inicio_utc'], 5);
        $h = $this->actingAs(self::BARBERO1); // la sesión anterior vence por inactividad al adelantar el reloj
        $res = $this->call('POST', "/panel/reservas/{$r1['id']}/iniciar", [], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertTrue($this->data($res)['en_atencion']);
        $this->assertSame(409, $this->call('POST', "/panel/reservas/{$r1['id']}/no-asistio", [], $h)->status(), 'Ya se inició la atención');
        $res = $this->call('POST', "/panel/reservas/{$r1['id']}/completar", [], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $c = $this->data($res);
        $this->assertSame('completada', $c['estado']);
        $this->assertContains('Atendida', array_column($c['historial'], 'texto'));
        $this->assertNotNull($this->scalar('SELECT ultima_visita_en FROM cliente_tenants WHERE tenant_id = ? AND cliente_id = ?', [$this->tenantId('demo-centro'), $r1['cliente_id']]));
        // Recordatorios de la cita (Fase 8): los que seguían en cola quedan anulados.
        $this->assertGreaterThan(0, (int) $this->scalar("SELECT COUNT(*) FROM notificaciones WHERE reserva_id = ? AND tipo = 'recordatorio' AND estado = 'cancelada'", [$r1['id']]), 'Recordatorios anulados');
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM notificaciones WHERE reserva_id = ? AND tipo = 'recordatorio' AND estado = 'programada'", [$r1['id']]));

        // La cita de las 11:00 (r2) aún no empieza: no se marca inasistencia.
        $this->assertSame(422, $this->call('POST', "/panel/reservas/{$r2['id']}/no-asistio", [], $h)->status());
        $this->freezeAt((string) $r2['inicio_utc'], 20);
        $h = $this->actingAs(self::BARBERO1);
        $res = $this->call('POST', "/panel/reservas/{$r2['id']}/no-asistio", [], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame('no_asistio', $this->data($res)['estado']);
        $ct = fn () => (int) $this->scalar('SELECT total_inasistencias FROM cliente_tenants WHERE tenant_id = ? AND cliente_id = ?', [$this->tenantId('demo-centro'), $r2['cliente_id']]);
        $this->assertSame(1, $ct());
        $this->assertSame(200, $this->call('POST', "/panel/reservas/{$r2['id']}/no-asistio", [], $h)->status(), 'Idempotente');
        $this->assertSame(1, $ct());

        // Corrección: el cliente llegó tarde y sí fue atendido.
        $res = $this->call('POST', "/panel/reservas/{$r2['id']}/completar", [], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertContains('Corregida: el cliente sí fue atendido', array_column($this->data($res)['historial'], 'texto'));
        $this->assertSame(0, $ct());
        $this->assertSame(4, $this->auditCount('reserva.completada', 'exito') + $this->auditCount('reserva.no_asistio', 'exito') + $this->auditCount('reserva.atencion_iniciada', 'exito'));

        // El horario liberado por la atención anticipada vuelve a estar libre para el panel.
        $this->assertSame(0, (int) $this->scalar('SELECT ocupa_agenda FROM reservas WHERE id = ?', [$r1['id']]));
    }

    public function testStaffCancelAndRescheduleToAnotherBarber(): void
    {
        $r1 = $this->demoBooking('confirmada');
        $fecha = $this->localDateOf((string) $r1['inicio_utc']);
        $h = $this->actingAs(self::ADMIN);
        $res = $this->call('POST', "/panel/reservas/{$r1['id']}/cancelar", [], $h);
        $this->assertSame(422, $res->status(), 'El motivo es obligatorio para el personal');

        // Horarios para reprogramar con Barbero Dos (no hace "Corte y barba" → 422).
        $res = $this->call('GET', "/panel/reservas/{$r1['id']}/disponibilidad?fecha={$fecha}&barbero_id=" . $this->barberId('Barbero Dos Demo'), null, $h);
        $this->assertSame(422, $res->status());
        $res = $this->call('GET', "/panel/reservas/{$r1['id']}/disponibilidad?fecha={$fecha}", null, $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertContains('10:00', array_column($this->data($res)['horarios'], 'hora'), 'La propia cita no cuenta como ocupada');

        $res = $this->call('POST', "/panel/reservas/{$r1['id']}/reprogramar", ['fecha' => $fecha, 'hora' => '16:00'], $h + ['Idempotency-Key' => 'panel-reprog-0001']);
        $this->assertSame(200, $res->status(), $res->body());
        $r = $this->data($res);
        $this->assertSame('16:00', $r['hora_inicio']);
        $this->assertSame(1, $r['reprogramaciones']);
        $this->assertSame('personal', $this->scalar("SELECT actor_tipo FROM reserva_eventos WHERE reserva_id = ? AND tipo = 'reprogramada'", [$r1['id']]));
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notificaciones WHERE reserva_id = ? AND tipo = 'reserva_reprogramada' AND usuario_id = ?", [$r1['id'], $this->userId(self::CLIENTE1)]));

        $res = $this->call('POST', "/panel/reservas/{$r1['id']}/cancelar", ['motivo' => 'El barbero se enfermó'], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $c = $this->data($res);
        $this->assertSame('cancelada', $c['estado']);
        $this->assertSame(['personal', 'El barbero se enfermó'], [$c['cancelacion']['por'], $c['cancelacion']['motivo']]);
        $this->assertSame('Administrador Demo Centro', end($c['historial'])['actor']);
        $this->assertContains('16:00', $this->hours($fecha, ['Corte y barba']), 'El horario vuelve a ofrecerse');
    }

    public function testNotesAndPayments(): void
    {
        $r1 = $this->demoBooking('confirmada');
        $h = $this->owner();
        $res = $this->call('PUT', "/panel/reservas/{$r1['id']}/notas", ['notas_internas' => 'Alérgico a la loción'], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame('Alérgico a la loción', $this->data($res)['notas_internas']);
        $this->assertNull($this->data($this->call('GET', "/mis-reservas/{$r1['id']}", null, $this->client()))['notas_internas'] ?? null, 'El cliente no ve notas internas');

        $h = $this->owner();
        $res = $this->call('POST', "/panel/reservas/{$r1['id']}/pagos", ['monto' => '40.00', 'metodo' => 'yape', 'referencia' => 'OP-123'], $h);
        $this->assertSame(201, $res->status(), $res->body());
        $p = $this->data($res);
        $this->assertSame(['40.00', '0.00'], [$p['pagado'], $p['saldo']]);
        $this->assertCount(1, $p['cobros']);
        $res = $this->call('POST', "/panel/reservas/{$r1['id']}/pagos", ['monto' => 400, 'metodo' => 'efectivo'], $h);
        $this->assertSame(422, $res->status());
        $this->assertSame('payment_exceeds_total', $this->errorCode($res));
        $res = $this->call('POST', "/panel/reservas/{$r1['id']}/pagos", ['monto' => 5, 'metodo' => 'efectivo', 'confirmar_excedente' => true], $h);
        $this->assertSame(201, $res->status(), $res->body());
        $this->assertSame('45.00', $this->data($res)['pagado']);
        $this->assertSame(422, $this->call('POST', "/panel/reservas/{$r1['id']}/pagos", ['monto' => 0, 'metodo' => 'efectivo'], $h)->status());
        $this->assertSame(422, $this->call('POST', "/panel/reservas/{$r1['id']}/pagos", ['monto' => 10, 'metodo' => 'bitcoin'], $h)->status());

        $pagoId = (int) $p['cobros'][0]['id'];
        $this->assertSame(422, $this->call('POST', "/panel/reservas/{$r1['id']}/pagos/{$pagoId}/anular", [], $h)->status(), 'Motivo obligatorio');
        $res = $this->call('POST', "/panel/reservas/{$r1['id']}/pagos/{$pagoId}/anular", ['motivo' => 'Se registró dos veces'], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame('5.00', $this->data($res)['pagado']);
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM reserva_pagos WHERE reserva_id = ? AND anulado_en IS NOT NULL', [$r1['id']]), 'Se anula, no se borra');

        // Con cobros vigentes no se puede corregir a inasistencia.
        $this->freezeAt((string) $r1['inicio_utc'], 10);
        $h = $this->owner();
        $this->assertSame(200, $this->call('POST', "/panel/reservas/{$r1['id']}/completar", [], $h)->status());
        $res = $this->call('POST', "/panel/reservas/{$r1['id']}/no-asistio", [], $h);
        $this->assertSame(409, $res->status());
        $this->assertSame('booking_has_payments', $this->errorCode($res));

        // El administrador no registra cobros si no tiene el permiso… (sí lo tiene: reservas.*)
        $this->assertSame(201, $this->call('POST', "/panel/reservas/{$r1['id']}/pagos", ['monto' => 1, 'metodo' => 'efectivo', 'confirmar_excedente' => true], $this->actingAs(self::ADMIN))->status());
        // …pero no puede cobrar una cita cancelada.
        $cancelada = $this->demoBooking('cancelada');
        $this->assertSame(409, $this->call('POST', "/panel/reservas/{$cancelada['id']}/pagos", ['monto' => 1, 'metodo' => 'efectivo'], $this->actingAs(self::ADMIN))->status());
    }

    public function testBookingCatalogForManualBookings(): void
    {
        $res = $this->call('GET', '/panel/agenda/catalogo', null, $this->actingAs(self::ADMIN));
        $this->assertSame(200, $res->status(), $res->body());
        $c = $this->data($res);
        $this->assertContains('Corte clásico', array_column($c['servicios'], 'nombre'));
        $uno = array_column($c['barberos'], null, 'nombre')['Barbero Uno Demo'];
        $this->assertContains($this->serviceId('Corte clásico'), $uno['servicio_ids']);
        $this->assertContains($this->branchId('sede-cercado'), $uno['sucursal_ids']);
        $this->assertSame(403, $this->call('GET', '/panel/agenda/catalogo', null, $this->actingAs(self::BARBERO1))->status(), 'El barbero no crea citas manuales');
    }
}
