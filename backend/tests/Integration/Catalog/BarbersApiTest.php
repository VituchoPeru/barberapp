<?php

declare(strict_types=1);

namespace Tests\Integration\Catalog;

use App\Core\Config;

/** Barberos, servicios habilitados, jornada semanal, descansos y fotos (Fase 4). */
final class BarbersApiTest extends CatalogTestCase
{
    /** @return list<array<string, mixed>> lunes a sábado con el horario indicado */
    private function week(int $sucursal, string $ini = '09:00', string $fin = '20:00'): array
    {
        $out = [];
        for ($d = 1; $d <= 6; $d++) {
            $out[] = ['dia_semana' => $d, 'hora_inicio' => $ini, 'hora_fin' => $fin, 'sucursal_id' => $sucursal];
        }
        return $out;
    }

    public function testOwnerRegistersBarberWithServicesAndSchedule(): void
    {
        $h = $this->owner();
        $cercado = $this->branchId('sede-cercado');
        $res = $this->call('POST', '/panel/barberos', [
            'nombre_publico' => 'Carlos Navaja', 'especialidad' => 'Afeitado clásico', 'biografia' => 'Diez años de oficio.', 'sucursal_id' => $cercado,
        ], $h);
        $this->assertSame(201, $res->status(), $res->body());
        $b = $this->data($res);
        $this->assertTrue($b['activo']);
        $this->assertSame([], $b['servicios']);
        $this->assertSame([], $b['horario']);

        $corte = $this->serviceId('Corte clásico');
        $barba = $this->serviceId('Perfilado de barba');
        $res = $this->call('PUT', "/panel/barberos/{$b['id']}/servicios", ['servicios' => [
            ['servicio_id' => $corte],
            ['servicio_id' => $barba, 'duracion_minutos' => 25, 'precio' => '18'],
        ]], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $srv = array_column($this->data($res)['servicios'], null, 'servicio_id');
        $this->assertSame(30, $srv[$corte]['duracion_minutos'], 'Sin override usa la duración estándar');
        $this->assertSame(25, $srv[$barba]['duracion_minutos']);
        $this->assertSame('18.00', $srv[$barba]['precio']);
        $this->assertSame('15.00', $srv[$barba]['precio_estandar']);

        $res = $this->call('PUT', "/panel/barberos/{$b['id']}/horario", [
            'tramos' => $this->week($cercado, '10:00', '19:00'),
            'descansos' => [['dia_semana' => 1, 'hora_inicio' => '13:00', 'hora_fin' => '14:00', 'motivo' => 'Almuerzo']],
        ], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertCount(6, $this->data($res)['horario']);
        $this->assertSame([], $res->decoded()['meta']['advertencias']['reservas_fuera_de_jornada']);

        $lunes = $this->nextLocal(1);
        $j = $this->data($this->call('GET', "/panel/barberos/{$b['id']}/jornada?desde={$lunes}&hasta={$lunes}", null, $h));
        $this->assertSame([['10:00', '13:00'], ['14:00', '19:00']], array_map(static fn ($w) => [$w['inicio_local'], $w['fin_local']], $j['ventanas']));
        $this->assertSame('Sede Demo Cercado', $j['ventanas'][0]['sucursal']);
        $this->assertSame(480, $j['minutos_totales']);

        // Ya aparece en el perfil público con sus servicios.
        $this->cookies = [];
        $pub = $this->data($this->call('GET', '/marketplace/barberias/demo-centro'));
        $carlos = array_values(array_filter($pub['barberos'], static fn ($x) => $x['nombre'] === 'Carlos Navaja'))[0] ?? null;
        $this->assertNotNull($carlos);
        $this->assertSame('Afeitado clásico', $carlos['especialidad']);
        $this->assertCount(2, $carlos['servicios']);
    }

    public function testScheduleRules(): void
    {
        $h = $this->owner();
        $id = $this->barberId('Barbero Dos Demo');
        $cercado = $this->branchId('sede-cercado');
        $miraflores = $this->branchId('sede-miraflores');

        // Mismo día en dos sucursales con horas cruzadas.
        $res = $this->call('PUT', "/panel/barberos/{$id}/horario", ['tramos' => [
            ['dia_semana' => 2, 'hora_inicio' => '09:00', 'hora_fin' => '14:00', 'sucursal_id' => $cercado],
            ['dia_semana' => 2, 'hora_inicio' => '13:00', 'hora_fin' => '20:00', 'sucursal_id' => $miraflores],
        ]], $h);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('dos sucursales', $res->decoded()['error']['details']['fields']['tramos.1'][0]);

        // Fuera del horario comercial (abre 09:00) y en domingo (cerrado).
        $res = $this->call('PUT', "/panel/barberos/{$id}/horario", ['tramos' => [
            ['dia_semana' => 1, 'hora_inicio' => '07:00', 'hora_fin' => '12:00', 'sucursal_id' => $cercado],
            ['dia_semana' => 7, 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'sucursal_id' => $cercado],
        ]], $h);
        $this->assertSame(422, $res->status());
        $this->assertEqualsCanonicalizing(['tramos.0', 'tramos.1'], array_keys($res->decoded()['error']['details']['fields']));

        // Sucursal de otra barbería.
        $norte = $this->branchId('sede-los-olivos', 'demo-norte');
        $res = $this->call('PUT', "/panel/barberos/{$id}/horario", ['tramos' => [['dia_semana' => 1, 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'sucursal_id' => $norte]]], $h);
        $this->assertSame(422, $res->status());

        // Nada se guardó tras los rechazos.
        $this->assertSame(12, (int) $this->scalar('SELECT COUNT(*) FROM horarios_barberos WHERE barbero_id = ?', [$id]));

        // Dos sucursales en el mismo día SIN cruce: válido.
        $res = $this->call('PUT', "/panel/barberos/{$id}/horario", ['tramos' => [
            ['dia_semana' => 2, 'hora_inicio' => '09:00', 'hora_fin' => '13:00', 'sucursal_id' => $cercado],
            ['dia_semana' => 2, 'hora_inicio' => '14:00', 'hora_fin' => '20:00', 'sucursal_id' => $miraflores],
        ]], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame(1, $this->auditCount('barbero.horario_actualizado', 'exito'));
    }

    public function testChangingScheduleWarnsAboutBookingsOutsideIt(): void
    {
        $h = $this->owner();
        $id = $this->barberId('Barbero Uno Demo'); // reservas mañana 10:00 y 11:00
        $dia = (int) (new \DateTimeImmutable($this->bookingLocalDate($id)))->format('N');
        $tramos = [];
        for ($d = 1; $d <= 6; $d++) {
            $tramos[] = ['dia_semana' => $d, 'hora_inicio' => $d === $dia ? '14:00' : '09:00', 'hora_fin' => '20:00', 'sucursal_id' => $this->branchId('sede-cercado')];
        }
        $res = $this->call('PUT', "/panel/barberos/{$id}/horario", ['tramos' => $tramos], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $fuera = $res->decoded()['meta']['advertencias']['reservas_fuera_de_jornada'];
        $this->assertCount(2, $fuera, 'Las dos reservas de la mañana quedan fuera, pero se conservan');
        $this->assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM reservas WHERE barbero_id = ? AND estado IN ('pendiente','confirmada')", [$id]));
        // Sin descanso: los descansos anteriores se reemplazan.
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM descansos_barberos WHERE barbero_id = ?', [$id]));
    }

    public function testWorkingTimeHonoursBreaksAndApprovedVacations(): void
    {
        $h = $this->owner();
        $uno = $this->barberId('Barbero Uno Demo');
        $lunes = $this->nextLocal(1);
        $j = $this->data($this->call('GET', "/panel/barberos/{$uno}/jornada?desde={$lunes}&hasta={$lunes}", null, $h));
        $this->assertSame([['09:00', '13:00'], ['14:00', '20:00']], array_map(static fn ($w) => [$w['inicio_local'], $w['fin_local']], $j['ventanas']), 'Descanso de almuerzo 13–14');

        // Barbero Dos tiene vacaciones aprobadas (demo) dentro de 14 y 15 días.
        $dos = $this->barberId('Barbero Dos Demo');
        $desde = $this->localDate(13);
        $hasta = $this->localDate(16);
        $j = $this->data($this->call('GET', "/panel/barberos/{$dos}/jornada?desde={$desde}&hasta={$hasta}", null, $h));
        $fechas = array_unique(array_column($j['ventanas'], 'fecha'));
        $this->assertNotContains($this->localDate(14), $fechas);
        $this->assertNotContains($this->localDate(15), $fechas);
        $this->assertNotEmpty($j['bloqueos']);

        $this->assertSame(422, $this->call('GET', "/panel/barberos/{$dos}/jornada?desde={$hasta}&hasta={$desde}", null, $h)->status());
        $this->assertSame(422, $this->call('GET', "/panel/barberos/{$dos}/jornada?desde={$desde}&hasta=" . $this->localDate(100), null, $h)->status());
    }

    public function testValidationAndAccountLinking(): void
    {
        $h = $this->owner();
        $norte = $this->branchId('sede-los-olivos', 'demo-norte');
        $res = $this->call('POST', '/panel/barberos', ['nombre_publico' => 'X', 'sucursal_id' => $norte], $h);
        $this->assertSame(422, $res->status());
        $this->assertArrayHasKey('nombre_publico', $res->decoded()['error']['details']['fields']);
        $res = $this->call('POST', '/panel/barberos', ['nombre_publico' => 'Pedro', 'sucursal_id' => $norte], $h);
        $this->assertSame(422, $res->status(), 'Sucursal de otra barbería');
        $this->assertArrayHasKey('sucursal_id', $res->decoded()['error']['details']['fields']);

        // Cuentas: cliente (no es del equipo) y barbero ya vinculado.
        $cercado = $this->branchId('sede-cercado');
        $res = $this->call('POST', '/panel/barberos', ['nombre_publico' => 'Pedro', 'sucursal_id' => $cercado, 'usuario_id' => $this->userId('cliente1@barberapp.test')], $h);
        $this->assertSame(422, $res->status());
        $res = $this->call('POST', '/panel/barberos', ['nombre_publico' => 'Pedro', 'sucursal_id' => $cercado, 'usuario_id' => $this->userId('barbero1.centro@barberapp.test')], $h);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('vinculada', $res->decoded()['error']['details']['fields']['usuario_id'][0]);

        // Invitado con rol barbero aparece como vinculable y se puede asociar.
        $inv = $this->call('POST', '/panel/personal', ['email' => 'nuevo.barbero@barberapp.test', 'rol' => 'barbero', 'nombres' => 'Nuevo Barbero'], $h);
        $this->assertSame(201, $inv->status(), $inv->body());
        $list = $this->call('GET', '/panel/barberos', null, $h);
        $cuentas = $list->decoded()['meta']['cuentas_vinculables'];
        $this->assertSame(['nuevo.barbero@barberapp.test'], array_column($cuentas, 'email'));
        $res = $this->call('POST', '/panel/barberos', ['nombre_publico' => 'Nuevo Barbero', 'sucursal_id' => $cercado, 'usuario_id' => $cuentas[0]['usuario_id']], $h);
        $this->assertSame(201, $res->status(), $res->body());
        $this->assertSame('nuevo.barbero@barberapp.test', $this->data($res)['usuario_email']);

        // Servicios: repetidos, de otra barbería, override negativo.
        $id = $this->data($res)['id'];
        $corte = $this->serviceId('Corte clásico');
        $res = $this->call('PUT', "/panel/barberos/{$id}/servicios", ['servicios' => [
            ['servicio_id' => $corte], ['servicio_id' => $corte], ['servicio_id' => $this->serviceId('Corte moderno')], ['servicio_id' => $this->serviceId('Corte y barba'), 'precio' => -5],
        ]], $h);
        $this->assertSame(422, $res->status());
        $this->assertEqualsCanonicalizing(['servicios.1', 'servicios.2', 'servicios.3'], array_keys($res->decoded()['error']['details']['fields']));
    }

    public function testDeactivationIsBlockedByFutureBookings(): void
    {
        $h = $this->owner();
        $uno = $this->barberId('Barbero Uno Demo');
        $res = $this->call('PUT', "/panel/barberos/{$uno}", ['activo' => false], $h);
        $this->assertSame(409, $res->status());
        $this->assertSame('barber_has_bookings', $this->errorCode($res));
        $this->assertCount(2, $res->decoded()['error']['details']['reservas']);
        $this->assertSame(409, $this->call('DELETE', "/panel/barberos/{$uno}", null, $h)->status());

        // Dejar de recibir reservas online sí se permite.
        $res = $this->call('PUT', "/panel/barberos/{$uno}", ['acepta_reservas_online' => false], $h);
        $this->assertSame(200, $res->status());
        $this->assertFalse($this->data($res)['acepta_reservas_online']);

        // Barbero Dos: solo reservas canceladas o pasadas → se puede desactivar y eliminar.
        $dos = $this->barberId('Barbero Dos Demo');
        $res = $this->call('PUT', "/panel/barberos/{$dos}", ['activo' => false], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame(204, $this->call('DELETE', "/panel/barberos/{$dos}", null, $h)->status());
        $this->assertSame(404, $this->call('GET', "/panel/barberos/{$dos}", null, $h)->status());
        $this->assertNull($this->scalar('SELECT usuario_id FROM barberos WHERE id = ?', [$dos]), 'La cuenta queda libre');
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM reservas WHERE barbero_id = ? AND estado = ?', [$dos, 'completada']), 'El historial se conserva');
    }

    public function testPermissionsAndIsolation(): void
    {
        $uno = $this->barberId('Barbero Uno Demo');
        $cercado = $this->branchId('sede-cercado');

        // El administrador configura horarios pero no edita fichas de barberos.
        $admin = $this->actingAs('admin.centro@barberapp.test');
        $this->assertSame(200, $this->call('GET', '/panel/barberos', null, $admin)->status());
        $this->assertSame(200, $this->call('PUT', "/panel/barberos/{$uno}/horario", ['tramos' => $this->week($cercado)], $admin)->status());
        $this->assertSame(403, $this->call('PUT', "/panel/barberos/{$uno}", ['nombre_publico' => 'Otro'], $admin)->status());
        $this->assertSame(403, $this->call('POST', '/panel/barberos', ['nombre_publico' => 'Otro', 'sucursal_id' => $cercado], $admin)->status());

        // El barbero no gestiona barberos (su propia agenda llega en la Fase 7).
        $barber = $this->actingAs('barbero1.centro@barberapp.test');
        $this->assertSame(403, $this->call('GET', '/panel/barberos', null, $barber)->status());
        $this->assertSame(403, $this->call('PUT', "/panel/barberos/{$uno}/horario", ['tramos' => []], $barber)->status());

        // Otra barbería: 404 para no revelar existencia.
        $norte = $this->actingAs('propietario.norte@barberapp.test', 'demo-norte');
        foreach ([['GET', "/panel/barberos/{$uno}", null], ['PUT', "/panel/barberos/{$uno}", ['activo' => false]], ['PUT', "/panel/barberos/{$uno}/horario", ['tramos' => []]],
                  ['PUT', "/panel/barberos/{$uno}/servicios", ['servicios' => []]], ['GET', "/panel/barberos/{$uno}/jornada", null], ['DELETE', "/panel/barberos/{$uno}", null]] as [$m, $p, $body]) {
            $this->assertSame(404, $this->call($m, $p, $body, $norte)->status(), "{$m} {$p}");
        }
        $this->assertSame(6, (int) $this->scalar('SELECT COUNT(*) FROM horarios_barberos WHERE barbero_id = ?', [$uno]), 'Sigue la jornada que guardó el administrador');
        $this->assertSame(1, (int) $this->scalar('SELECT activo FROM barberos WHERE id = ?', [$uno]));
    }

    public function testBarberPhoto(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('La extensión GD no está instalada.');
        }
        $dir = sys_get_temp_dir() . '/barberapp-media-' . bin2hex(random_bytes(4));
        Config::set('media.path', $dir);
        Config::set('media.url', 'https://cdn.test/media');
        $img = imagecreatetruecolor(600, 900);
        imagefilledrectangle($img, 0, 0, 600, 900, imagecolorallocate($img, 30, 30, 30));
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = (string) ob_get_clean();

        $h = $this->owner();
        $uno = $this->barberId('Barbero Uno Demo');
        $res = $this->send('POST', "/panel/barberos/{$uno}/foto", $jpeg, $h + ['Content-Type' => 'image/jpeg']);
        $this->assertSame(200, $res->status(), $res->body());
        $url = $this->data($res)['foto_url'];
        $this->assertStringStartsWith('https://cdn.test/media/tenants/', $url);
        $file = $dir . '/' . substr($url, strlen('https://cdn.test/media/'));
        $this->assertFileExists($file);
        [$w, $hgt] = getimagesize($file);
        $this->assertSame($w, $hgt, 'El retrato se recorta cuadrado');

        $this->assertSame(415, $this->send('POST', "/panel/barberos/{$uno}/foto", 'texto', $h + ['Content-Type' => 'text/plain'])->status());
        $res = $this->call('DELETE', "/panel/barberos/{$uno}/foto", null, $h);
        $this->assertNull($this->data($res)['foto_url']);
        $this->assertFileDoesNotExist($file);
        @rmdir(dirname($file));
        @rmdir(dirname($file, 2));
        @rmdir($dir);
    }
}
