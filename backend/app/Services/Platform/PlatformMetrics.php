<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Helpers\Clock;
use App\Repositories\BaseRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Indicadores globales del SaaS (Fase 9). Todo se calcula con consultas sobre los datos reales;
 * nada se estima ni se rellena. Las barberías de demostración (tenants.es_demo) se cuentan
 * aparte y pueden excluirse con $excluirDemo.
 *
 * Solo se exponen cifras agregadas por barbería o por plan: nunca nombres, correos ni
 * teléfonos de los clientes de las barberías (el superadministrador no tiene acceso por
 * defecto a esos datos).
 *
 * Definiciones (ver docs/SUPERADMIN.md):
 *  - Suscripción vigente: inicio_en ≤ t < fin efectivo, con fin efectivo = el menor entre
 *    periodo_fin_en y terminada_en.
 *  - Estado de suscripción de una barbería: el de su suscripción más reciente.
 *  - MRR: suma mensualizada (anual ÷ 12) de precio_pactado de las suscripciones `activa` con
 *    periodo vigente. Las pruebas gratuitas no suman.
 *  - Churn (30 días): de las suscripciones de pago vigentes hace 30 días, cuántas ya no lo
 *    están y su barbería no tiene otra vigente hoy.
 */
final class PlatformMetrics extends BaseRepository
{
    private const FIN = 'LEAST(s.periodo_fin_en, COALESCE(s.terminada_en, s.periodo_fin_en))';

    private string $tz;

    public function __construct(private bool $excluirDemo = false)
    {
        parent::__construct();
        $this->tz = PlatformSettings::timezone();
    }

    private function demo(string $alias = 't'): string
    {
        return $this->excluirDemo ? " AND {$alias}.es_demo = 0" : '';
    }

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $now = Clock::nowUtc();
        $nowSql = $now->format('Y-m-d H:i:s');
        $d30 = $now->modify('-30 days')->format('Y-m-d H:i:s');
        $d60 = $now->modify('-60 days')->format('Y-m-d H:i:s');

        // ---------- Barberías
        $porEstado = ['pendiente' => 0, 'activo' => 0, 'suspendido' => 0, 'cancelado' => 0];
        foreach ($this->fetchAll('SELECT t.estado, COUNT(*) n FROM tenants t WHERE t.deleted_at IS NULL' . $this->demo() . ' GROUP BY t.estado') as $r) {
            $porEstado[(string) $r['estado']] = (int) $r['n'];
        }
        $altas = $this->fetchOne(
            'SELECT SUM(t.created_at >= :d30) actual, SUM(t.created_at >= :d60 AND t.created_at < :d30b) anterior
               FROM tenants t WHERE t.deleted_at IS NULL AND t.created_at >= :d60b' . $this->demo(),
            ['d30' => $d30, 'd60' => $d60, 'd30b' => $d30, 'd60b' => $d60]
        ) ?? [];

        // ---------- Suscripciones (estado de la más reciente de cada barbería)
        $subs = $this->latestSubscriptions();
        $s = ['activas' => 0, 'en_prueba' => 0, 'vencidas' => 0, 'suspendidas' => 0, 'canceladas' => 0, 'vencen_7_dias' => 0, 'sin_suscripcion' => 0];
        $mrr = [];
        $in7 = $now->modify('+7 days')->format('Y-m-d H:i:s');
        foreach ($subs as $row) {
            $vigente = in_array($row['estado'], ['prueba', 'activa'], true) && $row['periodo_fin_en'] >= $nowSql;
            if ($row['estado'] === 'activa' && $vigente) {
                $s['activas']++;
                $mrr[$row['moneda']] = ($mrr[$row['moneda']] ?? 0.0) + self::monthly($row);
            } elseif ($row['estado'] === 'prueba' && $vigente) {
                $s['en_prueba']++;
            } elseif (in_array($row['estado'], ['prueba', 'activa', 'vencida'], true)) {
                $s['vencidas']++;
            } elseif ($row['estado'] === 'suspendida') {
                $s['suspendidas']++;
            } else {
                $s['canceladas']++;
            }
            if ($vigente && $row['periodo_fin_en'] <= $in7) {
                $s['vencen_7_dias']++;
            }
        }
        $totalTenants = array_sum($porEstado);
        $s['sin_suscripcion'] = $this->scalarInt(
            "SELECT COUNT(*) FROM tenants t WHERE t.deleted_at IS NULL AND t.estado <> 'cancelado'
                AND NOT EXISTS (SELECT 1 FROM suscripciones s WHERE s.tenant_id = t.id)" . $this->demo()
        );

