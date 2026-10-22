<?php

declare(strict_types=1);

namespace Tests\Integration\Panel;

/** Clientes, dashboard, reportes y configuración del negocio en el panel (Fase 6). */
final class PanelInsightsTest extends PanelTestCase
{
    public function testClientSearchDetailNotesAndBlock(): void
    {
        $h = $this->actingAs(self::ADMIN);
        $all = $this->call('GET', '/panel/clientes', null, $h);
        $this->assertSame(200, $all->status(), $all->body());
        $this->assertSame(3, $all->decoded()['meta']['total']);

        $this->assertSame(['Cliente Mostrador Demo'], array_column($this->data($this->call('GET', '/panel/clientes?q=000003', null, $h)), 'nombre'), 'Por teléfono');
        $this->assertSame(['Cliente Dos Demo'], array_column($this->data($this->call('GET', '/panel/clientes?q=cliente%20dos', null, $h)), 'nombre'), 'Por nombre');
        $this->assertSame(['Cliente Uno Demo'], array_column($this->data($this->call('GET', '/panel/clientes?q=cliente1%40', null, $h)), 'nombre'), 'Por correo');
        $this->assertSame([], $this->data($this->call('GET', '/panel/clientes?q=%25', null, $h)), 'Los comodines se escapan');

        $uno = (int) $this->scalar("SELECT id FROM clientes WHERE nombres = 'Cliente Uno'");
        $res = $this->call('GET', "/panel/clientes/{$uno}", null, $h);
        $this->assertSame(200, $res->status(), $res->body());
        $c = $this->data($res);
        $this->assertTrue($c['tiene_cuenta']);
        $this->assertSame(1, $c['resumen']['por_estado']['completada']);
        $this->assertSame([['nombre' => 'Corte clásico', 'veces' => 1]], $c['resumen']['servicios_frecuentes']);
        $this->assertSame('Barbero Dos Demo', $c['resumen']['barbero_frecuente']['nombre']);
        $this->assertSame('25.00', $c['resumen']['total_cobrado'], 'Cobro demo de la cita atendida');
        $this->assertNotNull($c['proxima_cita']);
        $this->assertNotNull($c['ultima_visita']);

        $prox = $this->call('GET', "/panel/clientes/{$uno}/reservas?tipo=proximas", null, $h);
        $this->assertSame(1, $prox->decoded()['meta']['total'], 'Solo las de esta barbería (no la de Demo Norte)');
        $this->assertSame(1, $this->call('GET', "/panel/clientes/{$uno}/reservas?tipo=pasadas", null, $h)->decoded()['meta']['total']);

        $res = $this->call('PUT', "/panel/clientes/{$uno}", ['notas_internas' => 'Prefiere el turno tarde', 'bloqueado' => true], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame(['Prefiere el turno tarde', true], [$this->data($res)['notas_internas'], $this->data($res)['bloqueado']]);
        $res = $this->book($this->body($this->day(3), '10:00'), $this->client());
        $this->assertSame(403, $res->status(), 'Bloqueado para reservar en línea');
        $this->assertSame('client_blocked', $this->errorCode($res));

        $h = $this->actingAs(self::ADMIN);
        $res = $this->call('PUT', "/panel/clientes/{$uno}", ['telefono' => '999888777'], $h);
        $this->assertSame(422, $res->status(), 'Datos de un cliente con cuenta: solo los cambia él');
        $mostrador = (int) $this->scalar("SELECT id FROM clientes WHERE nombres = 'Cliente Mostrador'");
        $res = $this->call('PUT', "/panel/clientes/{$mostrador}", ['telefono' => '999 888 777', 'email' => 'Mostrador@Ejemplo.pe'], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame(['+51999888777', 'mostrador@ejemplo.pe'], [$this->data($res)['telefono'], $this->data($res)['email']]);

        // Alta directa de cliente de mostrador (reutiliza si ya existe el contacto).
        $res = $this->call('POST', '/panel/clientes', ['nombres' => 'Luis', 'apellidos' => 'Nuevo', 'telefono' => '911222333'], $h);
        $this->assertSame(201, $res->status(), $res->body());
        $this->assertFalse($this->data($res)['ya_existia']);
        $res = $this->call('POST', '/panel/clientes', ['nombres' => 'Luis N.', 'telefono' => '+51911222333'], $h);
        $this->assertSame(200, $res->status());
        $this->assertTrue($this->data($res)['ya_existia']);
        $this->assertSame(422, $this->call('POST', '/panel/clientes', ['nombres' => 'X', 'telefono' => 'abc'], $h)->status());
        $this->assertSame(422, $this->call('PUT', "/panel/clientes/{$uno}", ['notas_internas' => str_repeat('a', 2001)], $h)->status());
    }

    public function testDashboardCountsAreRealAndIncomeOnlyForFinancialRole(): void
    {
        $r1 = $this->demoBooking('confirmada');
        $fecha = $this->localDateOf((string) $r1['inicio_utc']);
        $res = $this->call('GET', '/panel/dashboard?fecha=' . $fecha, null, $this->owner());
        $this->assertSame(200, $res->status(), $res->body());
        $d = $this->data($res);
        $this->assertSame(['pendiente' => 1, 'confirmada' => 1, 'cancelada' => 1, 'total' => 2], array_intersect_key($d['citas'], array_flip(['pendiente', 'confirmada', 'cancelada', 'total'])));
        $this->assertNotNull($d['ingresos'], 'El propietario ve ingresos');
        $this->assertSame('0.00', $d['ingresos']['dia']);
        $this->assertSame(1, $d['pendientes_confirmar']);
        $this->assertSame(1, $d['solicitudes_bloqueo']);
        $this->assertCount(7, $d['semana']);
        $uno = array_values(array_filter($d['ocupacion']['barberos'], fn ($b) => $b['barbero_id'] === $this->barberId('Barbero Uno Demo')))[0];
        $this->assertSame(95, $uno['minutos_reservados'], '45 + 50 minutos (la cancelada no cuenta)');
        $this->assertGreaterThan(0, $uno['minutos_disponibles']);
        $this->assertSame(round(95 * 100 / $uno['minutos_disponibles'], 1), $uno['porcentaje']);

        $d = $this->data($this->call('GET', '/panel/dashboard?fecha=' . $fecha, null, $this->actingAs(self::ADMIN)));
        $this->assertNull($d['ingresos'], 'El administrador no tiene reportes financieros');
        $this->assertSame(403, $this->call('GET', '/panel/dashboard', null, $this->actingAs(self::BARBERO1))->status());

        // Hoy: incluye próximas citas y cobros del día.
        $this->freezeAt((string) $r1['inicio_utc'], -60);
        $h = $this->owner();
        $this->call('POST', "/panel/reservas/{$r1['id']}/pagos", ['monto' => '40.00', 'metodo' => 'efectivo'], $h);
        $d = $this->data($this->call('GET', '/panel/dashboard', null, $h));
        $this->assertSame($fecha, $d['fecha']);
        $this->assertSame('40.00', $d['ingresos']['dia']);
        $this->assertSame($r1['codigo'], $d['proximas'][0]['codigo']);
    }

    public function testReportsByDayServicesOccupancyCancellationsAndMonthly(): void
    {
        $r1 = $this->demoBooking('confirmada');
        $fecha = $this->localDateOf((string) $r1['inicio_utc']);
        $desde = (new \DateTimeImmutable($fecha))->modify('-10 day')->format('Y-m-d');
        $res = $this->call('GET', "/panel/reportes?desde={$desde}&hasta={$fecha}", null, $this->owner());
        $this->assertSame(200, $res->status(), $res->body());
        $r = $this->data($res);
        $this->assertSame(4, $r['totales']['reservas']);
        $this->assertSame(1, $r['totales']['completada']);
        $this->assertSame(1, $r['totales']['cancelada']);
        $this->assertSame(25.0, $r['totales']['tasa_cancelacion']);
        $this->assertSame(0.0, $r['totales']['tasa_inasistencia']);
        $this->assertSame('25.00', $r['totales']['importe_previsto_atendidas']);
        $this->assertCount(11, $r['por_dia']);
        $dia = array_column($r['por_dia'], null, 'fecha')[$fecha];
        $this->assertSame([1, 1, 1], [$dia['confirmada'], $dia['pendiente'], $dia['cancelada']]);
        $top = array_column($r['servicios'], null, 'nombre');
        $this->assertSame(2, $top['Corte clásico']['cantidad'], 'La cancelada no cuenta como solicitada');
        $this->assertSame(1, $top['Corte clásico']['atendidos']);
        $this->assertSame(['cliente' => 0, 'personal' => 1, 'sistema' => 0], $r['cancelaciones']['por']);
        $this->assertCount(12, $r['evolucion_mensual']);
        $this->assertSame(substr($fecha, 0, 7), end($r['evolucion_mensual'])['mes']);
        $this->assertSame('25.00', $r['ingresos']['registrados'], 'Cobro demo de la cita atendida');
        $this->assertSame([['metodo' => 'efectivo', 'monto' => '25.00']], $r['ingresos']['por_metodo']);
        $this->assertNotEmpty($r['ocupacion']['barberos']);

        // Filtro por barbero y rol sin finanzas.
        $res = $this->call('GET', "/panel/reportes?desde={$desde}&hasta={$fecha}&barbero_id=" . $this->barberId('Barbero Uno Demo'), null, $this->actingAs(self::ADMIN));
        $r = $this->data($res);
        $this->assertSame(2, $r['totales']['reservas']);
        $this->assertNull($r['ingresos']);
        $this->assertArrayNotHasKey('ingresos', $r['evolucion_mensual'][0]);
        $this->assertSame(['Barbero Uno Demo'], array_column($r['ocupacion']['barberos'], 'nombre'));

        $this->assertSame(422, $this->call('GET', '/panel/reportes?desde=2025-01-01&hasta=2026-06-01', null, $this->owner())->status(), 'Rango máximo');
        $this->assertSame(422, $this->call('GET', '/panel/reportes?desde=2026-06-02&hasta=2026-06-01', null, $this->owner())->status());
    }

    public function testBusinessSettingsNameAndColors(): void
    {
        $h = $this->owner();
        $res = $this->call('GET', '/panel/negocio', null, $h);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertStringEndsWith('/b/demo-centro', $this->data($res)['enlace']);

        $res = $this->call('PUT', '/panel/negocio', ['nombre_comercial' => 'Barbería Demo Centro Premium', 'color_primario' => '#d4af37'], $h);
        $this->assertSame(200, $res->status(), $res->body());
        $n = $this->data($res);
        $this->assertSame(['Barbería Demo Centro Premium', '#D4AF37'], [$n['nombre_comercial'], $n['colores']['primario']]);
        $this->assertSame(1, $this->auditCount('negocio.actualizado', 'exito'));
        $this->assertSame(422, $this->call('PUT', '/panel/negocio', ['color_primario' => 'rojo'], $h)->status());
        $this->assertSame(422, $this->call('PUT', '/panel/negocio', ['nombre_comercial' => 'X'], $h)->status());
        $this->assertSame(422, $this->call('PUT', '/panel/negocio', [], $h)->status());
        $this->assertSame(403, $this->call('PUT', '/panel/negocio', ['nombre_comercial' => 'Otra'], $this->actingAs(self::ADMIN))->status());
    }
}
