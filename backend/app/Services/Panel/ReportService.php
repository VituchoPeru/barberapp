<?php

declare(strict_types=1);

namespace App\Services\Panel;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Helpers\Clock;
use App\Helpers\Validator;
use App\Repositories\AgendaBlockRepository;
use App\Repositories\AgendaRepository;
use App\Repositories\BarberRepository;
use App\Repositories\BranchRepository;
use App\Repositories\ReportRepository;
use App\Services\Booking\Availability;
use App\Services\Schedule\WorkingTime;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Dashboard y reportes operativos del panel (Fase 6). Todo se calcula desde datos reales:
 *   - reservas por estado y día (en la zona horaria de la sucursal o de la barbería);
 *   - ocupación = minutos reservados (pendiente, confirmada, atendida, no asistió) ÷ minutos de
 *     jornada efectiva (WorkingTime: jornada ∩ horario de la sede − descansos − bloqueos);
 *   - ingresos = SOLO cobros registrados (reserva_pagos), nunca el importe previsto de las citas,
 *     y solo para quien tiene reportes.financieros.
 */
final class ReportService
{
    public const MAX_DIAS_REPORTE = 366;
    private const ESTADOS = ['pendiente', 'confirmada', 'completada', 'cancelada', 'no_asistio'];

    private ReportRepository $repo;

    /**
     * @param list<string>          $perms
     * @param array<string, mixed>  $tenant contexto (zona_horaria)
     */
    public function __construct(private int $tenantId, private array $perms, private array $tenant)
    {
        $this->repo = new ReportRepository($tenantId);
    }

    private function financial(): bool
    {
        return in_array('reportes.financieros', $this->perms, true);
    }

    // ================================================================== dashboard

    /** @param array<string, mixed> $query fecha, sucursal_id @return array<string, mixed> */
    public function dashboard(array $query): array
    {
        $q = Validator::validate($query, ['fecha' => 'nullable|date', 'sucursal_id' => 'nullable|integer|min:1']);
        [$sucursalId, $branches, $tz] = $this->scope($q);
        $hoy = Clock::nowUtc()->setTimezone($tz)->format('Y-m-d');
        $fecha = (string) ($q['fecha'] ?? $hoy);
        [$desdeUtc, $hastaUtc] = $this->utcRange($fecha, $fecha, $tz);

        $rows = $this->repo->bookings($desdeUtc, $hastaUtc, $sucursalId, null);
        $estados = $this->countByEstado($rows);
        $enAtencion = count(array_filter($rows, static fn (array $r): bool => $r['atencion_iniciada_en'] !== null && in_array($r['estado'], ['pendiente', 'confirmada'], true)));
        $atendidos = count(array_unique(array_map(static fn (array $r): int => (int) $r['cliente_id'], array_filter($rows, static fn (array $r): bool => $r['estado'] === 'completada'))));

        // Próximos 7 días: citas activas por día.
        $semanaHasta = (new DateTimeImmutable($fecha))->modify('+6 day')->format('Y-m-d');
        [, $semanaHastaUtc] = $this->utcRange($fecha, $semanaHasta, $tz);
        $semana = [];
        for ($d = new DateTimeImmutable($fecha); $d->format('Y-m-d') <= $semanaHasta; $d = $d->modify('+1 day')) {
            $semana[$d->format('Y-m-d')] = 0;
        }
        foreach ($this->repo->bookings($desdeUtc, $semanaHastaUtc, $sucursalId, null) as $r) {
            if (in_array($r['estado'], ['pendiente', 'confirmada', 'completada', 'no_asistio'], true)) {
                $f = Clock::utcToLocal((string) $r['inicio_utc'], $tz->getName())->format('Y-m-d');
                if (isset($semana[$f])) {
                    $semana[$f]++;
                }
            }
        }

        $agenda = new AgendaRepository($this->tenantId);
        $proximas = $fecha === $hoy ? $agenda->upcoming($sucursalId, null, Clock::nowSql(), $hastaUtc, 8) : [];
        $services = $agenda->servicesFor(array_map(static fn (array $r): int => (int) $r['id'], $proximas));

        $ingresos = null;
        if ($this->financial()) {
            $pagos = $this->repo->payments($desdeUtc, $hastaUtc, $sucursalId, null);
            [$mesDesdeUtc] = $this->utcRange(substr($fecha, 0, 7) . '-01', $fecha, $tz);
            $ingresos = [
                'dia' => $this->money(array_sum(array_map(static fn (array $p): float => (float) $p['monto'], $pagos))),
                'cobros_dia' => count($pagos),
                'mes' => $this->money(array_sum(array_map(static fn (array $p): float => (float) $p['monto'], $this->repo->payments($mesDesdeUtc, $hastaUtc, $sucursalId, null)))),
                'moneda' => 'PEN',
            ];
        }

        return [
            'fecha' => $fecha,
            'hoy' => $hoy,
            'zona_horaria' => $tz->getName(),
            'sucursal' => $sucursalId === null ? null : ['id' => $sucursalId, 'nombre' => $branches[$sucursalId]['nombre']],
            'sucursales' => $this->branchList($branches),
            'citas' => $estados + [
                'total' => count($rows) - $estados['cancelada'],
                'en_atencion' => $enAtencion,
                'clientes_atendidos' => $atendidos,
            ],
            'ocupacion' => $this->occupancy($fecha, $fecha, $sucursalId, null, $rows, $branches),
            'ingresos' => $ingresos,
            'pendientes_confirmar' => $this->repo->pendingToConfirm(Clock::nowSql(), $sucursalId),
            'solicitudes_bloqueo' => $this->repo->pendingBlockRequests(Clock::nowSql()),
            'proximas' => array_map(fn (array $r): array => PanelBookingPresenter::present($r, $services[(int) $r['id']] ?? [], $this->perms), $proximas),
            'semana' => array_map(static fn (string $f, int $n): array => ['fecha' => $f, 'citas' => $n], array_keys($semana), $semana),
        ];
    }

