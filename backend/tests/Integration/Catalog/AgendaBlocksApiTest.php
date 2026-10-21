<?php

declare(strict_types=1);

namespace Tests\Integration\Catalog;

use App\Helpers\Clock;
use DateTimeImmutable;
use DateTimeZone;

/** Vacaciones, ausencias, feriados y bloqueos de agenda (Fase 4). */
final class AgendaBlocksApiTest extends CatalogTestCase
{
    public function testFullDayVacationRemovesWorkingTimeAndDeletingRestoresIt(): void
    {
        $h = $this->owner();
        $uno = $this->barberId('Barbero Uno Demo');
        $lunes = $this->nextLocal(1, 30);
        $martes = (new DateTimeImmutable($lunes))->modify('+1 day')->format('Y-m-d');
        $res = $this->call('POST', '/panel/bloqueos', [
            'tipo' => 'vacaciones', 'barbero_id' => $uno, 'dia_completo' => true, 'fecha_inicio' => $lunes, 'fecha_fin' => $martes, 'motivo' => 'Viaje',
        ], $h);
        $this->assertSame(201, $res->status(), $res->body());
        $b = $this->data($res);
        $this->assertSame('aprobado', $b['estado']);
        $this->assertTrue($b['dia_completo']);
        $this->assertSame([$lunes, $martes], [$b['fecha_inicio'], $b['fecha_fin']]);
        $this->assertSame($lunes . 'T00:00', $b['inicio_local']);
        $this->assertSame($lunes . 'T05:00:00Z', $b['inicio_utc'], 'Medianoche de Lima = 05:00 UTC');
        $this->assertSame('barbero', $b['alcance']);

        $miercoles = (new DateTimeImmutable($martes))->modify('+1 day')->format('Y-m-d');
        $j = $this->data($this->call('GET', "/panel/barberos/{$uno}/jornada?desde={$lunes}&hasta={$miercoles}", null, $h));
        $this->assertSame([$miercoles], array_values(array_unique(array_column($j['ventanas'], 'fecha'))));

        $list = $this->data($this->call('GET', "/panel/bloqueos?barbero_id={$uno}", null, $h));
        $this->assertContains($b['id'], array_column($list, 'id'));

        $this->assertSame(204, $this->call('DELETE', "/panel/bloqueos/{$b['id']}", null, $h)->status());
        $j = $this->data($this->call('GET', "/panel/barberos/{$uno}/jornada?desde={$lunes}&hasta={$lunes}", null, $h));
        $this->assertCount(2, $j['ventanas'], 'El intervalo vuelve a ofrecerse');
        $this->assertSame(1, $this->auditCount('bloqueo.eliminado', 'exito'));
    }

    public function testPartialBlockAndBranchWideHoliday(): void
    {
        $h = $this->owner();
        $dos = $this->barberId('Barbero Dos Demo');
        $jueves = $this->nextLocal(4, 30);
        $res = $this->call('POST', '/panel/bloqueos', ['tipo' => 'ausencia', 'barbero_id' => $dos, 'inicio' => "{$jueves}T15:00", 'fin' => "{$jueves}T17:30"], $h);
        $this->assertSame(201, $res->status(), $res->body());
        $this->assertFalse($this->data($res)['dia_completo']);
        $j = $this->data($this->call('GET', "/panel/barberos/{$dos}/jornada?desde={$jueves}&hasta={$jueves}", null, $h));
        $this->assertSame([['09:00', '13:00'], ['14:00', '15:00'], ['17:30', '20:00']], array_map(static fn ($w) => [$w['inicio_local'], $w['fin_local']], $j['ventanas']));

        // Feriado en toda la sucursal Cercado: afecta a todos sus barberos.
        $viernes = (new DateTimeImmutable($jueves))->modify('+1 day')->format('Y-m-d');
        $res = $this->call('POST', '/panel/bloqueos', ['tipo' => 'feriado', 'sucursal_id' => $this->branchId('sede-cercado'), 'dia_completo' => true, 'fecha_inicio' => $viernes], $h);
        $this->assertSame(201, $res->status(), $res->body());
        $this->assertSame('sucursal', $this->data($res)['alcance']);
        foreach ([$dos, $this->barberId('Barbero Uno Demo')] as $id) {
            $j = $this->data($this->call('GET', "/panel/barberos/{$id}/jornada?desde={$viernes}&hasta={$viernes}", null, $h));
            $this->assertSame([], $j['ventanas']);
        }
    }

    public function testBlockOverActiveBookingsIsRejected(): void
    {
        $h = $this->owner();
        $uno = $this->barberId('Barbero Uno Demo');
        $fecha = $this->bookingLocalDate($uno);
        $res = $this->call('POST', '/panel/bloqueos', ['tipo' => 'bloqueo', 'barbero_id' => $uno, 'dia_completo' => true, 'fecha_inicio' => $fecha], $h);
        $this->assertSame(409, $res->status());
        $this->assertSame('block_conflicts_bookings', $this->errorCode($res));
        $this->assertCount(2, $res->decoded()['error']['details']['reservas']);

        // Toda la barbería ese día: también choca (y el barbero 3 de Norte no cuenta).
        $res = $this->call('POST', '/panel/bloqueos', ['tipo' => 'feriado', 'dia_completo' => true, 'fecha_inicio' => $fecha], $h);
        $this->assertSame(409, $res->status());

        // Una hora sin reservas ese mismo día sí se puede bloquear.
        $res = $this->call('POST', '/panel/bloqueos', ['tipo' => 'descanso', 'barbero_id' => $uno, 'inicio' => "{$fecha}T17:00", 'fin' => "{$fecha}T18:00"], $h);
        $this->assertSame(201, $res->status(), $res->body());
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM bloqueos_agenda WHERE tipo IN ('bloqueo','feriado') AND barbero_id <=> ?", [$uno]));
    }