        // ---------- Ingresos de la plataforma (pagos confirmados) en el mes en curso
        [$mesIni, $mesFin] = $this->monthBounds($now, 0);
        $ingresos = $this->fetchAll(
            "SELECT p.moneda, SUM(p.estado = 'confirmado' AND p.pagado_en >= :a AND p.pagado_en < :b) cobros,
                    COALESCE(SUM(IF(p.estado = 'confirmado' AND p.pagado_en >= :a2 AND p.pagado_en < :b2, p.monto, 0)), 0) total,
                    SUM(p.estado = 'pendiente') pendientes, COALESCE(SUM(IF(p.estado = 'pendiente', p.monto, 0)), 0) por_confirmar
               FROM pagos_saas p JOIN tenants t ON t.id = p.tenant_id
              WHERE (p.estado = 'pendiente' OR (p.estado = 'confirmado' AND p.pagado_en >= :a3 AND p.pagado_en < :b3))" . $this->demo() . '
              GROUP BY p.moneda',
            ['a' => $mesIni, 'b' => $mesFin, 'a2' => $mesIni, 'b2' => $mesFin, 'a3' => $mesIni, 'b3' => $mesFin]
        );

        // ---------- Reservas agregadas (todas las barberías, sin datos de clientes)
        $res = $this->fetchOne(
            "SELECT COUNT(*) total, SUM(r.estado = 'completada') atendidas, SUM(r.estado = 'cancelada') canceladas,
                    SUM(r.estado = 'no_asistio') no_asistio, COUNT(DISTINCT r.tenant_id) barberias
               FROM reservas r JOIN tenants t ON t.id = r.tenant_id
              WHERE r.created_at >= :d30" . $this->demo(),
            ['d30' => $d30]
        ) ?? [];
        $resPrev = (int) $this->scalarInt(
            'SELECT COUNT(*) FROM reservas r JOIN tenants t ON t.id = r.tenant_id WHERE r.created_at >= :a AND r.created_at < :b' . $this->demo(),
            ['a' => $d60, 'b' => $d30]
        );

