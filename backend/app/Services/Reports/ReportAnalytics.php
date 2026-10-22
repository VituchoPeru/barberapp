<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Helpers\Clock;
use App\Services\Booking\Availability;
use DateTimeImmutable;

/**
 * Cálculos de analítica de la barbería (Fase 11) sobre filas ya filtradas por tenant, rango,
 * sucursal y barbero. Son funciones puras (sin base de datos) para poder probarlas aisladas.
 *
 * Reglas comunes:
 *   - "Activas" = reservas no canceladas (pendiente, confirmada, completada, no_asistio).
 *   - Las horas y días se calculan en la zona horaria del reporte (sucursal o barbería).
 *   - El importe previsto (precio de la cita) nunca se suma como ingreso: los ingresos salen
 *     solo de cobros registrados no anulados.
 */
final class ReportAnalytics
{
    private const ACTIVAS = ['pendiente', 'confirmada', 'completada', 'no_asistio'];

    /**
     * Confirmación de reservas: por la barbería (confirmada_en) y por el cliente (asistencia).
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function confirmations(array $rows): array
    {
        $base = 0;
        $conf = 0;
        $asist = 0;
        $sinConfirmar = 0;
        foreach ($rows as $r) {
            if (!in_array($r['estado'], self::ACTIVAS, true)) {
                continue;
            }
            $base++;
            if ($r['confirmada_en'] !== null) {
                $conf++;
            }
            if ($r['asistencia_confirmada_en'] !== null) {
                $asist++;
            }
            if ($r['estado'] === 'pendiente') {
                $sinConfirmar++;
            }
        }
        return [
            'reservas_activas' => $base,
            'confirmadas' => $conf,
            'tasa_confirmacion' => self::rate($conf, $base),
            'asistencia_confirmada_cliente' => $asist,
            'tasa_asistencia_confirmada' => self::rate($asist, $base),
            'pendientes_sin_confirmar' => $sinConfirmar,
        ];
    }

    /**
     * Demanda por día de la semana (1 = lunes … 7 = domingo) y hora de inicio. El promedio divide
     * entre las veces que ese día de la semana aparece en el rango, para comparar días con justicia.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function demand(array $rows, string $tz, string $desde, string $hasta): array
    {
        $ocurrencias = array_fill(1, 7, 0);
        for ($d = new DateTimeImmutable($desde); $d->format('Y-m-d') <= $hasta; $d = $d->modify('+1 day')) {
            $ocurrencias[(int) $d->format('N')]++;
        }
        $celdas = [];
        $porDia = array_fill(1, 7, 0);
        $porHora = [];
        $total = 0;
        foreach ($rows as $r) {
            if (!in_array($r['estado'], self::ACTIVAS, true)) {
                continue;
            }
            $local = Clock::utcToLocal((string) $r['inicio_utc'], $tz);
            $dia = (int) $local->format('N');
            $hora = (int) $local->format('G');
            $celdas[$dia][$hora] = ($celdas[$dia][$hora] ?? 0) + 1;
            $porDia[$dia]++;
            $porHora[$hora] = ($porHora[$hora] ?? 0) + 1;
            $total++;
        }
        // Franja visible: 08–20 h por defecto, ampliada si hay citas fuera.
        $min = min(8, $porHora === [] ? 8 : min(array_keys($porHora)));
        $max = max(20, $porHora === [] ? 20 : max(array_keys($porHora)));
        $horas = range($min, $max);

        $lista = [];
        $picos = [];
        foreach ($celdas as $dia => $hs) {
            foreach ($hs as $hora => $n) {
                $item = ['dia' => $dia, 'hora' => $hora, 'reservas' => $n, 'promedio' => self::avg($n, $ocurrencias[$dia])];
                $lista[] = $item;
                $picos[] = $item;
            }
        }
        usort($lista, static fn (array $a, array $b): int => [$a['dia'], $a['hora']] <=> [$b['dia'], $b['hora']]);
        usort($picos, static fn (array $a, array $b): int => [$b['reservas'], $a['dia'], $a['hora']] <=> [$a['reservas'], $b['dia'], $b['hora']]);

        $hs = array_fill_keys($horas, 0);
        foreach ($porHora as $h => $n) {
            $hs[$h] = $n;
        }
        return [
            'total' => $total,
            'horas' => $horas,
            'celdas' => $lista,
            'max_celda' => $lista === [] ? 0 : max(array_column($lista, 'reservas')),
            'por_dia_semana' => array_map(static fn (int $d): array => [
                'dia' => $d, 'reservas' => $porDia[$d], 'dias_en_rango' => $ocurrencias[$d], 'promedio' => self::avg($porDia[$d], $ocurrencias[$d]),
            ], range(1, 7)),
            'por_hora' => array_map(static fn (int $h, int $n): array => ['hora' => $h, 'reservas' => $n], array_keys($hs), $hs),
            'picos' => array_slice($picos, 0, 3),
        ];
    }

    /**
     * Productividad por barbero.
     *
     * @param list<array<string, mixed>>                $rows      reservas del rango
     * @param array<int, array{nombre:string, activo:bool}> $barbers
     * @param array<int, array<string, mixed>>|null     $occupancy barbero_id => fila de ocupación (plan avanzado)
     * @param array<int, float>|null                    $paid      reserva_id => cobrado (solo con reportes.financieros)
     * @param list<array<string, mixed>>|null           $payments  cobros del rango por fecha de cobro (financieros)
     * @return list<array<string, mixed>>
     */
    public static function productivity(array $rows, array $barbers, ?array $occupancy, ?array $paid, ?array $payments): array
    {
        $acc = [];
        $blank = static fn (): array => [
            'completada' => 0, 'no_asistio' => 0, 'cancelada' => 0, 'por_atender' => 0, 'clientes' => [],
            'minutos' => 0, 'real_sum' => 0, 'real_n' => 0, 'previsto' => 0.0, 'cobrado_at' => 0.0, 'con_cobro' => 0, 'ingresos' => 0.0, 'cobros' => 0,
        ];
        foreach ($rows as $r) {
            $b = (int) $r['barbero_id'];
            $acc[$b] ??= $blank();
            $e = (string) $r['estado'];
            if ($e === 'pendiente' || $e === 'confirmada') {
                $acc[$b]['por_atender']++;
                continue;
            }
            $acc[$b][$e]++;
            if ($e !== 'completada') {
                continue;
            }
            $acc[$b]['clientes'][(int) $r['cliente_id']] = true;
            $acc[$b]['minutos'] += (int) $r['duracion_total_minutos'];
            $acc[$b]['previsto'] += (float) $r['precio_total'];
            if ($r['atencion_iniciada_en'] !== null && $r['atencion_finalizada_en'] !== null) {
                $min = intdiv(Availability::ts((string) $r['atencion_finalizada_en']) - Availability::ts((string) $r['atencion_iniciada_en']), 60);
                if ($min > 0 && $min <= 600) {
                    $acc[$b]['real_sum'] += $min;
                    $acc[$b]['real_n']++;
                }
            }
            if ($paid !== null && ($paid[(int) $r['id']] ?? 0.0) > 0) {
                $acc[$b]['cobrado_at'] += $paid[(int) $r['id']];
                $acc[$b]['con_cobro']++;
            }
        }
        foreach ($payments ?? [] as $p) {
            $b = (int) $p['barbero_id'];
            $acc[$b] ??= $blank();
            $acc[$b]['ingresos'] += (float) $p['monto'];
            $acc[$b]['cobros']++;
        }
        foreach (array_keys($occupancy ?? []) as $b) {
            $acc[$b] ??= $blank();
        }

        $out = [];
        foreach ($acc as $b => $a) {
            $occ = $occupancy[$b] ?? null;
            $row = [
                'barbero_id' => $b,
                'nombre' => $barbers[$b]['nombre'] ?? ('Barbero #' . $b),
                'activo' => $barbers[$b]['activo'] ?? false,
                'atendidas' => $a['completada'],
                'no_asistio' => $a['no_asistio'],
                'canceladas' => $a['cancelada'],
                'por_atender' => $a['por_atender'],
                'clientes_atendidos' => count($a['clientes']),
                'minutos_atendidos' => $a['minutos'],
                'duracion_real_promedio' => $a['real_n'] > 0 ? (int) round($a['real_sum'] / $a['real_n']) : null,
                'tasa_inasistencia' => self::rate($a['no_asistio'], $a['completada'] + $a['no_asistio']),
                'importe_previsto_atendidas' => self::money($a['previsto']),
                'ocupacion' => $occ['porcentaje'] ?? null,
                'minutos_jornada' => $occ !== null ? (int) $occ['minutos_disponibles'] : null,
                'atendidas_por_hora' => $occ !== null && $occ['minutos_disponibles'] > 0 ? round($a['completada'] * 60 / $occ['minutos_disponibles'], 2) : null,
            ];
            if ($paid !== null) {
                $row['ingresos_registrados'] = self::money($a['ingresos']);
                $row['cobros'] = $a['cobros'];
                $row['cobrado_atendidas'] = self::money($a['cobrado_at']);
                $row['ticket_promedio'] = $a['con_cobro'] > 0 ? self::money($a['cobrado_at'] / $a['con_cobro']) : null;
            }
            $out[] = $row;
        }
        usort($out, static fn (array $x, array $y): int => [$y['atendidas'], $x['nombre']] <=> [$x['atendidas'], $y['nombre']]);
        return $out;
    }

