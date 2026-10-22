<?php

declare(strict_types=1);

namespace Tests\Integration\Barber;

use Tests\Integration\Panel\PanelTestCase;

/**
 * Panel del barbero (Fase 7): agenda propia, atención, historial autorizado, horario y
 * solicitudes de ausencia sujetas a aprobación. El barbero nunca ve datos de caja ni a otros barberos.
 */
final class BarberPanelTest extends PanelTestCase
{
    private const BARBERO2 = 'barbero2.centro@barberapp.test';

    public function testDayAgendaShowsOnlyOwnBookingsWithWorkingHoursAndNoCashData(): void
    {
        $r1 = $this->demoBooking('confirmada'); // Barbero Uno, mañana 10:00
        $r2 = $this->demoBooking('pendiente');  // Barbero Uno, mañana 11:00
        $fecha = $this->localDateOf((string) $r1['inicio_utc']);
        $h = $this->actingAs(self::BARBERO1);

        $res = $this->call('GET', '/barbero/hoy?fecha=' . $fecha, null, $h);
        $this->assertSame(200, $res->status(), $res->body());
        $d = $this->data($res);
        $this->assertSame('Barbero Uno Demo', $d['barbero']['nombre']);
        $this->assertSame($fecha, $d['fecha']);
        $this->assertSame([$r1['codigo'], $r2['codigo']], array_column($d['citas'], 'codigo'), 'Solo sus citas, en orden');
        $this->assertSame([['09:00', '13:00'], ['14:00', '20:00']], array_map(static fn ($j) => [$j['inicio'], $j['fin']], $d['jornada']), 'Jornada sin el almuerzo');
        $this->assertSame('Almuerzo', $d['descansos'][0]['motivo']);
        $this->assertSame(2, $d['resumen']['total']);
        $this->assertSame(45 + 50, $d['resumen']['minutos_reservados']);
        $this->assertSame(600, $d['resumen']['minutos_jornada']);

        $c = $d['citas'][0];
        $this->assertSame('Cliente Uno Demo', $c['cliente']['nombre']);
        $this->assertSame('+51900000001', $c['cliente']['telefono'], 'Teléfono para avisar si hay retraso');
        $this->assertNull($c['cliente']['email'], 'Sin correo del cliente');
        $this->assertArrayNotHasKey('pagado', $c);
        $this->assertArrayNotHasKey('saldo', $c);
        $this->assertSame(['confirmar', 'iniciar', 'completar', 'no_asistio', 'notas'], array_keys($c['acciones']));
        $this->assertSame('Corte y barba', $c['servicios'][0]['nombre']);

        $this->assertTrue($d['ajustes']['ausencias_requieren_aprobacion']);
        $this->assertFalse($d['ajustes']['puede_editar_horario']);
        $this->assertSame(1, $d['solicitudes_pendientes'], 'La solicitud demo pendiente');

        // Barbero Dos ve solo lo suyo (la cita cancelada de mañana).
        $d2 = $this->data($this->call('GET', '/barbero/hoy?fecha=' . $fecha, null, $this->actingAs(self::BARBERO2)));
        $this->assertSame([$this->demoBooking('cancelada')['codigo']], array_column($d2['citas'], 'codigo'));
        $this->assertSame(0, $d2['resumen']['total'], 'Las canceladas no cuentan');
    }

