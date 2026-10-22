<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Tests\Integration\Auth\ApiTestCase;

/**
 * Fase 14 — integración de punta a punta, solo por la API y sin datos demo de la barbería:
 * registro → creación y aprobación de la barbería → sucursal, servicio y barbero con horario →
 * registro del cliente → disponibilidad → reserva → agenda del panel → reprogramación →
 * cancelación → avisos. Es el recorrido de los criterios de éxito del MVP.
 */
final class FullJourneyTest extends ApiTestCase
{
    public function testFromRegistrationToCancellation(): void
    {
        // 1) Una persona registra su barbería (app móvil) y la plataforma la aprueba.
        $reg = $this->call('POST', '/auth/register', [
            'tipo' => 'propietario', 'email' => 'duena.flujo@barberapp.test', 'password' => 'Navaja2026x', 'nombres' => 'Rosa',
            'acepta_terminos' => true, 'canal' => 'movil',
            'barberia' => ['nombre_comercial' => 'Barbería Flujo Completo', 'telefono' => '+51 999 111 222'],
        ]);
        $this->assertSame(201, $reg->status(), $reg->body());
        $barberia = $this->data($reg)['barberia'];
        $slug = (string) $barberia['slug'];
        $owner = ['Authorization' => 'Bearer ' . $this->data($reg)['access_token'], 'X-Tenant' => $slug];
        $this->assertSame('pendiente', $barberia['estado']);

        $super = ['Authorization' => 'Bearer ' . $this->loginMobile('superadmin@barberapp.test')['access_token']];
        $ok = $this->call('POST', "/plataforma/barberias/{$barberia['id']}/aprobar", [], $super);
        $this->assertSame(200, $ok->status(), $ok->body());
        $this->assertSame('activo', $this->scalar('SELECT estado FROM tenants WHERE id = ?', [$barberia['id']]));

        // 2) Configura sucursal (todos los días 09:00–20:00), un servicio de 45 min y un barbero.
        $suc = $this->ok($this->call('POST', '/panel/sucursales', ['nombre' => 'Sede Flujo', 'direccion' => 'Av. Prueba 123', 'distrito' => 'Miraflores'], $owner), 201);
        $horario = [];
        for ($d = 1; $d <= 7; $d++) {
            $horario[] = ['dia_semana' => $d, 'hora_apertura' => '09:00', 'hora_cierre' => '20:00'];
        }
        $this->ok($this->call('PUT', "/panel/sucursales/{$suc['id']}/horario", ['horario' => $horario], $owner));

        $srv = $this->ok($this->call('POST', '/panel/servicios', ['nombre' => 'Corte y barba', 'duracion_minutos' => 45, 'precio' => '45.00'], $owner), 201);
        $bar = $this->ok($this->call('POST', '/panel/barberos', ['nombre_publico' => 'Mario Tijeras', 'sucursal_id' => $suc['id']], $owner), 201);
        $this->ok($this->call('PUT', "/panel/barberos/{$bar['id']}/servicios", ['servicios' => [['servicio_id' => $srv['id']]]], $owner));
        $tramos = [];
        for ($d = 1; $d <= 7; $d++) {
            $tramos[] = ['dia_semana' => $d, 'hora_inicio' => '10:00', 'hora_fin' => '14:00', 'sucursal_id' => $suc['id']];
        }
        $this->ok($this->call('PUT', "/panel/barberos/{$bar['id']}/horario", ['tramos' => $tramos, 'descansos' => []], $owner));

        // 3) Un cliente nuevo se registra y verifica su correo.
        $this->cookies = [];
        $cli = $this->call('POST', '/auth/register', [
            'tipo' => 'cliente', 'email' => 'cliente.flujo@barberapp.test', 'password' => 'Fade2026corte', 'nombres' => 'Luis',
            'apellidos' => 'Flujo', 'telefono' => '+51911222333', 'acepta_terminos' => true, 'canal' => 'movil',
        ]);
        $this->assertSame(201, $cli->status(), $cli->body());
        $this->ok($this->call('POST', '/auth/verify-email', ['token' => $this->tokenFromLastMail()]));
        $client = ['Authorization' => 'Bearer ' . $this->data($cli)['access_token']];

        // 4) Consulta horarios por enlace directo (sin estar en el marketplace) y reserva.
        $fecha = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->modify('+3 day')->format('Y-m-d');
        $horas = $this->slots($slug, (int) $suc['id'], (int) $srv['id'], (int) $bar['id'], $fecha);
        // Jornada 10:00–14:00 con servicio de 45 min en grilla de 15: 10:00 … 13:15.
        $this->assertSame('10:00', $horas[0]);
        $this->assertSame('13:15', end($horas));

        $res = $this->call('POST', '/reservas', [
            'slug' => $slug, 'canal' => 'enlace', 'sucursal_id' => $suc['id'], 'servicio_ids' => [$srv['id']],
            'barbero_id' => $bar['id'], 'fecha' => $fecha, 'hora' => '10:00',
        ], $client + ['Idempotency-Key' => 'flujo-reserva-0001']);
        $this->assertSame(201, $res->status(), $res->body());
        $reserva = $this->data($res);
        $id = (int) $reserva['id'];
        $this->assertSame('confirmada', $reserva['estado'], 'Confirmación automática por defecto');
        $this->assertNotContains('10:00', $this->slots($slug, (int) $suc['id'], (int) $srv['id'], (int) $bar['id'], $fecha));
        $this->assertNotContains('10:30', $this->slots($slug, (int) $suc['id'], (int) $srv['id'], (int) $bar['id'], $fecha), 'Se solapa con 10:00–10:45');
        $this->assertGreaterThan(0, (int) $this->scalar("SELECT COUNT(*) FROM notificaciones WHERE reserva_id = ? AND tipo = 'recordatorio' AND estado = 'programada'", [$id]), 'Recordatorios programados');

        // 5) El propietario la ve en su agenda.
        $agenda = $this->call('GET', '/panel/agenda?fecha=' . $fecha, null, $owner);
        $this->assertSame(200, $agenda->status(), $agenda->body());
        $this->assertStringContainsString((string) $reserva['codigo'], $agenda->body());

        // 6) El cliente reprograma: el horario anterior se libera y el nuevo se ocupa.
        $this->ok($this->call('POST', "/mis-reservas/{$id}/reprogramar", ['fecha' => $fecha, 'hora' => '12:00'], $client + ['Idempotency-Key' => 'flujo-reprogramar-0001']));
        $libres = $this->slots($slug, (int) $suc['id'], (int) $srv['id'], (int) $bar['id'], $fecha);
        $this->assertContains('10:00', $libres);
        $this->assertNotContains('12:00', $libres);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM reserva_eventos WHERE reserva_id = ? AND tipo = 'reprogramada'", [$id]));

        // 7) El cliente cancela: todo el día vuelve a estar libre y los recordatorios se anulan.
        $this->ok($this->call('POST', "/mis-reservas/{$id}/cancelar", ['motivo' => 'Viaje'], $client));
        $this->assertSame($horas, $this->slots($slug, (int) $suc['id'], (int) $srv['id'], (int) $bar['id'], $fecha));
        $this->assertSame('cancelada', $this->scalar('SELECT estado FROM reservas WHERE id = ?', [$id]));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM notificaciones WHERE reserva_id = ? AND tipo = 'recordatorio' AND estado = 'programada'", [$id]));

        // 8) El horario liberado se puede volver a reservar (nueva clave de idempotencia).
        $res = $this->call('POST', '/reservas', [
            'slug' => $slug, 'canal' => 'enlace', 'sucursal_id' => $suc['id'], 'servicio_ids' => [$srv['id']],
            'barbero_id' => $bar['id'], 'fecha' => $fecha, 'hora' => '12:00',
        ], $client + ['Idempotency-Key' => 'flujo-reserva-0002']);
        $this->assertSame(201, $res->status(), $res->body());

        // 9) Historial y auditoría reales.
        $mias = $this->ok($this->call('GET', '/mis-reservas', null, $client));
        $this->assertNotEmpty($mias);
        foreach (['reserva.creada', 'reserva.cancelada'] as $accion) {
            $this->assertGreaterThan(0, (int) $this->scalar('SELECT COUNT(*) FROM auditoria WHERE accion = ? AND tenant_id = ?', [$accion, $barberia['id']]), $accion);
        }
    }

    /** @return list<string> */
    private function slots(string $slug, int $sucursal, int $servicio, int $barbero, string $fecha): array
    {
        $res = $this->call('GET', '/disponibilidad?' . http_build_query([
            'slug' => $slug, 'sucursal_id' => $sucursal, 'servicio_ids' => (string) $servicio, 'barbero_id' => $barbero, 'fecha' => $fecha,
        ]));
        $this->assertSame(200, $res->status(), $res->body());
        return array_column($this->data($res)['horarios'], 'hora');
    }

    /** @return array<string, mixed> */
    private function ok(\App\Core\Response $res, int $status = 200): array
    {
        $this->assertSame($status, $res->status(), $res->body());
        return $this->data($res);
    }
}