    // ================================================================== reportes

    /** @param array<string, mixed> $query desde, hasta, sucursal_id, barbero_id @return array<string, mixed> */
    public function report(array $query): array
    {
        $q = Validator::validate($query, [
            'desde' => 'nullable|date', 'hasta' => 'nullable|date', 'sucursal_id' => 'nullable|integer|min:1', 'barbero_id' => 'nullable|integer|min:1',
        ]);
        [$sucursalId, $branches, $tz] = $this->scope($q);
        $barberoId = isset($q['barbero_id']) ? (int) $q['barbero_id'] : null;
        $hoy = Clock::nowUtc()->setTimezone($tz)->format('Y-m-d');
        $hasta = (string) ($q['hasta'] ?? $hoy);
        $desde = (string) ($q['desde'] ?? (new DateTimeImmutable($hasta))->modify('-29 day')->format('Y-m-d'));
        if ($hasta < $desde) {
            throw new ValidationException(['hasta' => ['La fecha final debe ser igual o posterior a la inicial.']]);
        }
        if ((new DateTimeImmutable($desde))->diff(new DateTimeImmutable($hasta))->days >= self::MAX_DIAS_REPORTE) {
            throw new ValidationException(['hasta' => ['El rango máximo es de ' . self::MAX_DIAS_REPORTE . ' días.']]);
        }
        [$desdeUtc, $hastaUtc] = $this->utcRange($desde, $hasta, $tz);
        $tzName = $tz->getName();
        $rows = $this->repo->bookings($desdeUtc, $hastaUtc, $sucursalId, $barberoId);
        $estados = $this->countByEstado($rows);

        // Reservas por día (todas las fechas del rango, aunque no tengan citas).
        $porDia = [];
        for ($d = new DateTimeImmutable($desde); $d->format('Y-m-d') <= $hasta; $d = $d->modify('+1 day')) {
            $porDia[$d->format('Y-m-d')] = array_fill_keys(self::ESTADOS, 0);
        }
        $cancelPor = ['cliente' => 0, 'personal' => 0, 'sistema' => 0];
        $porBarbero = [];
        $previsto = 0.0;
        foreach ($rows as $r) {
            $f = Clock::utcToLocal((string) $r['inicio_utc'], $tzName)->format('Y-m-d');
            if (isset($porDia[$f])) {
                $porDia[$f][$r['estado']]++;
            }
            if ($r['estado'] === 'cancelada') {
                $cancelPor[$r['cancelada_por_tipo'] ?? 'sistema']++;
            }
            if ($r['estado'] === 'completada') {
                $previsto += (float) $r['precio_total'];
            }
            $b = (int) $r['barbero_id'];
            $porBarbero[$b] ??= array_fill_keys(self::ESTADOS, 0);
            $porBarbero[$b][$r['estado']]++;
        }
        $ocupacion = $this->occupancy($desde, $hasta, $sucursalId, $barberoId, $rows, $branches);
        foreach ($ocupacion['barberos'] as &$ob) {
            $c = $porBarbero[$ob['barbero_id']] ?? array_fill_keys(self::ESTADOS, 0);
            $ob['citas'] = $c;
            $ob['tasa_inasistencia'] = $this->rate($c['no_asistio'], $c['completada'] + $c['no_asistio']);
        }
        unset($ob);

        $total = count($rows);
        $out = [
            'rango' => ['desde' => $desde, 'hasta' => $hasta, 'zona_horaria' => $tzName],
            'filtros' => ['sucursal_id' => $sucursalId, 'barbero_id' => $barberoId],
            'sucursales' => $this->branchList($branches),
            'totales' => $estados + [
                'reservas' => $total,
                'clientes_atendidos' => count(array_unique(array_map(static fn (array $r): int => (int) $r['cliente_id'], array_filter($rows, static fn (array $r): bool => $r['estado'] === 'completada')))),
                'clientes_nuevos' => $sucursalId === null && $barberoId === null ? $this->repo->newClients($desdeUtc, $hastaUtc) : null,
                'tasa_cancelacion' => $this->rate($estados['cancelada'], $total),
                'tasa_inasistencia' => $this->rate($estados['no_asistio'], $estados['completada'] + $estados['no_asistio']),
                'importe_previsto_atendidas' => $this->money($previsto),
            ],
            'por_dia' => array_map(static fn (string $f, array $c): array => ['fecha' => $f] + $c, array_keys($porDia), $porDia),
            'servicios' => array_map(fn (array $s): array => [
                'servicio_id' => (int) $s['servicio_id'], 'nombre' => (string) $s['nombre'], 'cantidad' => (int) $s['cantidad'],
                'atendidos' => (int) $s['atendidos'], 'importe_previsto' => $this->money((float) $s['importe_previsto']),
            ], $this->repo->topServices($desdeUtc, $hastaUtc, $sucursalId, $barberoId)),
            'ocupacion' => $ocupacion,
            'cancelaciones' => ['total' => $estados['cancelada'], 'por' => $cancelPor, 'tasa' => $this->rate($estados['cancelada'], $total)],
            'inasistencias' => ['total' => $estados['no_asistio'], 'tasa' => $this->rate($estados['no_asistio'], $estados['completada'] + $estados['no_asistio'])],
            'evolucion_mensual' => $this->monthly($hasta, $tz, $sucursalId, $barberoId),
            'ingresos' => null,
        ];
        if ($this->financial()) {
            $pagos = $this->repo->payments($desdeUtc, $hastaUtc, $sucursalId, $barberoId);
            $porMetodo = [];
            foreach ($pagos as $p) {
                $porMetodo[(string) $p['metodo']] = ($porMetodo[(string) $p['metodo']] ?? 0) + (float) $p['monto'];
            }
            arsort($porMetodo);
            $out['ingresos'] = [
                'registrados' => $this->money(array_sum($porMetodo)),
                'cobros' => count($pagos),
                'por_metodo' => array_map(fn (string $m, float $v): array => ['metodo' => $m, 'monto' => $this->money($v)], array_keys($porMetodo), $porMetodo),
                'moneda' => 'PEN',
            ];
        }
        return $out;
    }