        return [
            'generado_en' => str_replace(' ', 'T', $nowSql) . 'Z',
            'zona_horaria' => $this->tz,
            'excluye_demo' => $this->excluirDemo,
            'demo' => [
                'barberias' => $this->scalarInt('SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL AND es_demo = 1'),
            ],
            'barberias' => [
                'registradas' => $totalTenants,
                'activas' => $porEstado['activo'],
                'pendientes' => $porEstado['pendiente'],
                'suspendidas' => $porEstado['suspendido'],
                'canceladas' => $porEstado['cancelado'],
                'altas_30_dias' => (int) ($altas['actual'] ?? 0),
                'altas_30_dias_anteriores' => (int) ($altas['anterior'] ?? 0),
            ],
            'suscripciones' => $s,
            'mrr' => self::money($mrr),
            'churn_30_dias' => $this->churn($now->modify('-30 days'), $now),
            'ingresos_mes' => array_map(static fn (array $r): array => [
                'moneda' => (string) $r['moneda'], 'total' => number_format((float) $r['total'], 2, '.', ''), 'cobros' => (int) $r['cobros'],
                'pendientes' => (int) $r['pendientes'], 'por_confirmar' => number_format((float) $r['por_confirmar'], 2, '.', ''),
            ], $ingresos),
            'reservas_30_dias' => [
                'total' => (int) ($res['total'] ?? 0),
                'atendidas' => (int) ($res['atendidas'] ?? 0),
                'canceladas' => (int) ($res['canceladas'] ?? 0),
                'no_asistio' => (int) ($res['no_asistio'] ?? 0),
                'barberias_con_reservas' => (int) ($res['barberias'] ?? 0),
                'periodo_anterior' => $resPrev,
            ],
            'consumo_por_plan' => $this->usageByPlan($subs, $nowSql, $d30),
        ];
    }

    /**
     * Serie mensual de los últimos $meses meses (mes en curso incluido) en la zona horaria de la
     * plataforma, más el ranking de barberías por reservas del periodo.
     *
     * @return array<string, mixed>
     */
    public function report(int $meses): array
    {
        $meses = max(1, min(24, $meses));
        $now = Clock::nowUtc();
        $bounds = [];
        for ($i = $meses - 1; $i >= 0; $i--) {
            $bounds[] = $this->monthBounds($now, -$i);
        }
        $desde = $bounds[0][0];
        $hasta = end($bounds)[1];

        $altas = $this->bucket('SELECT %s FROM tenants t WHERE t.deleted_at IS NULL' . $this->demo(), 't.created_at', $bounds);
        $reservas = $this->bucket('SELECT %s FROM reservas r JOIN tenants t ON t.id = r.tenant_id WHERE r.created_at >= ? AND r.created_at < ?' . $this->demo(), 'r.created_at', $bounds, [$desde, $hasta]);
        $estados = [];
        foreach (['completada' => 'atendidas', 'cancelada' => 'canceladas', 'no_asistio' => 'no_asistio'] as $estado => $k) {
            $estados[$k] = $this->bucket(
                'SELECT %s FROM reservas r JOIN tenants t ON t.id = r.tenant_id WHERE r.estado = ? AND r.inicio_utc >= ? AND r.inicio_utc < ?' . $this->demo(),
                'r.inicio_utc', $bounds, [$estado, $desde, $hasta]
            );
        }
        $subsNuevas = $this->bucket('SELECT %s FROM suscripciones s JOIN tenants t ON t.id = s.tenant_id WHERE s.inicio_en >= ? AND s.inicio_en < ?' . $this->demo(), 's.inicio_en', $bounds, [$desde, $hasta]);
        $bajas = $this->bucket('SELECT %s FROM suscripciones s JOIN tenants t ON t.id = s.tenant_id WHERE s.terminada_en >= ? AND s.terminada_en < ?' . $this->demo(), 's.terminada_en', $bounds, [$desde, $hasta]);
        $ingresos = $this->bucket(
            "SELECT %s FROM pagos_saas p JOIN tenants t ON t.id = p.tenant_id WHERE p.estado = 'confirmado' AND p.moneda = ? AND p.pagado_en >= ? AND p.pagado_en < ?" . $this->demo(),
            'p.pagado_en', $bounds, [(string) PlatformSettings::all()['plataforma.moneda'], $desde, $hasta], 'p.monto'
        );

        $serie = [];
        foreach ($bounds as $i => [$a, $b, $label]) {
            $fin = min($b, $now->format('Y-m-d H:i:s'));
            $serie[] = [
                'mes' => $label,
                'altas_barberias' => $altas[$i],
                'suscripciones_nuevas' => $subsNuevas[$i],
                'bajas_suscripciones' => $bajas[$i],
                'barberias_con_suscripcion_vigente' => $this->vigentesAt($fin),
                'ingresos' => number_format((float) $ingresos[$i], 2, '.', ''),
                'reservas_creadas' => $reservas[$i],
                'citas_atendidas' => $estados['atendidas'][$i],
                'citas_canceladas' => $estados['canceladas'][$i],
                'citas_no_asistio' => $estados['no_asistio'][$i],
            ];
        }

        $top = $this->fetchAll(
            "SELECT t.id, t.slug, t.nombre_comercial, t.estado, t.es_demo, COUNT(r.id) reservas, SUM(r.estado = 'completada') atendidas
               FROM reservas r JOIN tenants t ON t.id = r.tenant_id
              WHERE r.created_at >= ? AND r.created_at < ?" . $this->demo() . '
              GROUP BY t.id, t.slug, t.nombre_comercial, t.estado, t.es_demo
              ORDER BY reservas DESC, t.id LIMIT 10',
            [$desde, $hasta]
        );

        return [
            'zona_horaria' => $this->tz,
            'moneda' => (string) PlatformSettings::all()['plataforma.moneda'],
            'excluye_demo' => $this->excluirDemo,
            'demo' => ['barberias' => $this->scalarInt('SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL AND es_demo = 1')],
            'desde' => str_replace(' ', 'T', $desde) . 'Z',
            'hasta' => str_replace(' ', 'T', $hasta) . 'Z',
            'meses' => $serie,
            'top_barberias' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'], 'slug' => (string) $r['slug'], 'nombre' => (string) $r['nombre_comercial'], 'estado' => (string) $r['estado'],
                'es_demo' => (bool) $r['es_demo'], 'reservas' => (int) $r['reservas'], 'atendidas' => (int) $r['atendidas'],
            ], $top),
        ];
    }

    /** @param array<string, mixed> $report resultado de report() */
    public static function csv(array $report): string
    {
        $cols = ['mes', 'altas_barberias', 'suscripciones_nuevas', 'bajas_suscripciones', 'barberias_con_suscripcion_vigente', 'ingresos', 'reservas_creadas', 'citas_atendidas', 'citas_canceladas', 'citas_no_asistio'];
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, $cols);
        foreach ($report['meses'] as $m) {
            fputcsv($fh, array_map(static fn (string $c) => $m[$c], $cols));
        }
        rewind($fh);
        $out = (string) stream_get_contents($fh);
        fclose($fh);
        return "\xEF\xBB\xBF" . $out; // BOM: Excel abre bien las tildes
    }

    // ------------------------------------------------------------------ internos

    /**
     * Suscripción más reciente de cada barbería (no eliminada).
     *
     * @return list<array<string, mixed>>
     */
    public function latestSubscriptions(): array
    {
        return $this->fetchAll(
            'SELECT s.id, s.tenant_id, s.plan_id, s.estado, s.ciclo, s.precio_pactado, s.moneda, s.periodo_fin_en, t.estado tenant_estado
               FROM suscripciones s
               JOIN (SELECT tenant_id, MAX(id) id FROM suscripciones GROUP BY tenant_id) u ON u.id = s.id
               JOIN tenants t ON t.id = s.tenant_id
              WHERE t.deleted_at IS NULL' . $this->demo()
        );
    }

    /** @param array<string, mixed> $row */
    public static function monthly(array $row): float
    {
        $p = (float) $row['precio_pactado'];
        return $row['ciclo'] === 'anual' ? $p / 12 : $p;
    }

    /**
     * @param array<string, float> $byCurrency
     * @return list<array{moneda:string, total:string}>
     */
    private static function money(array $byCurrency): array
    {
        ksort($byCurrency);
        $out = [];
        foreach ($byCurrency as $m => $v) {
            $out[] = ['moneda' => (string) $m, 'total' => number_format(round($v, 2), 2, '.', '')];
        }
        return $out;
    }

    /** @return array{base:int, perdidas:int, porcentaje:float|null} */
    public function churn(DateTimeImmutable $t0, DateTimeImmutable $t1): array
    {
        $a = $t0->format('Y-m-d H:i:s');
        $b = $t1->format('Y-m-d H:i:s');
        $fin = self::FIN;
        $row = $this->fetchOne(
            "SELECT COUNT(*) base,
                    SUM({$fin} <= :b AND NOT EXISTS (
                        SELECT 1 FROM suscripciones s2
                         WHERE s2.tenant_id = s.tenant_id AND s2.inicio_en <= :b2
                           AND LEAST(s2.periodo_fin_en, COALESCE(s2.terminada_en, s2.periodo_fin_en)) > :b3)) perdidas
               FROM suscripciones s JOIN tenants t ON t.id = s.tenant_id
              WHERE s.inicio_en <= :a AND {$fin} > :a2
                AND (s.prueba_fin_en IS NULL OR s.prueba_fin_en <= :a3)
                AND s.estado <> 'prueba'" . $this->demo(),
            ['a' => $a, 'a2' => $a, 'a3' => $a, 'b' => $b, 'b2' => $b, 'b3' => $b]
        ) ?? [];
        $base = (int) ($row['base'] ?? 0);
        $lost = (int) ($row['perdidas'] ?? 0);
        return ['base' => $base, 'perdidas' => $lost, 'porcentaje' => $base > 0 ? round($lost * 100 / $base, 1) : null];
    }

    private function vigentesAt(string $t): int
    {
        $fin = self::FIN;
        return $this->scalarInt(
            "SELECT COUNT(DISTINCT s.tenant_id) FROM suscripciones s JOIN tenants t ON t.id = s.tenant_id
              WHERE s.inicio_en <= :a AND {$fin} > :b" . $this->demo(),
            ['a' => $t, 'b' => $t]
        );
    }

    /**
     * Uso real de cada plan: barberías con suscripción vigente, MRR, sucursales y barberos
     * activos y reservas de 30 días, y cuántas barberías superan los límites del plan
     * (los límites se aplicarán en la Fase 10; aquí solo se informan).
     *
     * @param list<array<string, mixed>> $subs
     * @return list<array<string, mixed>>
     */
    private function usageByPlan(array $subs, string $nowSql, string $d30): array
    {
        $plans = $this->fetchAll('SELECT id, codigo, nombre, moneda, max_sucursales, max_barberos, activo FROM planes ORDER BY orden, id');
        $vigentes = array_values(array_filter($subs, static fn (array $r): bool => in_array($r['estado'], ['prueba', 'activa'], true) && $r['periodo_fin_en'] >= $nowSql));
        $tenantIds = array_map(static fn (array $r): int => (int) $r['tenant_id'], $vigentes);

        $sucursales = $barberos = $reservas = [];
        if ($tenantIds !== []) {
            $in = implode(',', array_fill(0, count($tenantIds), '?'));
            foreach ($this->fetchAll("SELECT tenant_id, COUNT(*) n FROM sucursales WHERE activo = 1 AND deleted_at IS NULL AND tenant_id IN ({$in}) GROUP BY tenant_id", $tenantIds) as $r) {
                $sucursales[(int) $r['tenant_id']] = (int) $r['n'];
            }
            foreach ($this->fetchAll("SELECT tenant_id, COUNT(*) n FROM barberos WHERE activo = 1 AND deleted_at IS NULL AND tenant_id IN ({$in}) GROUP BY tenant_id", $tenantIds) as $r) {
                $barberos[(int) $r['tenant_id']] = (int) $r['n'];
            }
            foreach ($this->fetchAll("SELECT tenant_id, COUNT(*) n FROM reservas WHERE created_at >= ? AND tenant_id IN ({$in}) GROUP BY tenant_id", array_merge([$d30], $tenantIds)) as $r) {
                $reservas[(int) $r['tenant_id']] = (int) $r['n'];
            }
        }

        $out = [];
        foreach ($plans as $p) {
            $pid = (int) $p['id'];
            $rows = array_values(array_filter($vigentes, static fn (array $r): bool => (int) $r['plan_id'] === $pid));
            $u = ['barberias' => 0, 'en_prueba' => 0, 'mrr' => 0.0, 'sucursales' => 0, 'barberos' => 0, 'reservas_30_dias' => 0, 'exceden_sucursales' => 0, 'exceden_barberos' => 0];
            foreach ($rows as $r) {
                $t = (int) $r['tenant_id'];
                $u['barberias']++;
                if ($r['estado'] === 'prueba') {
                    $u['en_prueba']++;
                } else {
                    $u['mrr'] += self::monthly($r);
                }
                $u['sucursales'] += $sucursales[$t] ?? 0;
                $u['barberos'] += $barberos[$t] ?? 0;
                $u['reservas_30_dias'] += $reservas[$t] ?? 0;
                if ($p['max_sucursales'] !== null && ($sucursales[$t] ?? 0) > (int) $p['max_sucursales']) {
                    $u['exceden_sucursales']++;
                }
                if ($p['max_barberos'] !== null && ($barberos[$t] ?? 0) > (int) $p['max_barberos']) {
                    $u['exceden_barberos']++;
                }
            }
            $u['mrr'] = number_format(round($u['mrr'], 2), 2, '.', '');
            $out[] = [
                'plan' => ['id' => $pid, 'codigo' => (string) $p['codigo'], 'nombre' => (string) $p['nombre'], 'moneda' => (string) $p['moneda'], 'activo' => (bool) $p['activo'],
                    'max_sucursales' => $p['max_sucursales'] === null ? null : (int) $p['max_sucursales'], 'max_barberos' => $p['max_barberos'] === null ? null : (int) $p['max_barberos']],
            ] + $u;
        }
        return $out;
    }

    /**
     * Límites [inicio, fin, 'AAAA-MM'] en UTC del mes local desplazado $offset meses del actual.
     *
     * @return array{0:string, 1:string, 2:string}
     */
    private function monthBounds(DateTimeImmutable $nowUtc, int $offset): array
    {
        $tz = new DateTimeZone($this->tz);
        $local = $nowUtc->setTimezone($tz)->modify('first day of this month')->setTime(0, 0);
        $start = $local->modify(sprintf('%+d months', $offset));
        $end = $start->modify('+1 month');
        $utc = new DateTimeZone('UTC');
        return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s'), $start->format('Y-m')];
    }

    /**
     * Una sola consulta con una columna SUM por mes: "SELECT SUM(col >= ? AND col < ?) m0, …".
     *
     * @param list<array{0:string, 1:string, 2:string}> $bounds
     * @param list<mixed>                                $params parámetros del WHERE (van después)
     * @return list<int|float>
     */
    private function bucket(string $sqlTemplate, string $col, array $bounds, array $params = [], ?string $sumCol = null): array
    {
        $cols = [];
        $p = [];
        foreach ($bounds as $i => [$a, $b]) {
            $cols[] = $sumCol === null
                ? "COALESCE(SUM({$col} >= ? AND {$col} < ?), 0) m{$i}"
                : "COALESCE(SUM(IF({$col} >= ? AND {$col} < ?, {$sumCol}, 0)), 0) m{$i}";
            $p[] = $a;
            $p[] = $b;
        }
        $row = $this->fetchOne(sprintf($sqlTemplate, implode(', ', $cols)), array_merge($p, $params)) ?? [];
        $out = [];
        foreach ($bounds as $i => $_) {
            $out[] = $sumCol === null ? (int) ($row["m{$i}"] ?? 0) : round((float) ($row["m{$i}"] ?? 0), 2);
        }
        return $out;
    }

    /** @param array<string|int, mixed> $params */
    private function scalarInt(string $sql, array $params = []): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}
