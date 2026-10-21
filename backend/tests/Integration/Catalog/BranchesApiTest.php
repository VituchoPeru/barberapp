<?php

declare(strict_types=1);

namespace Tests\Integration\Catalog;

/** Sucursales y horario comercial (Fase 4). */
final class BranchesApiTest extends CatalogTestCase
{
    public function testOwnerCreatesBranchWithUniqueSlugAndHours(): void
    {
        $h = $this->owner();
        $body = ['nombre' => 'Sede San Isidro', 'direccion' => 'Av. Javier Prado 100', 'distrito' => 'San Isidro', 'latitud' => -12.0931, 'longitud' => -77.0465, 'telefono' => '01 234 5678'];
        $res = $this->call('POST', '/panel/sucursales', $body, $h);
        $this->assertSame(201, $res->status(), $res->body());
        $s = $this->data($res);
        $this->assertSame('sede-san-isidro', $s['slug']);
        $this->assertSame('America/Lima', $s['zona_horaria'], 'Hereda la zona horaria de la barbería');
        $this->assertSame('012345678', $s['telefono']);
        $this->assertFalse($s['es_principal']);
        $this->assertSame([], $s['horario']);

        $again = $this->data($this->call('POST', '/panel/sucursales', $body, $h));
        $this->assertSame('sede-san-isidro-2', $again['slug']);

        $res = $this->call('PUT', "/panel/sucursales/{$s['id']}/horario", ['horario' => [
            ['dia_semana' => 1, 'hora_apertura' => '10:00', 'hora_cierre' => '14:00'],
            ['dia_semana' => 1, 'hora_apertura' => '15:00', 'hora_cierre' => '21:00'],
            ['dia_semana' => 6, 'hora_apertura' => '09:00', 'hora_cierre' => '18:00'],
        ]], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertCount(3, $this->data($res)['horario']);

        $res = $this->call('PUT', "/panel/sucursales/{$s['id']}/horario", ['horario' => [
            ['dia_semana' => 1, 'hora_apertura' => '10:00', 'hora_cierre' => '14:00'],
            ['dia_semana' => 1, 'hora_apertura' => '13:00', 'hora_cierre' => '21:00'],
            ['dia_semana' => 2, 'hora_apertura' => '18:00', 'hora_cierre' => '09:00'],
        ]], $h);
        $this->assertSame(422, $res->status());
        $this->assertEqualsCanonicalizing(['horario.1', 'horario.2'], array_keys($res->decoded()['error']['details']['fields']));

        // Marcar como principal desmarca la anterior.
        $res = $this->call('PUT', "/panel/sucursales/{$s['id']}", ['es_principal' => true], $h);
        $this->assertTrue($this->data($res)['es_principal']);
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM sucursales WHERE tenant_id = ? AND es_principal = 1', [$this->tenantId('demo-centro')]));
    }

    public function testValidation(): void
    {
        $h = $this->owner();
        $base = ['nombre' => 'Sede', 'direccion' => 'Calle 1', 'distrito' => 'Lince'];
        $cases = [
            ['longitud', ['latitud' => -12.1] + $base],
            ['latitud', ['latitud' => 95, 'longitud' => -77] + $base],
            ['zona_horaria', ['zona_horaria' => 'Lima/Peru'] + $base],
            ['email', ['email' => 'no-es-correo'] + $base],
            ['ubigeo', ['ubigeo' => '15'] + $base],
            ['direccion', ['direccion' => ''] + $base],
        ];
        foreach ($cases as [$field, $body]) {
            $res = $this->call('POST', '/panel/sucursales', $body, $h);
            $this->assertSame(422, $res->status(), $field . ': ' . $res->body());
            $this->assertArrayHasKey($field, $res->decoded()['error']['details']['fields']);
        }
    }

    public function testNarrowingHoursWarnsAboutBarberShifts(): void
    {
        $h = $this->owner();
        $cercado = $this->branchId('sede-cercado');
        $horario = [];
        for ($d = 1; $d <= 6; $d++) {
            $horario[] = ['dia_semana' => $d, 'hora_apertura' => '10:00', 'hora_cierre' => '18:00'];
        }
        $res = $this->call('PUT', "/panel/sucursales/{$cercado}/horario", ['horario' => $horario], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $warn = $res->decoded()['meta']['advertencias']['jornadas_fuera_de_horario'];
        $this->assertContains('Barbero Uno Demo', array_column($warn, 'barbero'));
        $this->assertContains('Barbero Dos Demo', array_column($warn, 'barbero'));

        // La jornada efectiva se recorta al nuevo horario comercial automáticamente.
        $uno = $this->barberId('Barbero Uno Demo');
        $lunes = $this->nextLocal(1);
        $j = $this->data($this->call('GET', "/panel/barberos/{$uno}/jornada?desde={$lunes}&hasta={$lunes}", null, $h));
        $this->assertSame([['10:00', '13:00'], ['14:00', '18:00']], array_map(static fn ($w) => [$w['inicio_local'], $w['fin_local']], $j['ventanas']));
    }

    public function testDeactivateAndDeleteRules(): void
    {
        $h = $this->owner();
        $cercado = $this->branchId('sede-cercado');
        $res = $this->call('PUT', "/panel/sucursales/{$cercado}", ['activo' => false], $h);
        $this->assertSame(409, $res->status());
        $this->assertSame('branch_has_barbers', $this->errorCode($res));
        $this->assertSame(409, $this->call('DELETE', "/panel/sucursales/{$cercado}", null, $h)->status());

        // Miraflores solo tiene un barbero inactivo y sin reservas: se puede desactivar y eliminar.
        $mira = $this->branchId('sede-miraflores');
        $res = $this->call('PUT', "/panel/sucursales/{$mira}", ['activo' => false], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertFalse($this->data($res)['activo']);
        $this->cookies = [];
        $pub = $this->data($this->call('GET', '/marketplace/barberias/demo-centro'));
        $this->assertNotContains('Sede Demo Miraflores', array_column($pub['sucursales'], 'nombre'));

        $h = $this->owner();
        // No se puede elegir una sucursal desactivada para un barbero.
        $res = $this->call('POST', '/panel/barberos', ['nombre_publico' => 'Luis', 'sucursal_id' => $mira], $h);
        $this->assertSame(422, $res->status());

        $this->assertSame(204, $this->call('DELETE', "/panel/sucursales/{$mira}", null, $h)->status());
        $this->assertSame(404, $this->call('GET', "/panel/sucursales/{$mira}", null, $h)->status());
        $this->assertNotContains($mira, array_column($this->data($this->call('GET', '/panel/sucursales', null, $h)), 'id'));
    }

    public function testBranchUsedInAShiftCannotBeDeleted(): void
    {
        $h = $this->owner();
        $mira = $this->branchId('sede-miraflores');
        $dos = $this->barberId('Barbero Dos Demo');
        $res = $this->call('PUT', "/panel/barberos/{$dos}/horario", ['tramos' => [['dia_semana' => 1, 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'sucursal_id' => $mira]]], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $res = $this->call('DELETE', "/panel/sucursales/{$mira}", null, $h);
        $this->assertSame(409, $res->status());
        $this->assertSame('branch_in_schedules', $this->errorCode($res));
    }

    public function testPermissionsAndIsolation(): void
    {
        $cercado = $this->branchId('sede-cercado');
        $admin = $this->actingAs('admin.centro@barberapp.test');
        $this->assertSame(200, $this->call('GET', '/panel/sucursales', null, $admin)->status(), 'El administrador consulta sucursales para asignar horarios');
        $this->assertSame(403, $this->call('POST', '/panel/sucursales', ['nombre' => 'X', 'direccion' => 'Y 1', 'distrito' => 'Z'], $admin)->status());
        $this->assertSame(403, $this->call('PUT', "/panel/sucursales/{$cercado}/horario", ['horario' => []], $admin)->status());

        $barber = $this->actingAs('barbero1.centro@barberapp.test');
        $this->assertSame(403, $this->call('GET', '/panel/sucursales', null, $barber)->status());

        $norte = $this->actingAs('propietario.norte@barberapp.test', 'demo-norte');
        $this->assertSame(404, $this->call('GET', "/panel/sucursales/{$cercado}", null, $norte)->status());
        $this->assertSame(404, $this->call('PUT', "/panel/sucursales/{$cercado}", ['nombre' => 'Hackeada'], $norte)->status());
        $this->assertSame(404, $this->call('PUT', "/panel/sucursales/{$cercado}/horario", ['horario' => []], $norte)->status());
        $this->assertSame('Sede Demo Cercado', $this->scalar('SELECT nombre FROM sucursales WHERE id = ?', [$cercado]));
        $this->assertSame(6, (int) $this->scalar('SELECT COUNT(*) FROM horarios_sucursales WHERE sucursal_id = ?', [$cercado]));
        $this->assertSame(['Sede Demo Los Olivos'], array_column($this->data($this->call('GET', '/panel/sucursales', null, $norte)), 'nombre'));
    }
}