    /**
     * Últimos 12 meses hasta el mes de $hasta: reservas, atendidas, canceladas, inasistencias
     * (e ingresos registrados con reportes.financieros).
     *
     * @return list<array<string, mixed>>
     */
    private function monthly(string $hasta, DateTimeZone $tz, ?int $sucursalId, ?int $barberoId): array
    {
        $fin = new DateTimeImmutable(substr($hasta, 0, 7) . '-01');
        $ini = $fin->modify('-11 month');
        $meses = [];
        for ($m = $ini; $m <= $fin; $m = $m->modify('+1 month')) {
            $meses[$m->format('Y-m')] = ['mes' => $m->format('Y-m'), 'reservas' => 0, 'completada' => 0, 'cancelada' => 0, 'no_asistio' => 0];
        }
        [$desdeUtc] = $this->utcRange($ini->format('Y-m-d'), $ini->format('Y-m-d'), $tz);
        [, $hastaUtc] = $this->utcRange($fin->modify('last day of this month')->format('Y-m-d'), $fin->modify('last day of this month')->format('Y-m-d'), $tz);
        foreach ($this->repo->bookings($desdeUtc, $hastaUtc, $sucursalId, $barberoId) as $r) {
            $k = Clock::utcToLocal((string) $r['inicio_utc'], $tz->getName())->format('Y-m');
            if (!isset($meses[$k])) {
                continue;
            }
            $meses[$k]['reservas']++;
            if (isset($meses[$k][$r['estado']])) {
                $meses[$k][$r['estado']]++;
            }
        }
        if ($this->financial()) {
            foreach ($meses as &$m) {
                $m['ingresos'] = 0.0;
            }
            unset($m);
            foreach ($this->repo->payments($desdeUtc, $hastaUtc, $sucursalId, $barberoId) as $p) {
                $k = Clock::utcToLocal((string) $p['pagado_en'], $tz->getName())->format('Y-m');
                if (isset($meses[$k])) {
                    $meses[$k]['ingresos'] += (float) $p['monto'];
                }
            }
            foreach ($meses as &$m) {
                $m['ingresos'] = $this->money($m['ingresos']);
            }
            unset($m);
        }
        return array_values($meses);
    }

    // ================================================================== ocupación