    public function testPendingRequestsAreApprovedOrRejected(): void
    {
        $h = $this->owner();
        $pend = $this->data($this->call('GET', '/panel/bloqueos?estado=solicitado', null, $h));
        $this->assertCount(1, $pend);
        $this->assertSame('Barbero Uno Demo', $pend[0]['barbero']);
        $this->assertSame('Barbero Uno Demo', $pend[0]['creado_por']);
        $id = $pend[0]['id'];

        $res = $this->call('POST', "/panel/bloqueos/{$id}/aprobar", null, $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame('aprobado', $this->data($res)['estado']);
        $this->assertSame('Propietario Demo Centro', $this->data($res)['aprobado_por']);
        $this->assertSame('block_not_pending', $this->errorCode($this->call('POST', "/panel/bloqueos/{$id}/aprobar", null, $h)));
        $this->assertSame(409, $this->call('POST', "/panel/bloqueos/{$id}/rechazar", null, $h)->status());

        // Una solicitud rechazada no bloquea la agenda.
        $uno = $this->barberId('Barbero Uno Demo');
        $ini = Clock::localToUtc($this->nextLocal(3, 40) . ' 10:00:00', 'America/Lima');
        $this->exec("INSERT INTO bloqueos_agenda (tenant_id, barbero_id, tipo, inicio_utc, fin_utc, estado) VALUES (?, ?, 'ausencia', ?, ?, 'solicitado')",
            [$this->tenantId('demo-centro'), $uno, $ini->format('Y-m-d H:i:s'), $ini->modify('+2 hours')->format('Y-m-d H:i:s')]);
        $id2 = (int) $this->db->lastInsertId();
        $this->assertSame('rechazado', $this->data($this->call('POST', "/panel/bloqueos/{$id2}/rechazar", null, $h))['estado']);
        $fecha = $ini->setTimezone(new DateTimeZone('America/Lima'))->format('Y-m-d');
        $j = $this->data($this->call('GET', "/panel/barberos/{$uno}/jornada?desde={$fecha}&hasta={$fecha}", null, $h));
        $this->assertSame('09:00', $j['ventanas'][0]['inicio_local']);
        $this->assertSame('13:00', $j['ventanas'][0]['fin_local']);
    }

    public function testValidation(): void
    {
        $h = $this->owner();
        $uno = $this->barberId('Barbero Uno Demo');
        $ayer = $this->localDate(-1);
        $cases = [
            ['tipo', ['tipo' => 'fiesta', 'dia_completo' => true, 'fecha_inicio' => $this->localDate(40)]],
            ['fin', ['tipo' => 'bloqueo', 'barbero_id' => $uno, 'dia_completo' => true, 'fecha_inicio' => $ayer]],
            ['fin', ['tipo' => 'bloqueo', 'barbero_id' => $uno, 'inicio' => $this->localDate(40) . 'T12:00', 'fin' => $this->localDate(40) . 'T11:00']],
            ['fecha_fin', ['tipo' => 'vacaciones', 'barbero_id' => $uno, 'dia_completo' => true, 'fecha_inicio' => $this->localDate(40), 'fecha_fin' => $this->localDate(39)]],
            ['fin', ['tipo' => 'vacaciones', 'barbero_id' => $uno, 'dia_completo' => true, 'fecha_inicio' => $this->localDate(40), 'fecha_fin' => $this->localDate(500)]],
            ['inicio', ['tipo' => 'bloqueo', 'barbero_id' => $uno]],
            ['barbero_id', ['tipo' => 'bloqueo', 'barbero_id' => $this->barberId('Barbero Tres Demo'), 'dia_completo' => true, 'fecha_inicio' => $this->localDate(40)]],
        ];
        foreach ($cases as $i => [$field, $body]) {
            $res = $this->call('POST', '/panel/bloqueos', $body, $h);
            $this->assertSame(422, $res->status(), "caso {$i}: " . $res->body());
            $this->assertArrayHasKey($field, $res->decoded()['error']['details']['fields'], "caso {$i}");
        }
    }

    public function testPermissionsAndIsolation(): void
    {
        $id = (int) $this->scalar("SELECT id FROM bloqueos_agenda WHERE estado = 'solicitado' LIMIT 1");
        $norte = $this->actingAs('propietario.norte@barberapp.test', 'demo-norte');
        $this->assertSame(404, $this->call('POST', "/panel/bloqueos/{$id}/aprobar", null, $norte)->status());
        $this->assertSame(404, $this->call('DELETE', "/panel/bloqueos/{$id}", null, $norte)->status());
        $this->assertSame([], $this->data($this->call('GET', '/panel/bloqueos?estado=solicitado', null, $norte)));
        $this->assertSame('solicitado', $this->scalar('SELECT estado FROM bloqueos_agenda WHERE id = ?', [$id]));

        $barber = $this->actingAs('barbero1.centro@barberapp.test');
        $this->assertSame(403, $this->call('POST', "/panel/bloqueos/{$id}/aprobar", null, $barber)->status(), 'El barbero no aprueba sus propias solicitudes');
        $this->assertSame(403, $this->call('GET', '/panel/bloqueos', null, $barber)->status());

        $admin = $this->actingAs('admin.centro@barberapp.test');
        $this->assertSame(200, $this->call('POST', "/panel/bloqueos/{$id}/aprobar", null, $admin)->status());
    }
}