    public function testNextAndInProgressFollowTheClock(): void
    {
        $r1 = $this->demoBooking('confirmada');
        $r2 = $this->demoBooking('pendiente');
        $this->freezeAt((string) $r1['inicio_utc'], -10);
        $h = $this->actingAs(self::BARBERO1);

        $d = $this->data($this->call('GET', '/barbero/hoy', null, $h));
        $this->assertSame($this->localDateOf((string) $r1['inicio_utc']), $d['fecha'], 'Por defecto, hoy');
        $this->assertSame((int) $r1['id'], $d['siguiente_id']);
        $this->assertNull($d['en_atencion_id']);
        $this->assertTrue($d['citas'][0]['acciones']['iniciar'], 'Se puede iniciar desde 30 min antes');
        $this->assertFalse($d['citas'][0]['acciones']['no_asistio'], 'Aún no es la hora');

        $res = $this->call('POST', "/barbero/citas/{$r1['id']}/iniciar", null, $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertTrue($this->data($res)['en_atencion']);
        $d = $this->data($this->call('GET', '/barbero/hoy', null, $h));
        $this->assertSame((int) $r1['id'], $d['en_atencion_id']);
        $this->assertSame((int) $r2['id'], $d['siguiente_id']);
    }

    public function testBarberAttendsOwnBookingsAndCannotTouchOthers(): void
    {
        $r1 = $this->demoBooking('confirmada');
        $r2 = $this->demoBooking('pendiente');
        $ajena = $this->demoBooking('cancelada'); // Barbero Dos
        $completadaAjena = $this->demoBooking('completada'); // Barbero Dos, del mismo cliente que r1
        $this->freezeAt((string) $r1['inicio_utc'], 5);
        $h = $this->actingAs(self::BARBERO1);

        $this->assertSame(200, $this->call('POST', "/barbero/citas/{$r1['id']}/iniciar", null, $h)->status());
        $res = $this->call('POST', "/barbero/citas/{$r1['id']}/completar", null, $h);
        $this->assertSame(200, $res->status(), $res->body());
        $c = $this->data($res);
        $this->assertSame('completada', $c['estado']);
        $this->assertArrayNotHasKey('cobros', $c, 'Sin caja en la vista del barbero');
        $this->assertContains('Atendida', array_column($c['historial'], 'texto'));
        $this->assertSame(1, $this->auditCount('reserva.completada', 'exito'));
        // Historial autorizado: la visita del cliente con OTRO barbero no aparece.
        $this->assertSame(0, $c['visitas_previas']['atendidas']);
        $this->assertSame([], $c['visitas_previas']['ultimas']);

        $res = $this->call('PUT', "/barbero/citas/{$r1['id']}/notas", ['notas_internas' => 'Prefiere degradado bajo'], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame('Prefiere degradado bajo', $this->data($res)['notas_internas']);

        // No asistió: desde la hora de inicio de la cita.
        $this->assertSame(422, $this->call('POST', "/barbero/citas/{$r2['id']}/no-asistio", null, $h)->status());
        $this->freezeAt((string) $r2['inicio_utc'], 15);
        $h = $this->actingAs(self::BARBERO1);
        $res = $this->call('POST', "/barbero/citas/{$r2['id']}/no-asistio", null, $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame('no_asistio', $this->data($res)['estado']);

        // Citas de otro barbero: 404 (no se revela que existen).
        foreach (["GET /barbero/citas/{$ajena['id']}", "POST /barbero/citas/{$completadaAjena['id']}/no-asistio", "PUT /barbero/citas/{$ajena['id']}/notas"] as $req) {
            [$m, $p] = explode(' ', $req);
            $this->assertSame(404, $this->call($m, $p, $m === 'GET' ? null : ['notas_internas' => 'x'], $h)->status(), $req);
        }
        $this->assertSame('completada', $this->scalar('SELECT estado FROM reservas WHERE id = ?', [$completadaAjena['id']]));

        // Nada de reportes financieros ni datos del resto del equipo.
        $this->assertSame(403, $this->call('GET', '/panel/reportes', null, $h)->status());
        $this->assertSame(403, $this->call('GET', '/panel/dashboard', null, $h)->status());
        $this->assertSame(403, $this->call('GET', '/panel/barberos', null, $h)->status());
        $this->assertSame(403, $this->call('GET', '/panel/personal', null, $h)->status());
        $this->assertSame(403, $this->call('GET', '/panel/equipo/ajustes', null, $h)->status());
    }

    public function testClientVisitsWithSameBarberAreShownInDetail(): void
    {
        $r1 = $this->demoBooking('confirmada');
        // Visita anterior del mismo cliente con Barbero Uno.
        $this->exec("UPDATE reservas SET barbero_id = ? WHERE id = ?", [$this->barberId('Barbero Uno Demo'), $this->demoBooking('completada')['id']]);
        $d = $this->data($this->call('GET', "/barbero/citas/{$r1['id']}", null, $this->actingAs(self::BARBERO1)));
        $this->assertSame(1, $d['visitas_previas']['atendidas']);
        $this->assertSame('completada', $d['visitas_previas']['ultimas'][0]['estado']);
        $this->assertSame(['Corte clásico'], $d['visitas_previas']['ultimas'][0]['servicios']);
    }

    public function testAccessRules(): void
    {
        // El administrador no tiene ficha de barbero: 403 con un código claro para la interfaz.
        $res = $this->call('GET', '/barbero/hoy', null, $this->actingAs(self::ADMIN));
        $this->assertSame(403, $res->status());
        $this->assertSame('no_barber_profile', $this->errorCode($res));
        // Un cliente sin membresía no entra; sin sesión, 401.
        $this->assertSame(404, $this->call('GET', '/barbero/hoy', null, $this->actingAs(self::CLIENTE1))->status());
        $this->cookies = [];
        $this->assertSame(401, $this->call('GET', '/barbero/hoy', null, ['X-Tenant' => 'demo-centro'])->status());
        // Barbero Dos es ADMINISTRADOR (sin ficha de barbero) en demo-norte: allí no hay panel del barbero.
        $res = $this->call('GET', '/barbero/hoy', null, $this->actingAs(self::BARBERO2, 'demo-norte'));
        $this->assertSame('no_barber_profile', $this->errorCode($res));

        // El contexto del panel indica si la cuenta tiene ficha de barbero.
        $ctx = $this->data($this->call('GET', '/panel/contexto', null, $this->actingAs(self::BARBERO1)));
        $this->assertSame('Barbero Uno Demo', $ctx['barbero']['nombre']);
        $this->assertNull($this->data($this->call('GET', '/panel/contexto', null, $this->owner()))['barbero']);
    }

    public function testOwnerWhoAlsoCutsHairSeesOnlyOwnColumnAndSelfApproves(): void
    {
        $b2 = $this->barberId('Barbero Dos Demo');
        $this->exec('UPDATE barberos SET usuario_id = ? WHERE id = ?', [$this->userId('propietario.centro@barberapp.test'), $b2]);
        $r1 = $this->demoBooking('confirmada');
        $fecha = $this->localDateOf((string) $r1['inicio_utc']);
        $h = $this->owner();
        $d = $this->data($this->call('GET', '/barbero/hoy?fecha=' . $fecha, null, $h));
        $this->assertSame('Barbero Dos Demo', $d['barbero']['nombre']);
        $this->assertSame([$this->demoBooking('cancelada')['codigo']], array_column($d['citas'], 'codigo'), 'Aunque tenga reservas.ver_todas, aquí solo ve lo suyo');
        $this->assertSame(404, $this->call('GET', "/barbero/citas/{$r1['id']}", null, $h)->status());
        $this->assertFalse($d['ajustes']['ausencias_requieren_aprobacion'], 'Quien aprueba no espera su propia aprobación');

        $dia = $this->day(3, 40);
        $res = $this->call('POST', '/barbero/ausencias', ['tipo' => 'ausencia', 'dia_completo' => true, 'fecha_inicio' => $dia], $h);
        $this->assertSame(201, $res->status(), $res->body());
        $this->assertSame('aprobado', $this->data($res)['estado']);
    }

    public function testUpcomingAndHistory(): void
    {
        $r1 = $this->demoBooking('confirmada');
        $r2 = $this->demoBooking('pendiente');
        $h = $this->actingAs(self::BARBERO1);
        $up = $this->data($this->call('GET', '/barbero/proximas?dias=7', null, $h));
        $this->assertSame([$r1['codigo'], $r2['codigo']], array_column($up['citas'], 'codigo'));
        $this->assertSame(422, $this->call('GET', '/barbero/proximas?dias=90', null, $h)->status());

        // Se atiende r1; r2 no asiste. Al día siguiente ambas están en el historial.
        $this->freezeAt((string) $r1['inicio_utc'], 1);
        $h = $this->actingAs(self::BARBERO1);
        $this->call('POST', "/barbero/citas/{$r1['id']}/completar", null, $h);
        $this->freezeAt((string) $r2['inicio_utc'], 5);
        $h = $this->actingAs(self::BARBERO1);
        $this->call('POST', "/barbero/citas/{$r2['id']}/no-asistio", null, $h);
        $this->freezeAt((string) $r2['inicio_utc'], 60 * 24);
        $h = $this->actingAs(self::BARBERO1);

        $res = $this->call('GET', '/barbero/historial', null, $h);
        $this->assertSame(200, $res->status(), $res->body());
        $body = json_decode($res->body(), true);
        $this->assertSame([$r2['codigo'], $r1['codigo']], array_column($body['data'], 'codigo'), 'Más recientes primero, solo las suyas');
        $this->assertSame(1, $body['meta']['por_estado']['completada']);
        $this->assertSame(1, $body['meta']['por_estado']['no_asistio']);
        $this->assertEquals(50.0, $body['meta']['tasa_inasistencia']);
        $this->assertSame([], $this->data($this->call('GET', '/barbero/proximas', null, $h))['citas']);

        $f = json_decode($this->call('GET', '/barbero/historial?estado=completada', null, $h)->body(), true);
        $this->assertSame([$r1['codigo']], array_column($f['data'], 'codigo'));
        $this->assertSame(1, $f['meta']['total']);
        $this->assertSame(422, $this->call('GET', '/barbero/historial?desde=2025-01-01&hasta=2026-12-31', null, $h)->status(), 'Máximo 366 días');
    }

    public function testScheduleIsReadOnlyUntilTheShopAllowsEditing(): void
    {
        $h = $this->actingAs(self::BARBERO1);
        $res = $this->call('GET', '/barbero/horario?dias=7', null, $h);
        $this->assertSame(200, $res->status(), $res->body());
        $s = $this->data($res);
        $this->assertCount(6, $s['semanal']['tramos']);
        $this->assertCount(6, $s['semanal']['descansos']);
        $this->assertCount(7, $s['dias']);
        $domingo = array_values(array_filter($s['dias'], static fn ($d) => $d['dia_semana'] === 7))[0];
        $this->assertSame([], $domingo['ventanas'], 'Los domingos no trabaja');
        $this->assertContains('Sede Demo Cercado', array_column($s['sucursales'], 'nombre'));
        $this->assertContains('solicitado', array_column($s['bloqueos'], 'estado'), 'Ve su solicitud pendiente');
        $this->assertFalse($s['ajustes']['puede_editar_horario']);

        $nuevo = ['tramos' => [['dia_semana' => 1, 'hora_inicio' => '10:00', 'hora_fin' => '18:00', 'sucursal_id' => $this->branchId('sede-cercado')]], 'descansos' => []];
        $res = $this->call('PUT', '/barbero/horario', $nuevo, $h);
        $this->assertSame(403, $res->status());
        $this->assertSame('schedule_locked', $this->errorCode($res));

        // El propietario habilita la edición (solo para su barbería).
        $res = $this->call('PUT', '/panel/equipo/ajustes', ['barbero_edita_horario' => true], $this->owner());
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertTrue($this->data($res)['barbero_edita_horario']);
        $this->assertSame(1, $this->auditCount('equipo.ajustes_actualizados', 'exito'));

        $h = $this->actingAs(self::BARBERO1);
        $res = $this->call('PUT', '/barbero/horario', $nuevo, $h);
        $this->assertSame(200, $res->status(), $res->body());
        $out = $this->data($res);
        $this->assertCount(1, $out['horario']['semanal']['tramos']);
        // Sus citas de mañana quedan fuera de la nueva jornada: se respetan y se avisa.
        $this->assertNotEmpty($out['advertencias']['reservas_fuera_de_jornada']);
        $this->assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM reservas WHERE barbero_id = ? AND estado IN ('pendiente','confirmada')", [$this->barberId('Barbero Uno Demo')]));

        // Las mismas reglas del panel: un tramo fuera del horario de la sede se rechaza.
        $res = $this->call('PUT', '/barbero/horario', ['tramos' => [['dia_semana' => 1, 'hora_inicio' => '06:00', 'hora_fin' => '10:00', 'sucursal_id' => $this->branchId('sede-cercado')]], 'descansos' => []], $h);
        $this->assertSame(422, $res->status());

        // El ajuste de demo-centro no afecta a demo-norte.
        $res = $this->call('PUT', '/barbero/horario', $nuevo, $this->actingAs('barbero1.norte@barberapp.test', 'demo-norte'));
        $this->assertSame('schedule_locked', $this->errorCode($res));
    }

    public function testAbsenceRequestNeedsApprovalAndDoesNotChangeAvailabilityUntilApproved(): void
    {
        $dia = $this->day(2, 22); // un martes dentro del horizonte de reserva (30 días)
        $h = $this->actingAs(self::BARBERO1);
        $antes = $this->hours($dia);
        $this->assertNotEmpty($antes);

        $res = $this->call('POST', '/barbero/ausencias', ['tipo' => 'vacaciones', 'dia_completo' => true, 'fecha_inicio' => $dia], $h);
        $this->assertSame(422, $res->status(), 'El motivo es obligatorio cuando requiere aprobación');
        $res = $this->call('POST', '/barbero/ausencias', ['tipo' => 'feriado', 'dia_completo' => true, 'fecha_inicio' => $dia, 'motivo' => 'x'], $h);
        $this->assertSame(422, $res->status(), 'Los feriados los define la barbería');

        $res = $this->call('POST', '/barbero/ausencias', ['tipo' => 'vacaciones', 'dia_completo' => true, 'fecha_inicio' => $dia, 'motivo' => 'Viaje familiar'], $h);
        $this->assertSame(201, $res->status(), $res->body());
        $b = $this->data($res);
        $this->assertSame('solicitado', $b['estado']);
        $this->assertSame('barbero', $b['alcance']);
        $this->assertSame([], $b['advertencias']['reservas_en_conflicto']);
        $this->assertSame($antes, $this->hours($dia), 'Una solicitud no quita horarios hasta aprobarse');
        $this->assertSame(1, $this->auditCount('bloqueo.solicitado', 'exito'));
        // Aviso interno a quienes pueden aprobar (propietario y administrador), no al barbero.
        $avisos = $this->db->prepare("SELECT usuario_id FROM notificaciones WHERE tipo = 'bloqueo_solicitado' AND CAST(JSON_EXTRACT(datos, '$.bloqueo_id') AS UNSIGNED) = ? ORDER BY usuario_id");
        $avisos->execute([$b['id']]);
        $ids = array_map('intval', $avisos->fetchAll(\PDO::FETCH_COLUMN));
        sort($ids);
        $esperados = [$this->userId('propietario.centro@barberapp.test'), $this->userId(self::ADMIN)];
        sort($esperados);
        $this->assertSame($esperados, $ids);

        // Otra solicitud que se cruza: 409.
        $res = $this->call('POST', '/barbero/ausencias', ['tipo' => 'ausencia', 'inicio' => $dia . 'T10:00', 'fin' => $dia . 'T12:00', 'motivo' => 'Doble'], $h);
        $this->assertSame(409, $res->status());
        $this->assertSame('block_overlaps', $this->errorCode($res));

        // Aprobada por el encargado: el día deja de ofrecerse y el barbero recibe aviso.
        $res = $this->call('POST', "/panel/bloqueos/{$b['id']}/aprobar", ['respuesta' => 'Buen viaje'], $this->owner());
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame('aprobado', $this->data($res)['estado']);
        $this->assertSame([], $this->hours($dia));
        $this->assertSame('Ausencia aprobada', $this->scalar("SELECT titulo FROM notificaciones WHERE tipo = 'bloqueo_aprobado' AND usuario_id = ?", [$this->userId(self::BARBERO1)]));

        $lista = $this->data($this->call('GET', '/barbero/ausencias', null, $this->actingAs(self::BARBERO1)));
        $mia = array_values(array_filter($lista['items'], static fn ($i) => $i['id'] === $b['id']))[0];
        $this->assertSame('Buen viaje', $mia['respuesta']);
        $this->assertNotNull($mia['respondido_en']);
        $this->assertSame(['vacaciones', 'ausencia', 'descanso', 'bloqueo'], $lista['tipos']);

        // Ya aprobada: el barbero no puede retirarla.
        $res = $this->call('DELETE', "/barbero/ausencias/{$b['id']}", null, $this->actingAs(self::BARBERO1));
        $this->assertSame(409, $res->status());
    }

    public function testRejectWithReasonWithdrawAndConflictWarnings(): void
    {
        $r1 = $this->demoBooking('confirmada');
        $fecha = $this->localDateOf((string) $r1['inicio_utc']);
        $h = $this->actingAs(self::BARBERO1);

        // Pide la mañana en que ya tiene citas: se acepta la solicitud, con advertencia.
        $res = $this->call('POST', '/barbero/ausencias', ['tipo' => 'ausencia', 'inicio' => $fecha . 'T09:00', 'fin' => $fecha . 'T12:00', 'motivo' => 'Cita médica'], $h);
        $this->assertSame(201, $res->status(), $res->body());
        $b = $this->data($res);
        $this->assertEqualsCanonicalizing([$r1['codigo'], $this->demoBooking('pendiente')['codigo']], array_column($b['advertencias']['reservas_en_conflicto'], 'codigo'));
        // El encargado no puede aprobarla sin antes mover esas citas.
        $res = $this->call('POST', "/panel/bloqueos/{$b['id']}/aprobar", null, $this->owner());
        $this->assertSame(409, $res->status());
        $this->assertSame('block_conflicts_bookings', $this->errorCode($res));
        // Rechazo con comentario: el barbero lo ve y recibe aviso.
        $res = $this->call('POST', "/panel/bloqueos/{$b['id']}/rechazar", ['respuesta' => 'Tienes dos clientes esa mañana'], $this->owner());
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame('rechazado', $this->data($res)['estado']);
        $this->assertSame(409, $this->call('POST', "/panel/bloqueos/{$b['id']}/rechazar", null, $this->owner())->status(), 'Ya atendida');
        $aviso = (string) $this->scalar("SELECT cuerpo FROM notificaciones WHERE tipo = 'bloqueo_rechazado' AND usuario_id = ?", [$this->userId(self::BARBERO1)]);
        $this->assertStringContainsString('Tienes dos clientes esa mañana', $aviso);

        // Una solicitud pendiente se puede retirar; la de otro barbero, no (404).
        $h = $this->actingAs(self::BARBERO1);
        $dia = $this->day(4, 30);
        $otra = $this->data($this->call('POST', '/barbero/ausencias', ['tipo' => 'descanso', 'inicio' => $dia . 'T15:00', 'fin' => $dia . 'T16:00', 'motivo' => 'Trámite'], $h));
        $this->assertSame(404, $this->call('DELETE', "/barbero/ausencias/{$otra['id']}", null, $this->actingAs(self::BARBERO2))->status());
        $this->assertSame(204, $this->call('DELETE', "/barbero/ausencias/{$otra['id']}", null, $this->actingAs(self::BARBERO1))->status());
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM bloqueos_agenda WHERE id = ?', [$otra['id']]));
        $this->assertSame(1, $this->auditCount('bloqueo.retirado', 'exito'));

        // Otra barbería no ve ni toca las solicitudes de esta.
        $this->assertSame(404, $this->call('POST', "/panel/bloqueos/{$b['id']}/aprobar", null, $this->actingAs('propietario.norte@barberapp.test', 'demo-norte'))->status());
    }

    public function testShopCanLetBarbersBlockWithoutApproval(): void
    {
        $this->assertSame(200, $this->call('PUT', '/panel/equipo/ajustes', ['ausencias_requieren_aprobacion' => false], $this->owner())->status());
        $this->assertSame(422, $this->call('PUT', '/panel/equipo/ajustes', ['ausencias_requieren_aprobacion' => 'quizá'], $this->owner())->status());
        $h = $this->actingAs(self::BARBERO1);
        $dia = $this->day(5, 22);
        $res = $this->call('POST', '/barbero/ausencias', ['tipo' => 'bloqueo', 'inicio' => $dia . 'T15:00', 'fin' => $dia . 'T17:00'], $h);
        $this->assertSame(201, $res->status(), $res->body());
        $this->assertSame('aprobado', $this->data($res)['estado'], 'Sin aprobación: queda bloqueado al instante');
        $this->assertNotContains('15:30', $this->hours($dia));
        $this->assertContains('17:00', $this->hours($dia));

        // Sin aprobación se aplican las reglas del panel: no se bloquea encima de citas activas.
        $r1 = $this->demoBooking('confirmada');
        $fecha = $this->localDateOf((string) $r1['inicio_utc']);
        $res = $this->call('POST', '/barbero/ausencias', ['tipo' => 'bloqueo', 'inicio' => $fecha . 'T09:30', 'fin' => $fecha . 'T10:30'], $h);
        $this->assertSame(409, $res->status());
        $this->assertSame('block_conflicts_bookings', $this->errorCode($res));
    }
}