    /**
     * Clientes atendidos en el periodo: nuevos (sin visita atendida previa) y recurrentes
     * (con visita previa o con dos o más visitas en el periodo).
     *
     * @param list<array<string, mixed>> $rows
     * @param array<int, true>           $prior clientes con visita atendida antes del periodo
     * @param array<int, string>|null    $names nombres (solo con clientes.ver) para el ranking
     * @return array<string, mixed>
     */
    public static function retention(array $rows, array $prior, ?array $names): array
    {
        $visitas = [];
        foreach ($rows as $r) {
            if ($r['estado'] === 'completada') {
                $c = (int) $r['cliente_id'];
                $visitas[$c] = ($visitas[$c] ?? 0) + 1;
            }
        }
        $atendidos = count($visitas);
        $nuevos = 0;
        $recurrentes = 0;
        $previa = 0;
        $repiten = 0;
        $dist = ['1' => 0, '2' => 0, '3+' => 0];
        foreach ($visitas as $c => $n) {
            $tienePrevia = isset($prior[$c]);
            $tienePrevia ? $previa++ : $nuevos++;
            if ($n >= 2) {
                $repiten++;
            }
            if ($tienePrevia || $n >= 2) {
                $recurrentes++;
            }
            $dist[$n >= 3 ? '3+' : (string) $n]++;
        }
        $out = [
            'atendidos' => $atendidos,
            'nuevos' => $nuevos,
            'recurrentes' => $recurrentes,
            'con_visita_previa' => $previa,
            'repiten_en_periodo' => $repiten,
            'tasa_recurrencia' => self::rate($recurrentes, $atendidos),
            'visitas_promedio' => $atendidos > 0 ? round(array_sum($visitas) / $atendidos, 2) : null,
            'distribucion_visitas' => $dist,
            'top' => null,
        ];
        if ($names !== null) {
            $top = array_filter($visitas, static fn (int $n): bool => $n >= 2);
            arsort($top);
            $out['top'] = array_map(static fn (int $c, int $n): array => [
                'cliente_id' => $c, 'nombre' => $names[$c] ?? 'Cliente', 'visitas' => $n, 'con_visita_previa' => isset($prior[$c]),
            ], array_keys(array_slice($top, 0, 10, true)), array_slice($top, 0, 10, true));
        }
        return $out;
    }