    /**
     * @param list<array<string, mixed>> $rows reservas del rango
     * @param array<int, array<string, mixed>> $branches
     * @return array{porcentaje: ?float, minutos_disponibles: int, minutos_reservados: int, barberos: list<array<string, mixed>>}
     */
    private function occupancy(string $desde, string $hasta, ?int $sucursalId, ?int $barberoId, array $rows, array $branches): array
    {
        $active = array_filter($branches, static fn (array $b): bool => $b['activo']);
        $map = $sucursalId !== null ? array_intersect_key($active, [$sucursalId => true]) : $active;
        $barbersRepo = new BarberRepository($this->tenantId);
        $blocks = new AgendaBlockRepository($this->tenantId);
        $tz = new DateTimeZone('UTC');
        $fromUtc = (new DateTimeImmutable($desde, $tz))->modify('-1 day')->format('Y-m-d H:i:s');
        $toUtc = (new DateTimeImmutable($hasta, $tz))->modify('+2 day')->format('Y-m-d H:i:s');

        $reservados = [];
        foreach ($rows as $r) {
            if ($r['estado'] !== 'cancelada') {
                $reservados[(int) $r['barbero_id']] = ($reservados[(int) $r['barbero_id']] ?? 0)
                    + intdiv(Availability::ts((string) $r['fin_utc']) - Availability::ts((string) $r['inicio_utc']), 60);
            }
        }
        $out = [];
        $totalDisp = 0;
        $totalRes = 0;
        foreach ($barbersRepo->all() as $b) {
            $id = (int) $b['id'];
            if ($barberoId !== null && $id !== $barberoId) {
                continue;
            }
            $disp = 0;
            if ($b['activo']) {
                foreach (WorkingTime::windows($barbersRepo->shifts($id), $barbersRepo->breaks($id), $map, $blocks->approvedFor($id, $fromUtc, $toUtc), $desde, $hasta) as $w) {
                    $disp += $w['minutos'];
                }
            }
            $res = $reservados[$id] ?? 0;
            if ($disp === 0 && $res === 0) {
                continue; // no trabaja en esta sucursal ni tuvo citas aquí
            }
            $out[] = [
                'barbero_id' => $id,
                'nombre' => (string) $b['nombre_publico'],
                'minutos_disponibles' => $disp,
                'minutos_reservados' => $res,
                'porcentaje' => $disp > 0 ? round(min(100, $res * 100 / $disp), 1) : null,
            ];
            $totalDisp += $disp;
            $totalRes += $res;
        }
        return [
            'porcentaje' => $totalDisp > 0 ? round(min(100, $totalRes * 100 / $totalDisp), 1) : null,
            'minutos_disponibles' => $totalDisp,
            'minutos_reservados' => $totalRes,
            'barberos' => $out,
        ];
    }

    // ================================================================== utilidades

    /** @param array<string, mixed> $q @return array{0:?int, 1:array<int, array<string, mixed>>, 2:DateTimeZone} */
    private function scope(array $q): array
    {
        $branches = (new BranchRepository($this->tenantId))->scheduleMap(false);
        $sucursalId = isset($q['sucursal_id']) ? (int) $q['sucursal_id'] : null;
        if ($sucursalId !== null && !isset($branches[$sucursalId])) {
            throw new NotFoundException('Sucursal no encontrada.');
        }
        $tz = WorkingTime::zone($sucursalId !== null ? (string) $branches[$sucursalId]['zona_horaria'] : (string) ($this->tenant['zona_horaria'] ?? 'America/Lima'));
        return [$sucursalId, $branches, $tz];
    }

    /** @return array{0:string, 1:string} [desde 00:00 local, día siguiente a hasta 00:00 local) en UTC */
    private function utcRange(string $desde, string $hasta, DateTimeZone $tz): array
    {
        $utc = new DateTimeZone('UTC');
        return [
            (new DateTimeImmutable($desde . ' 00:00:00', $tz))->setTimezone($utc)->format('Y-m-d H:i:s'),
            (new DateTimeImmutable($hasta . ' 00:00:00', $tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /** @param list<array<string, mixed>> $rows @return array<string, int> */
    private function countByEstado(array $rows): array
    {
        $out = array_fill_keys(self::ESTADOS, 0);
        foreach ($rows as $r) {
            $out[(string) $r['estado']]++;
        }
        return $out;
    }

    /** @param array<int, array<string, mixed>> $branches @return list<array<string, mixed>> */
    private function branchList(array $branches): array
    {
        return array_map(static fn (int $id, array $b): array => ['id' => $id, 'nombre' => $b['nombre'], 'activo' => $b['activo']], array_keys($branches), $branches);
    }

    private function rate(int $part, int $total): ?float
    {
        return $total > 0 ? round($part * 100 / $total, 1) : null;
    }

    private function money(float $v): string
    {
        return number_format($v, 2, '.', '');
    }
}
