<?php

declare(strict_types=1);

namespace Tests\Integration\Catalog;

/** Servicios y categorías desde el panel (Fase 4). */
final class ServicesApiTest extends CatalogTestCase
{
    public function testOwnerCreatesListsAndEditsServices(): void
    {
        $h = $this->owner();
        $cat = (int) $this->scalar("SELECT id FROM categorias_servicio WHERE nombre = 'Barba'");
        $res = $this->call('POST', '/panel/servicios', [
            'nombre' => 'Barba de lujo', 'descripcion' => 'Toalla caliente y aceites', 'duracion_minutos' => 45,
            'precio' => '35.5', 'categoria_id' => $cat, 'tipo' => 'barba',
        ], $h);
        $this->assertSame(201, $res->status(), $res->body());
        $s = $this->data($res);
        $this->assertSame(45, $s['duracion_minutos']);
        $this->assertSame('35.50', $s['precio']);
        $this->assertSame('Barba', $s['categoria']);
        $this->assertSame('barba', $s['tipo']);
        $this->assertTrue($s['activo']);

        $list = $this->call('GET', '/panel/servicios', null, $h);
        $this->assertSame(200, $list->status());
        $names = array_column($this->data($list), 'nombre');
        $this->assertContains('Barba de lujo', $names);
        $this->assertContains('Servicio inactivo de prueba', $names, 'El panel también lista los inactivos');
        $this->assertNotContains('Corte moderno', $names, 'Nunca servicios de otra barbería');
        $meta = $list->decoded()['meta'];
        $this->assertNotEmpty($meta['tipos']);
        $this->assertSame(5, $meta['reglas']['duracion_min']);

        $upd = $this->call('PUT', "/panel/servicios/{$s['id']}", ['precio' => 39, 'duracion_minutos' => 50], $h);
        $this->assertSame(200, $upd->status(), $upd->body());
        $this->assertSame('39.00', $this->data($upd)['precio']);
        $this->assertSame(50, $this->data($upd)['duracion_minutos']);
        $this->assertSame('Barba de lujo', $this->data($upd)['nombre'], 'La edición es parcial');
        $this->assertSame(1, $this->auditCount('servicio.actualizado', 'exito'));
    }

    public function testRulesForDurationPriceAndName(): void
    {
        $h = $this->owner();
        $base = ['nombre' => 'Servicio X', 'duracion_minutos' => 30, 'precio' => '20'];
        $cases = [
            ['duracion_minutos', 0], ['duracion_minutos', -30], ['duracion_minutos', 32], ['duracion_minutos', 700],
            ['precio', '-1'], ['precio', '12.345'], ['precio', 'gratis'], ['nombre', 'A'],
        ];
        foreach ($cases as [$field, $value]) {
            $res = $this->call('POST', '/panel/servicios', [$field => $value] + $base, $h);
            $this->assertSame(422, $res->status(), "{$field}={$value}: " . $res->body());
            $this->assertArrayHasKey($field, $res->decoded()['error']['details']['fields'], "{$field}={$value}");
        }
        $res = $this->call('POST', '/panel/servicios', ['nombre' => 'Sin datos'], $h);
        $this->assertSame(422, $res->status());
        $this->assertEqualsCanonicalizing(['duracion_minutos', 'precio'], array_keys($res->decoded()['error']['details']['fields']));

        $dup = $this->call('POST', '/panel/servicios', ['nombre' => 'Corte clásico'] + $base, $h);
        $this->assertSame(422, $dup->status());
        $this->assertArrayHasKey('nombre', $dup->decoded()['error']['details']['fields']);

        // Un servicio gratuito (precio 0) sí es válido.
        $this->assertSame(201, $this->call('POST', '/panel/servicios', ['nombre' => 'Lavado de cortesía', 'duracion_minutos' => 10, 'precio' => 0], $h)->status());
    }

