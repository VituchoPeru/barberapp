<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Reports\Export\CsvWriter;
use App\Services\Reports\Export\Table;
use App\Services\Reports\Export\XlsxWriter;
use App\Services\Reports\ReportAnalytics;
use PHPUnit\Framework\TestCase;

/** Cálculos y exportaciones de reportes (Fase 11) sin base de datos. */
final class ReportsUnitTest extends TestCase
{
    /** @return array<string, mixed> */
    private function row(string $estado, string $inicioUtc, int $cliente = 1, int $barbero = 1, array $extra = []): array
    {
        return $extra + [
            'id' => random_int(1, PHP_INT_MAX), 'estado' => $estado, 'inicio_utc' => $inicioUtc, 'cliente_id' => $cliente, 'barbero_id' => $barbero,
            'duracion_total_minutos' => 30, 'precio_total' => '25.00', 'confirmada_en' => null, 'asistencia_confirmada_en' => null,
            'atencion_iniciada_en' => null, 'atencion_finalizada_en' => null,
        ];
    }

    public function testCsvCellsEscapeQuotesAndNeutralizeFormulas(): void
    {
        $this->assertSame('texto', CsvWriter::cell('texto'));
        $this->assertSame('"a,b"', CsvWriter::cell('a,b'));
        $this->assertSame('"di ""hola"""', CsvWriter::cell('di "hola"'));
        $this->assertSame("'=SUM(A1:A2)", CsvWriter::cell('=SUM(A1:A2)'));
        $this->assertSame("'+51 999", CsvWriter::cell('+51 999'));
        $this->assertSame("'-x", CsvWriter::cell('-x'));
        $this->assertSame("'@cmd", CsvWriter::cell('@cmd'));
        $this->assertSame('-5', CsvWriter::cell(-5), 'Los números negativos siguen siendo números');
        $this->assertSame('12.5', CsvWriter::cell(12.5));
        $this->assertSame('', CsvWriter::cell(null));
        $csv = CsvWriter::write([new Table('A', ['x', 'y'], [[1, 'ñ']]), new Table('B', ['z'])]);
        $this->assertSame("\u{FEFF}A\r\nx,y\r\n1,ñ\r\n\r\nB\r\nz\r\n", $csv);
    }

    public function testXlsxColumnsAndSheetNames(): void
    {
        $this->assertSame(['A', 'Z', 'AA', 'AZ', 'BA', 'ZZ', 'AAA'], array_map([XlsxWriter::class, 'col'], [0, 25, 26, 51, 52, 701, 702]));
        $bin = XlsxWriter::write([new Table('Hoja: [1]/a', ['h'], [['<b>&</b>']]), new Table('Hoja: [1]/a', ['h'])]);
        $this->assertStringStartsWith("PK\x03\x04", $bin);
        $this->assertStringContainsString("PK\x05\x06", $bin);
    }

    public function testDemandUsesLocalTimeAndWeekdayOccurrences(): void
    {
        // 2026-03-03 02:30 UTC = lunes 2026-03-02 21:30 en Lima (UTC−5).
        $rows = [
            $this->row('completada', '2026-03-03 02:30:00'),
            $this->row('pendiente', '2026-03-02 15:00:00'),   // lunes 10:00
            $this->row('cancelada', '2026-03-02 15:00:00'),   // no cuenta
            $this->row('no_asistio', '2026-03-09 15:00:00'),  // lunes siguiente 10:00
        ];
        $d = ReportAnalytics::demand($rows, 'America/Lima', '2026-03-02', '2026-03-15');
        $this->assertSame(3, $d['total']);
        $this->assertSame(range(8, 21), $d['horas'], 'La franja se amplía hasta las 21 h');
        $lunes = $d['por_dia_semana'][0];
        $this->assertSame(['dia' => 1, 'reservas' => 3, 'dias_en_rango' => 2, 'promedio' => 1.5], $lunes);
        $this->assertSame(['dia' => 1, 'hora' => 10, 'reservas' => 2, 'promedio' => 1.0], $d['picos'][0]);
        $this->assertSame(2, $d['max_celda']);
    }

    public function testRetentionAndConfirmations(): void
    {
        $rows = [
            $this->row('completada', '2026-03-02 15:00:00', 1, 1, ['confirmada_en' => '2026-03-01 10:00:00']),
            $this->row('completada', '2026-03-05 15:00:00', 1),
            $this->row('completada', '2026-03-06 15:00:00', 1),
            $this->row('completada', '2026-03-06 16:00:00', 2),
            $this->row('completada', '2026-03-06 17:00:00', 3),
            $this->row('cancelada', '2026-03-06 18:00:00', 4),
            $this->row('pendiente', '2026-03-07 18:00:00', 4, 1, ['asistencia_confirmada_en' => '2026-03-07 10:00:00']),
        ];
        $r = ReportAnalytics::retention($rows, [2 => true], null);
        $this->assertSame([3, 2, 2, 1, 1], [$r['atendidos'], $r['nuevos'], $r['recurrentes'], $r['con_visita_previa'], $r['repiten_en_periodo']]);
        $this->assertSame(['1' => 2, '2' => 0, '3+' => 1], $r['distribucion_visitas']);
        $this->assertSame(66.7, $r['tasa_recurrencia']);
        $this->assertNull($r['top'], 'Sin permiso de clientes no hay ranking con nombres');

        $c = ReportAnalytics::confirmations($rows);
        $this->assertSame([6, 1, 1, 1], [$c['reservas_activas'], $c['confirmadas'], $c['asistencia_confirmada_cliente'], $c['pendientes_sin_confirmar']]);
        $this->assertSame(ReportAnalytics::retention([], [], [])['tasa_recurrencia'], null);
    }

    public function testIncomeSeparatesPlannedCollectedAndBalance(): void
    {
        $rows = [
            $this->row('completada', '2026-03-02 15:00:00', 1, 1, ['id' => 10, 'precio_total' => '40.00']),
            $this->row('completada', '2026-03-02 16:00:00', 2, 1, ['id' => 11, 'precio_total' => '25.00']),
            $this->row('confirmada', '2026-03-03 16:00:00', 2, 1, ['id' => 12, 'precio_total' => '25.00']),
        ];
        $payments = [
            ['reserva_id' => 10, 'monto' => '50.00', 'metodo' => 'efectivo', 'pagado_en' => '2026-03-02 15:40:00', 'barbero_id' => 1],
            ['reserva_id' => 12, 'monto' => '25.00', 'metodo' => 'yape', 'pagado_en' => '2026-03-03 03:00:00', 'barbero_id' => 1], // adelanto, 2/3 22:00 en Lima
        ];
        $i = ReportAnalytics::income($rows, $payments, [10 => 50.0, 12 => 25.0], [1 => ['nombre' => 'B', 'activo' => true]], 'America/Lima', '2026-03-02', '2026-03-03');
        $this->assertSame(['citas' => 2, 'importe_previsto' => '65.00', 'cobrado' => '50.00', 'saldo_por_cobrar' => '25.00', 'sin_cobro' => 1], $i['atendidas'], 'Un cobro mayor al precio no genera saldo negativo');
        $this->assertSame([['fecha' => '2026-03-02', 'monto' => '75.00', 'cobros' => 2], ['fecha' => '2026-03-03', 'monto' => '0.00', 'cobros' => 0]], $i['por_dia']);
    }
}
