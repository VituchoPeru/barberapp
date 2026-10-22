<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Helpers\Clock;
use App\Services\Reports\Export\Table;

/**
 * Convierte el reporte del panel y el detalle de reservas en tablas exportables (CSV o Excel).
 * Solo incluye lo que el reporte ya trae para quien lo pide: sin reportes.financieros no hay
 * columnas de ingresos y sin clientes.ver no hay nombres de clientes.
 */
final class ReportExport
{
    public const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
    private const ESTADOS = ['pendiente' => 'Pendiente', 'confirmada' => 'Confirmada', 'completada' => 'Atendida', 'cancelada' => 'Cancelada', 'no_asistio' => 'No asistió'];
    private const CANALES = ['marketplace' => 'Marketplace', 'enlace' => 'Enlace directo', 'qr' => 'Código QR', 'panel' => 'Panel', 'app' => 'App'];
    private const METODOS = ['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'yape' => 'Yape', 'plin' => 'Plin', 'transferencia' => 'Transferencia', 'otro' => 'Otro'];
    private const CANCELA = ['cliente' => 'Cliente', 'personal' => 'Barbería', 'sistema' => 'Sistema'];

    /**
     * @param array<string, mixed> $r reporte de ReportService::report()
     * @param array<string, string|null> $labels nombres de sucursal y barbero filtrados
     * @return list<Table>
     */
    public static function summary(array $r, array $labels): array
    {
        $t = $r['totales'];
        $c = $r['confirmacion'];
        $cl = $r['clientes'];
        $resumen = new Table('Resumen', ['Indicador', 'Valor']);
        $add = static function (string $k, $v) use ($resumen): void {
            $resumen->rows[] = [$k, $v];
        };
        $add('Periodo', $r['rango']['desde'] . ' a ' . $r['rango']['hasta']);
        $add('Zona horaria', $r['rango']['zona_horaria']);
        $add('Sucursal', $labels['sucursal'] ?? 'Todas');
        $add('Barbero', $labels['barbero'] ?? 'Todos');
        $add('Reservas (incluye canceladas)', $t['reservas']);
        $add('Pendientes', $t['pendiente']);
        $add('Confirmadas (por atender)', $t['confirmada']);
        $add('Atendidas', $t['completada']);
        $add('Canceladas', $t['cancelada']);
        $add('No asistió', $t['no_asistio']);
        $add('Tasa de cancelación %', $t['tasa_cancelacion']);
        $add('Tasa de inasistencia %', $t['tasa_inasistencia']);
        $add('Reservas confirmadas por la barbería', $c['confirmadas']);
        $add('Tasa de confirmación %', $c['tasa_confirmacion']);
        $add('Asistencia confirmada por el cliente', $c['asistencia_confirmada_cliente']);
        $add('Clientes atendidos', $cl['atendidos']);
        $add('Clientes nuevos', $cl['nuevos']);
        $add('Clientes recurrentes', $cl['recurrentes']);
        $add('Tasa de recurrencia %', $cl['tasa_recurrencia']);
        if ($r['ocupacion'] !== null) {
            $add('Ocupación %', $r['ocupacion']['porcentaje']);
        }
        $add('Importe previsto de citas atendidas (no es ingreso)', self::num($t['importe_previsto_atendidas']));
        if ($r['ingresos'] !== null) {
            $i = $r['ingresos'];
            $add('Ingresos registrados (cobros del periodo)', self::num($i['registrados']));
            $add('Cobros registrados', $i['cobros']);
            $add('Cobrado de las citas atendidas', self::num($i['atendidas']['cobrado']));
            $add('Saldo por cobrar de citas atendidas', self::num($i['atendidas']['saldo_por_cobrar']));
            $add('Citas atendidas sin cobro registrado', $i['atendidas']['sin_cobro']);
        }
        $add('Generado (UTC)', Clock::nowUtc()->format('Y-m-d H:i'));
        $tables = [$resumen];

        $ingDia = $r['ingresos'] !== null ? array_column($r['ingresos']['por_dia'], 'monto', 'fecha') : null;
        $dia = new Table('Por día', array_merge(['Fecha', 'Reservas activas', 'Pendientes', 'Confirmadas', 'Atendidas', 'No asistió', 'Canceladas'], $ingDia !== null ? ['Ingresos registrados'] : []));
        foreach ($r['por_dia'] as $d) {
            $dia->rows[] = array_merge(
                [$d['fecha'], $d['pendiente'] + $d['confirmada'] + $d['completada'] + $d['no_asistio'], $d['pendiente'], $d['confirmada'], $d['completada'], $d['no_asistio'], $d['cancelada']],
                $ingDia !== null ? [self::num($ingDia[$d['fecha']] ?? '0')] : []
            );
        }
        $tables[] = $dia;

        $svc = new Table('Servicios', ['Servicio', 'Veces solicitado', 'Atendidos', 'Importe previsto (no es ingreso)']);
        foreach ($r['servicios'] as $s) {
            $svc->rows[] = [$s['nombre'], $s['cantidad'], $s['atendidos'], self::num($s['importe_previsto'])];
        }
        $tables[] = $svc;

        if ($r['productividad'] !== null) {
            $fin = $r['ingresos'] !== null;
            $p = new Table('Barberos', array_merge(
                ['Barbero', 'Atendidas', 'No asistió', 'Canceladas', 'Por atender', 'Clientes atendidos', 'Minutos atendidos', 'Duración real promedio (min)', 'Ocupación %', 'Atendidas por hora de jornada', 'Tasa de inasistencia %', 'Importe previsto atendidas'],
                $fin ? ['Ingresos registrados', 'Cobrado de atendidas', 'Ticket promedio'] : []
            ));
            foreach ($r['productividad'] as $b) {
                $p->rows[] = array_merge(
                    [$b['nombre'], $b['atendidas'], $b['no_asistio'], $b['canceladas'], $b['por_atender'], $b['clientes_atendidos'], $b['minutos_atendidos'],
                        $b['duracion_real_promedio'], $b['ocupacion'], $b['atendidas_por_hora'], $b['tasa_inasistencia'], self::num($b['importe_previsto_atendidas'])],
                    $fin ? [self::num($b['ingresos_registrados']), self::num($b['cobrado_atendidas']), $b['ticket_promedio'] === null ? null : self::num($b['ticket_promedio'])] : []
                );
            }
            $tables[] = $p;
        }

        if ($r['demanda'] !== null) {
            $dm = $r['demanda'];
            $grid = [];
            foreach ($dm['celdas'] as $cell) {
                $grid[$cell['dia']][$cell['hora']] = $cell['reservas'];
            }
            $h = new Table('Demanda por hora', array_merge(['Día', 'Total', 'Promedio por día'], array_map(static fn (int $x): string => sprintf('%02d:00', $x), $dm['horas'])));
            foreach ($dm['por_dia_semana'] as $d) {
                $h->rows[] = array_merge(
                    [self::DIAS[$d['dia']], $d['reservas'], $d['promedio']],
                    array_map(static fn (int $x): int => $grid[$d['dia']][$x] ?? 0, $dm['horas'])
                );
            }
            $tables[] = $h;
        }

        if ($r['evolucion_mensual'] !== null) {
            $conIng = $r['evolucion_mensual'] !== [] && array_key_exists('ingresos', $r['evolucion_mensual'][0]);
            $m = new Table('Evolución mensual', array_merge(['Mes', 'Reservas', 'Atendidas', 'Canceladas', 'No asistió'], $conIng ? ['Ingresos registrados'] : []));
            foreach ($r['evolucion_mensual'] as $x) {
                $m->rows[] = array_merge([$x['mes'], $x['reservas'], $x['completada'], $x['cancelada'], $x['no_asistio']], $conIng ? [self::num($x['ingresos'])] : []);
            }
            $tables[] = $m;
        }

        if ($r['ingresos'] !== null) {
            $im = new Table('Ingresos por método', ['Método de pago', 'Monto registrado']);
            foreach ($r['ingresos']['por_metodo'] as $x) {
                $im->rows[] = [self::METODOS[$x['metodo']] ?? $x['metodo'], self::num($x['monto'])];
            }
            $tables[] = $im;
        }

        if (!empty($cl['top'])) {
            $tc = new Table('Clientes recurrentes', ['Cliente', 'Visitas atendidas en el periodo', 'Con visitas anteriores']);
            foreach ($cl['top'] as $x) {
                $tc->rows[] = [$x['nombre'], $x['visitas'], $x['con_visita_previa'] ? 'Sí' : 'No'];
            }
            $tables[] = $tc;
        }
        return $tables;
    }

    /**
     * @param list<array<string, mixed>> $rows ReportRepository::detail()
     */
    public static function detail(array $rows, string $tz, bool $clientes, bool $finanzas): Table
    {
        $t = new Table('Reservas', array_merge(
            ['Código', 'Fecha', 'Hora', 'Sucursal', 'Barbero'],
            $clientes ? ['Cliente'] : [],
            ['Servicios', 'Estado', 'Canal', 'Duración (min)', 'Importe previsto'],
            $finanzas ? ['Cobrado', 'Saldo'] : [],
            ['Confirmada por la barbería', 'Asistencia confirmada por el cliente', 'Reprogramaciones', 'Cancelada por']
        ));
        foreach ($rows as $r) {
            $local = Clock::utcToLocal((string) $r['inicio_utc'], $tz);
            $precio = (float) $r['precio_total'];
            $cobrado = (float) $r['cobrado'];
            $t->rows[] = array_merge(
                [(string) $r['codigo'], $local->format('Y-m-d'), $local->format('H:i'), (string) $r['sucursal'], (string) $r['barbero']],
                $clientes ? [(string) $r['cliente']] : [],
                [(string) ($r['servicios'] ?? ''), self::ESTADOS[$r['estado']] ?? (string) $r['estado'], self::CANALES[$r['canal']] ?? (string) $r['canal'], (int) $r['duracion_total_minutos'], $precio],
                $finanzas ? [$cobrado, $r['estado'] === 'completada' ? round(max(0.0, $precio - $cobrado), 2) : null] : [],
                [$r['confirmada_en'] !== null ? 'Sí' : 'No', $r['asistencia_confirmada_en'] !== null ? 'Sí' : 'No', (int) $r['reprogramaciones'],
                    $r['estado'] === 'cancelada' ? (self::CANCELA[$r['cancelada_por_tipo'] ?? 'sistema'] ?? null) : null]
            );
        }
        return $t;
    }

    private static function num(string|int|float|null $v): ?float
    {
        return $v === null ? null : round((float) $v, 2);
    }
}