    public function testInactiveServiceDisappearsFromMarketplace(): void
    {
        $h = $this->owner();
        $id = $this->serviceId('Corte infantil');
        $res = $this->call('PUT', "/panel/servicios/{$id}", ['activo' => false], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertFalse($this->data($res)['activo']);

        $this->cookies = [];
        $profile = $this->data($this->call('GET', '/marketplace/barberias/demo-centro'));
        $names = array_column($profile['servicios'], 'nombre');
        $this->assertNotContains('Corte infantil', $names);
        $this->assertContains('Corte clásico', $names);
    }

    public function testDeleteKeepsBookingsAndReportsFutureOnes(): void
    {
        $h = $this->owner();
        $id = $this->serviceId('Corte clásico');
        $res = $this->call('DELETE', "/panel/servicios/{$id}", null, $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame(1, $res->decoded()['meta']['reservas_futuras'], 'La reserva pendiente de mañana conserva su copia del servicio');
        $this->assertNotNull($this->scalar('SELECT deleted_at FROM servicios WHERE id = ?', [$id]));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM barbero_servicios WHERE servicio_id = ?', [$id]));
        $this->assertSame(404, $this->call('GET', "/panel/servicios/{$id}", null, $h)->status());
        // El nombre queda libre para un servicio nuevo.
        $this->assertSame(201, $this->call('POST', '/panel/servicios', ['nombre' => 'Corte clásico', 'duracion_minutos' => 30, 'precio' => 28], $h)->status());
    }

    public function testCategories(): void
    {
        $h = $this->owner();
        $res = $this->call('POST', '/panel/categorias', ['nombre' => 'Tratamientos'], $h);
        $this->assertSame(201, $res->status(), $res->body());
        $id = $this->data($res)['id'];
        $this->assertSame(422, $this->call('POST', '/panel/categorias', ['nombre' => 'Cortes'], $h)->status(), 'Nombre repetido');
        $this->assertSame('Tratamientos capilares', $this->data($this->call('PUT', "/panel/categorias/{$id}", ['nombre' => 'Tratamientos capilares'], $h))['nombre']);

        $cortes = (int) $this->scalar("SELECT id FROM categorias_servicio WHERE nombre = 'Cortes'");
        $inUse = $this->call('DELETE', "/panel/categorias/{$cortes}", null, $h);
        $this->assertSame(409, $inUse->status());
        $this->assertSame('category_in_use', $this->errorCode($inUse));
        $this->assertSame(204, $this->call('DELETE', "/panel/categorias/{$id}", null, $h)->status());
    }

    public function testTenantIsolationAndPermissions(): void
    {
        $centroService = $this->serviceId('Corte clásico');
        $this->exec('INSERT INTO categorias_servicio (tenant_id, nombre) VALUES (?, ?)', [$this->tenantId('demo-norte'), 'Norte']);
        $norteCat = (int) $this->db->lastInsertId();

        $norte = $this->actingAs('propietario.norte@barberapp.test', 'demo-norte');
        $this->assertSame(404, $this->call('PUT', "/panel/servicios/{$centroService}", ['precio' => 1], $norte)->status());
        $this->assertSame(404, $this->call('DELETE', "/panel/servicios/{$centroService}", null, $norte)->status());
        $this->assertSame('25.00', (string) $this->scalar('SELECT precio FROM servicios WHERE id = ?', [$centroService]));

        // Enviar X-Tenant de otra barbería no da acceso.
        $spoof = ['X-Tenant' => 'demo-centro'] + $norte;
        $this->assertSame(404, $this->call('GET', '/panel/servicios', null, $spoof)->status());

        // Categoría de otra barbería al crear un servicio.
        $h = $this->owner();
        $res = $this->call('POST', '/panel/servicios', ['nombre' => 'Mezcla', 'duracion_minutos' => 30, 'precio' => 10, 'categoria_id' => $norteCat], $h);
        $this->assertSame(422, $res->status());

        // El administrador gestiona servicios; el barbero no.
        $admin = $this->actingAs('admin.centro@barberapp.test');
        $this->assertSame(200, $this->call('GET', '/panel/servicios', null, $admin)->status());
        $barber = $this->actingAs('barbero1.centro@barberapp.test');
        $this->assertSame(403, $this->call('GET', '/panel/servicios', null, $barber)->status());
        $this->assertSame(403, $this->call('POST', '/panel/servicios', ['nombre' => 'X', 'duracion_minutos' => 30, 'precio' => 1], $barber)->status());
        $this->assertGreaterThan(0, $this->auditCount('authz.permiso_denegado', 'denegado'));

        $this->cookies = [];
        $this->assertSame(401, $this->call('GET', '/panel/servicios', null, ['X-Tenant' => 'demo-centro'])->status());
    }
}
