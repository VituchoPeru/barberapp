<?php

declare(strict_types=1);

namespace Tests\Integration\Panel;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Reportes y analítica de la barbería (Fase 11): confirmación, demanda por día y hora,
 * productividad por barbero, clientes recurrentes, previsto frente a cobrado y exportaciones.
 *
 * Escenario controlado en una semana pasada (lunes a domingo, sin datos demo) de demo-centro:
 *   lun 10:00 B1 Cliente Uno   atendida 40.00 (atención real 50 min), cobro 40 efectivo
 *   lun 11:00 B1 Cliente Dos   atendida 25.00, cobro parcial 10 yape
 *   mar 10:00 B2 Cliente Uno   atendida 25.00, sin cobro
 *   mar 15:00 B2 Mostrador     no asistió 25.00
 *   mié 10:00 B1 Cliente Dos   cancelada por el cliente
 *   mié 12:00 B1 Mostrador     pendiente con asistencia confirmada por el cliente
 *   + una visita atendida de Cliente Dos ANTES del periodo (para la recurrencia).
 */
final class PanelAnalyticsTest extends PanelTestCase
{
    private const TZ = 'America/Lima';

    private string $lunes;
    private string $domingo;
    /** @var array<string, int> */
    private array $cli = [];

    protected function setUp(): void
    {
        parent::setUp();
        $d = (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->modify('-35 days');
        $d = $d->modify('-' . ((int) $d->format('N') - 1) . ' days');
        $this->lunes = $d->format('Y-m-d');
        $this->domingo = $d->modify('+6 days')->format('Y-m-d');
        foreach (['uno' => 'Cliente Uno', 'dos' => 'Cliente Dos', 'mostrador' => 'Cliente Mostrador'] as $k => $n) {
            $this->cli[$k] = (int) $this->scalar('SELECT id FROM clientes WHERE nombres = ?', [$n]);
        }
        $b1 = $this->barberId('Barbero Uno Demo');
        $b2 = $this->barberId('Barbero Dos Demo');

        $r = $this->seedBooking($b1, 'uno', 'completada', 0, '10:00', 45, '40.00', ['confirmada' => true, 'real' => 50]);
        $this->pay($r, '40.00', 'efectivo');
        $r = $this->seedBooking($b1, 'dos', 'completada', 0, '11:00', 30, '25.00', ['confirmada' => true]);
        $this->pay($r, '10.00', 'yape');
        $this->seedBooking($b2, 'uno', 'completada', 1, '10:00', 30, '25.00', ['confirmada' => true]);
        $this->seedBooking($b2, 'mostrador', 'no_asistio', 1, '15:00', 30, '25.00');
        $this->seedBooking($b1, 'dos', 'cancelada', 2, '10:00', 30, '25.00');
        $this->seedBooking($b1, 'mostrador', 'pendiente', 2, '12:00', 30, '25.00', ['asistencia' => true]);
        $this->seedBooking($b2, 'dos', 'completada', -5, '16:00', 30, '25.00', ['confirmada' => true]);
    }

    /** @param array{confirmada?:bool, real?:int, asistencia?:bool} $o */
    private function seedBooking(int $barbero, string $cliente, string $estado, int $dia, string $hora, int $min, string $precio, array $o = []): int
    {
        $tenant = $this->tenantId('demo-centro');
        $ini = (new DateTimeImmutable($this->lunes . ' ' . $hora, new DateTimeZone(self::TZ)))->modify("{$dia} day")->setTimezone(new DateTimeZone('UTC'));
        $fin = $ini->modify("+{$min} minutes");
        $f = static fn (DateTimeImmutable $x): string => $x->format('Y-m-d H:i:s');
        $this->exec(
            "INSERT INTO reservas (tenant_id, codigo, sucursal_id, barbero_id, cliente_id, estado, inicio_utc, fin_utc, zona_horaria, duracion_total_minutos,
                                   precio_total, canal, confirmada_en, asistencia_confirmada_en, cancelada_en, cancelada_por_tipo, atencion_iniciada_en, atencion_finalizada_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'America/Lima', ?, ?, 'enlace', ?, ?, ?, ?, ?, ?)",
            [
                $tenant, strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)), $this->branchId('sede-cercado'), $barbero, $this->cli[$cliente], $estado,
                $f($ini), $f($fin), $min, $precio,
                !empty($o['confirmada']) ? $f($ini->modify('-1 day')) : null,
                !empty($o['asistencia']) ? $f($ini->modify('-2 hour')) : null,
                $estado === 'cancelada' ? $f($ini->modify('-1 day')) : null,
                $estado === 'cancelada' ? 'cliente' : null,
                isset($o['real']) ? $f($ini) : null,
                isset($o['real']) ? $f($ini->modify('+' . $o['real'] . ' minutes')) : null,
            ]
        );
        $id = (int) $this->db->lastInsertId();
        $this->exec(
            "INSERT INTO reserva_servicios (tenant_id, reserva_id, servicio_id, orden, nombre_servicio, duracion_minutos, precio, inicio_utc, fin_utc)
             VALUES (?, ?, ?, 1, 'Corte clásico', ?, ?, ?, ?)",
            [$tenant, $id, $this->serviceId('Corte clásico'), $min, $precio, $f($ini), $f($fin)]
        );
        return $id;
    }

    private function pay(int $reserva, string $monto, string $metodo): void
    {
        $this->exec(
            'INSERT INTO reserva_pagos (tenant_id, reserva_id, monto, metodo, pagado_en) SELECT tenant_id, id, ?, ?, fin_utc FROM reservas WHERE id = ?',
            [$monto, $metodo, $reserva]
        );
    }

    /** @param array<string, string> $h @return array<string, mixed> */
    private function report(array $h, string $extra = ''): array
    {
        $res = $this->call('GET', "/panel/reportes?desde={$this->lunes}&hasta={$this->domingo}{$extra}", null, $h);
        $this->assertSame(200, $res->status(), $res->body());
        return $this->data($res);
    }

    public function testConfirmationDemandAndClientRetention(): void
    {
        $r = $this->report($this->owner());
        $this->assertSame(6, $r['totales']['reservas']);
        $this->assertSame([
            'reservas_activas' => 5, 'confirmadas' => 3, 'tasa_confirmacion' => 60.0,
            'asistencia_confirmada_cliente' => 1, 'tasa_asistencia_confirmada' => 20.0, 'pendientes_sin_confirmar' => 1,
        ], $r['confirmacion']);

        // Demanda: la cancelada no cuenta; cada día de la semana aparece una vez en el rango.
        $dm = $r['demanda'];
        $this->assertSame(5, $dm['total']);
        $this->assertSame(1, $dm['max_celda']);
        $porDia = array_column($dm['por_dia_semana'], null, 'dia');
        $this->assertSame(['dia' => 1, 'reservas' => 2, 'dias_en_rango' => 1, 'promedio' => 2.0], $porDia[1]);
        $this->assertSame(1, $porDia[3]['reservas'], 'Miércoles: solo la pendiente');
        $this->assertSame(0, $porDia[7]['reservas']);
        $this->assertSame(['dia' => 1, 'hora' => 10, 'reservas' => 1, 'promedio' => 1.0], $dm['picos'][0]);
        $this->assertSame(range(8, 20), $dm['horas']);
        $this->assertSame(2, array_column($dm['por_hora'], 'reservas', 'hora')[10]);

        // Clientes: Uno vino dos veces (recurrente y nuevo); Dos tenía una visita previa al periodo.
        $c = $r['clientes'];
        $this->assertSame(
            ['atendidos' => 2, 'nuevos' => 1, 'recurrentes' => 2, 'con_visita_previa' => 1, 'repiten_en_periodo' => 1, 'tasa_recurrencia' => 100.0, 'visitas_promedio' => 1.5],
            array_intersect_key($c, array_flip(['atendidos', 'nuevos', 'recurrentes', 'con_visita_previa', 'repiten_en_periodo', 'tasa_recurrencia', 'visitas_promedio']))
        );
        $this->assertSame(['1' => 1, '2' => 1, '3+' => 0], $c['distribucion_visitas']);
        $this->assertSame([['cliente_id' => $this->cli['uno'], 'nombre' => 'Cliente Uno Demo', 'visitas' => 2, 'con_visita_previa' => false]], $c['top']);

        // Con filtro de barbero la recurrencia se mide con ese barbero.
        $b2 = $this->report($this->owner(), '&barbero_id=' . $this->barberId('Barbero Dos Demo'));
        $this->assertSame(['atendidos' => 1, 'nuevos' => 1, 'recurrentes' => 0], array_intersect_key($b2['clientes'], array_flip(['atendidos', 'nuevos', 'recurrentes'])));
        $this->assertSame(2, $b2['totales']['reservas']);
        $this->assertSame(['Barbero Dos Demo'], array_column($b2['productividad'], 'nombre'));
        $this->assertNotEmpty($b2['barberos'], 'La lista para el filtro siempre trae a todo el equipo');
    }

    public function testProductivityAndIncomeSeparatePlannedFromCollected(): void
    {
        $r = $this->report($this->owner());
        $p = array_column($r['productividad'], null, 'nombre');
        $b1 = $p['Barbero Uno Demo'];
        $this->assertSame(
            ['atendidas' => 2, 'no_asistio' => 0, 'canceladas' => 1, 'por_atender' => 1, 'clientes_atendidos' => 2, 'minutos_atendidos' => 75,
                'duracion_real_promedio' => 50, 'importe_previsto_atendidas' => '65.00', 'ingresos_registrados' => '50.00', 'cobros' => 2,
                'cobrado_atendidas' => '50.00', 'ticket_promedio' => '25.00'],
            array_intersect_key($b1, array_flip(['atendidas', 'no_asistio', 'canceladas', 'por_atender', 'clientes_atendidos', 'minutos_atendidos',
                'duracion_real_promedio', 'importe_previsto_atendidas', 'ingresos_registrados', 'cobros', 'cobrado_atendidas', 'ticket_promedio']))
        );
        $this->assertGreaterThan(0, $b1['minutos_jornada']);
        $this->assertSame(round(2 * 60 / $b1['minutos_jornada'], 2), $b1['atendidas_por_hora']);
        $b2 = $p['Barbero Dos Demo'];
        $this->assertSame([1, 1, 50.0, null, '0.00'], [$b2['atendidas'], $b2['no_asistio'], $b2['tasa_inasistencia'], $b2['ticket_promedio'], $b2['ingresos_registrados']]);
        $this->assertSame('Barbero Uno Demo', $r['productividad'][0]['nombre'], 'Ordenado por atendidas');

        $i = $r['ingresos'];
        $this->assertSame('50.00', $i['registrados']);
        $this->assertSame(['citas' => 3, 'importe_previsto' => '90.00', 'cobrado' => '50.00', 'saldo_por_cobrar' => '40.00', 'sin_cobro' => 1], $i['atendidas']);
        $this->assertSame('90.00', $r['totales']['importe_previsto_atendidas'], 'El previsto se informa aparte, no como ingreso');
        $this->assertSame(['fecha' => $this->lunes, 'monto' => '50.00', 'cobros' => 2], $i['por_dia'][0]);
        $this->assertCount(7, $i['por_dia']);
        $this->assertSame([['barbero_id' => $this->barberId('Barbero Uno Demo'), 'nombre' => 'Barbero Uno Demo', 'monto' => '50.00']], $i['por_barbero']);

        // Sin reportes.financieros: productividad sin importes cobrados y sin ingresos.
        $adm = $this->report($this->actingAs(self::ADMIN));
        $this->assertNull($adm['ingresos']);
        $this->assertArrayNotHasKey('ingresos_registrados', $adm['productividad'][0]);
        $this->assertArrayNotHasKey('ticket_promedio', $adm['productividad'][0]);

        // Barbero de otra barbería: no existe aquí.
        $otro = (int) $this->scalar('SELECT id FROM barberos WHERE tenant_id = ? LIMIT 1', [$this->tenantId('demo-norte')]);
        $this->assertSame(404, $this->call('GET', "/panel/reportes?barbero_id={$otro}", null, $this->owner())->status());
    }

    public function testCsvExportsAreAuditedAndRespectPermissions(): void
    {
        $qs = "desde={$this->lunes}&hasta={$this->domingo}";
        $res = $this->call('GET', "/panel/reportes/exportar?tipo=resumen&formato=csv&{$qs}", null, $this->owner());
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame('text/csv; charset=utf-8', $res->header('Content-Type'));
        $this->assertStringContainsString("reporte-demo-centro-{$this->lunes}_{$this->domingo}.csv", (string) $res->header('Content-Disposition'));
        $csv = $res->body();
        $this->assertStringStartsWith("\u{FEFF}Reporte BARBERAPP", $csv);
        $this->assertStringContainsString("Saldo por cobrar de citas atendidas,40\r\n", $csv);
        $this->assertStringContainsString("Ingresos registrados (cobros del periodo),50\r\n", $csv);
        $this->assertStringContainsString("Tasa de confirmación %,60\r\n", $csv);
        $this->assertStringContainsString("\r\nDemanda por hora\r\n", $csv);
        $this->assertStringContainsString("\r\nBarberos\r\n", $csv);
        $this->assertSame(1, $this->auditCount('reportes.exportado', 'exito'));

        $adm = $this->call('GET', "/panel/reportes/exportar?tipo=resumen&formato=csv&{$qs}", null, $this->actingAs(self::ADMIN))->body();
        $this->assertStringNotContainsString('Ingresos', $adm, 'Sin reportes.financieros no se exportan ingresos');
        $this->assertStringContainsString('Importe previsto', $adm);

        // Detalle: una fila por reserva, nombres de cliente y fórmulas neutralizadas.
        $this->exec("UPDATE clientes SET apellidos = '=HYPERLINK(\"http://x\")' WHERE id = ?", [$this->cli['mostrador']]);
        $res = $this->call('GET', "/panel/reportes/exportar?tipo=reservas&formato=csv&{$qs}", null, $this->owner());
        $this->assertSame(200, $res->status(), $res->body());
        $lines = array_values(array_filter(explode("\r\n", substr($res->body(), 3))));
        $this->assertCount(7, $lines, 'Encabezado + 6 reservas del periodo');
        $this->assertStringStartsWith('Código,Fecha,Hora,Sucursal,Barbero,Cliente,Servicios,Estado,Canal,Duración (min),Importe previsto,Cobrado,Saldo,', $lines[0]);
        $this->assertStringContainsString(",{$this->lunes},10:00,", $lines[1]);
        $this->assertStringContainsString(',Atendida,Enlace directo,45,40,40,0,Sí,No,0,', $lines[1]);
        $this->assertStringContainsString(',Atendida,Enlace directo,30,25,10,15,', $lines[2]);
        $this->assertStringContainsString(',Cancelada,', $lines[5]);
        $this->assertStringEndsWith(',Cliente', $lines[5]);
        $this->assertStringNotContainsString(',Cliente Mostrador =HYPERLINK', $res->body(), 'Una celda que empieza con = no queda como fórmula');
        $this->assertStringContainsString('"Cliente Mostrador =HYPERLINK(""http://x"")"', $res->body(), 'No empieza con =: queda como texto entre comillas');

        $this->exec("UPDATE clientes SET nombres = '=1+1', apellidos = NULL WHERE id = ?", [$this->cli['mostrador']]);
        $body = $this->call('GET', "/panel/reportes/exportar?tipo=reservas&formato=csv&{$qs}", null, $this->owner())->body();
        $this->assertStringContainsString(",'=1+1,", $body);

        $adm = $this->call('GET', "/panel/reportes/exportar?tipo=reservas&formato=csv&{$qs}", null, $this->actingAs(self::ADMIN))->body();
        $this->assertStringNotContainsString('Cobrado', $adm);

        $this->assertSame(403, $this->call('GET', "/panel/reportes/exportar?{$qs}", null, $this->actingAs(self::BARBERO1))->status());
        $this->assertSame(422, $this->call('GET', "/panel/reportes/exportar?formato=docx&{$qs}", null, $this->owner())->status());
        $this->assertSame(422, $this->call('GET', "/panel/reportes/exportar?tipo=todo&{$qs}", null, $this->owner())->status());
    }

    public function testExcelExportIsAValidWorkbook(): void
    {
        $qs = "desde={$this->lunes}&hasta={$this->domingo}";
        $res = $this->call('GET', "/panel/reportes/exportar?tipo=resumen&formato=xlsx&{$qs}", null, $this->owner());
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $res->header('Content-Type'));
        $this->assertStringStartsWith('PK', $res->body());
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ext-zip no disponible para leer el libro (la exportación no la necesita).');
        }
        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($file, $res->body());
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file, \ZipArchive::CHECKCONS));
        $wb = (string) $zip->getFromName('xl/workbook.xml');
        foreach (['Resumen', 'Por día', 'Servicios', 'Barberos', 'Demanda por hora', 'Evolución mensual', 'Ingresos por método', 'Clientes recurrentes'] as $sheet) {
            $this->assertStringContainsString('name="' . $sheet . '"', $wb);
        }
        $resumen = simplexml_load_string((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
        $this->assertNotFalse($resumen);
        $this->assertStringContainsString('<c r="B9"><v>3</v></c>', (string) $zip->getFromName('xl/worksheets/sheet1.xml'), 'Atendidas como número');
        $zip->close();
        unlink($file);

        $res = $this->call('GET', "/panel/reportes/exportar?tipo=reservas&formato=xlsx&{$qs}", null, $this->owner());
        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($file, $res->body());
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file, \ZipArchive::CHECKCONS));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertSame(7, substr_count($sheet, '<row '));
        $this->assertStringContainsString('Cliente Uno Demo', $sheet);
        $zip->close();
        unlink($file);
    }

    public function testPdfExportForTheNativeApp(): void
    {
        $qs = "desde={$this->lunes}&hasta={$this->domingo}";
        $res = $this->call('GET', "/panel/reportes/exportar?tipo=resumen&formato=pdf&{$qs}", null, $this->owner());
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame('application/pdf', $res->header('Content-Type'));
        $this->assertStringContainsString('.pdf"', (string) $res->header('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-1.4', $res->body());
        $this->assertStringEndsWith("%%EOF\n", $res->body());

        $res = $this->call('GET', "/panel/reportes/exportar?tipo=reservas&formato=pdf&{$qs}", null, $this->owner());
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertStringContainsString('/MediaBox [0 0 841.89 595.28]', $res->body(), 'El detalle tiene muchas columnas: página horizontal');

        $audit = $this->db->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(datos_nuevos, '$.formato')) FROM auditoria WHERE accion = 'reportes.exportado' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->assertSame('pdf', $audit);
        $this->assertSame(403, $this->call('GET', "/panel/reportes/exportar?formato=pdf&{$qs}", null, $this->actingAs(self::BARBERO1))->status());
    }
}