    /**
     * Ingresos: lo cobrado por día y por barbero (fecha de cobro) y, para las citas atendidas del
     * periodo, el importe previsto frente a lo cobrado por ellas y el saldo que falta cobrar.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $payments cobros del rango
     * @param array<int, float>          $paid     reserva_id => cobrado
     * @param array<int, array{nombre:string, activo:bool}> $barbers
     * @return array<string, mixed>
     */
    public static function income(array $rows, array $payments, array $paid, array $barbers, string $tz, string $desde, string $hasta): array
    {
        $porDia = [];
        for ($d = new DateTimeImmutable($desde); $d->format('Y-m-d') <= $hasta; $d = $d->modify('+1 day')) {
            $porDia[$d->format('Y-m-d')] = ['monto' => 0.0, 'cobros' => 0];
        }
        $porBarbero = [];
        foreach ($payments as $p) {
            $f = Clock::utcToLocal((string) $p['pagado_en'], $tz)->format('Y-m-d');
            if (isset($porDia[$f])) {
                $porDia[$f]['monto'] += (float) $p['monto'];
                $porDia[$f]['cobros']++;
            }
            $b = (int) $p['barbero_id'];
            $porBarbero[$b] = ($porBarbero[$b] ?? 0.0) + (float) $p['monto'];
        }
        arsort($porBarbero);
        $previsto = 0.0;
        $cobrado = 0.0;
        $saldo = 0.0;
        $sinCobro = 0;
        $atendidas = 0;
        foreach ($rows as $r) {
            if ($r['estado'] !== 'completada') {
                continue;
            }
            $atendidas++;
            $precio = (float) $r['precio_total'];
            $pagado = $paid[(int) $r['id']] ?? 0.0;
            $previsto += $precio;
            $cobrado += $pagado;
            $saldo += max(0.0, $precio - $pagado);
            if ($pagado <= 0) {
                $sinCobro++;
            }
        }
        return [
            'por_dia' => array_map(static fn (string $f, array $v): array => ['fecha' => $f, 'monto' => self::money($v['monto']), 'cobros' => $v['cobros']], array_keys($porDia), $porDia),
            'por_barbero' => array_map(static fn (int $b, float $v): array => ['barbero_id' => $b, 'nombre' => $barbers[$b]['nombre'] ?? ('Barbero #' . $b), 'monto' => self::money($v)], array_keys($porBarbero), $porBarbero),
            'atendidas' => [
                'citas' => $atendidas,
                'importe_previsto' => self::money($previsto),
                'cobrado' => self::money($cobrado),
                'saldo_por_cobrar' => self::money($saldo),
                'sin_cobro' => $sinCobro,
            ],
        ];
    }

    public static function rate(int $part, int $total): ?float
    {
        return $total > 0 ? round($part * 100 / $total, 1) : null;
    }

    public static function money(float $v): string
    {
        return number_format($v, 2, '.', '');
    }

    private static function avg(int $n, int $occ): ?float
    {
        return $occ > 0 ? round($n / $occ, 2) : null;
    }
}
