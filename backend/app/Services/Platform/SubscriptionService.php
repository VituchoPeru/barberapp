<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Helpers\Clock;
use App\Repositories\BaseRepository;
use App\Services\Audit;
use App\Services\Billing\Entitlements;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;

/**
 * Suscripciones de las barberías a BARBERAPP y sus pagos, gestionadas a mano por el equipo
 * de la plataforma (Fase 9). La pasarela de pagos, la prueba automática al registrarse, los
 * avisos de vencimiento y los límites por plan llegan en la Fase 10.
 *
 * Reglas:
 *  - Una sola suscripción vigente (prueba o activa) por barbería (índice único en la BD).
 *  - Una suscripción que dejó de estar vigente (vencida, suspendida, cancelada) no vuelve a
 *    estarlo: reactivar o renovar después del vencimiento crea una fila nueva. Así el
 *    historial de periodos es exacto y el churn y las bajas por mes salen de datos reales.
 *  - Cambiar el precio de un plan no cambia el precio pactado de las suscripciones existentes.
 *  - Cada cambio queda en suscripcion_eventos y en la auditoría.
 */
final class SubscriptionService extends BaseRepository
{
    public const ESTADOS = ['prueba', 'activa', 'vencida', 'suspendida', 'cancelada'];
    public const METODOS = ['manual', 'transferencia', 'yape', 'plin', 'efectivo'];

    private const SELECT = 'SELECT s.*, t.slug tenant_slug, t.nombre_comercial tenant_nombre, t.es_demo tenant_demo, t.estado tenant_estado,
                                   p.codigo plan_codigo, p.nombre plan_nombre, pp.codigo plan_programado_codigo, pp.nombre plan_programado_nombre
                              FROM suscripciones s
                              JOIN tenants t ON t.id = s.tenant_id
                              JOIN planes p ON p.id = s.plan_id
                              LEFT JOIN planes pp ON pp.id = s.plan_programado_id';

    /** Estado mostrado: una suscripción vigente cuyo periodo ya terminó se muestra como vencida. */
    public static function effectiveState(string $estado, string $periodoFin): string
    {
        if (in_array($estado, ['prueba', 'activa'], true) && $periodoFin <= Clock::nowSql()) {
            return 'vencida';
        }
        return $estado;
    }

    /**
     * @param array<string, mixed> $f estado, plan, q, vence_en_dias, tenant_id
     * @return array{items: list<array<string, mixed>>, total:int}
     */
    public function list(array $f, int $limit, int $offset): array
    {
        $where = ['t.deleted_at IS NULL'];
        $p = [];
        $now = Clock::nowSql();
        if (!empty($f['tenant_id'])) {
            $where[] = 's.tenant_id = :tid';
            $p['tid'] = (int) $f['tenant_id'];
        } elseif (empty($f['historial'])) {
            // Por defecto, la suscripción más reciente de cada barbería.
            $where[] = 's.id = (SELECT MAX(s2.id) FROM suscripciones s2 WHERE s2.tenant_id = s.tenant_id)';
        }
        if (!empty($f['estado'])) {
            switch ($f['estado']) {
                case 'vencida':
                    $where[] = "(s.estado = 'vencida' OR (s.estado IN ('prueba','activa') AND s.periodo_fin_en <= :now))";
                    $p['now'] = $now;
                    break;
                case 'prueba':
                case 'activa':
                    $where[] = 's.estado = :estado AND s.periodo_fin_en > :now';
                    $p += ['estado' => $f['estado'], 'now' => $now];
                    break;
                default:
                    if (in_array($f['estado'], self::ESTADOS, true)) {
                        $where[] = 's.estado = :estado';
                        $p['estado'] = $f['estado'];
                    }
            }
        }
        if (!empty($f['plan'])) {
            $where[] = 'p.codigo = :plan';
            $p['plan'] = (string) $f['plan'];
        }
        if (!empty($f['vence_en_dias'])) {
            $where[] = "s.estado IN ('prueba','activa') AND s.periodo_fin_en > :vnow AND s.periodo_fin_en <= :vhasta";
            $p += ['vnow' => $now, 'vhasta' => Clock::nowUtc()->modify('+' . max(1, min(365, (int) $f['vence_en_dias'])) . ' days')->format('Y-m-d H:i:s')];
        }
        if (!empty($f['q'])) {
            $q = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(trim((string) $f['q']), 0, 80)) . '%';
            $where[] = '(t.nombre_comercial LIKE :q1 OR t.slug LIKE :q2)';
            $p += ['q1' => $q, 'q2' => $q];
        }
        $w = implode(' AND ', $where);
        $base = 'FROM suscripciones s JOIN tenants t ON t.id = s.tenant_id JOIN planes p ON p.id = s.plan_id WHERE ' . $w;
        $total = (int) ($this->fetchOne("SELECT COUNT(*) n {$base}", $p)['n'] ?? 0);
        $rows = $this->fetchAll(self::SELECT . " WHERE {$w} ORDER BY s.periodo_fin_en ASC, s.id DESC LIMIT {$limit} OFFSET {$offset}", $p);
        return ['items' => array_map([self::class, 'present'], $rows), 'total' => $total];
    }

    /** @return list<array<string, mixed>> */
    public function forTenant(int $tenantId): array
    {
        return array_map([self::class, 'present'], $this->fetchAll(self::SELECT . ' WHERE s.tenant_id = :t ORDER BY s.id DESC LIMIT 20', ['t' => $tenantId]));
    }

    /** @return array<string, mixed> */
    public function show(int $id): array
    {
        $row = $this->fetchOne(self::SELECT . ' WHERE s.id = :id AND t.deleted_at IS NULL', ['id' => $id]);
        if ($row === null) {
            throw new NotFoundException('Suscripción no encontrada.');
        }
        $eventos = $this->fetchAll(
            'SELECT e.*, pa.nombre plan_anterior, pn.nombre plan_nuevo, u.email usuario_email
               FROM suscripcion_eventos e
               LEFT JOIN planes pa ON pa.id = e.plan_anterior_id
               LEFT JOIN planes pn ON pn.id = e.plan_nuevo_id
               LEFT JOIN usuarios u ON u.id = e.usuario_id
              WHERE e.tenant_id = :t AND e.suscripcion_id = :s ORDER BY e.id DESC LIMIT 50',
            ['t' => (int) $row['tenant_id'], 's' => $id]
        );
        return self::present($row) + [
            'eventos' => array_map(static fn (array $e): array => [
                'id' => (int) $e['id'], 'tipo' => (string) $e['tipo'], 'fecha' => self::iso((string) $e['created_at']),
                'estado_anterior' => $e['estado_anterior'], 'estado_nuevo' => $e['estado_nuevo'],
                'plan_anterior' => $e['plan_anterior'], 'plan_nuevo' => $e['plan_nuevo'],
                'precio_anterior' => $e['precio_anterior'], 'precio_nuevo' => $e['precio_nuevo'],
                'periodo_fin_anterior' => $e['periodo_fin_anterior'] ? self::iso((string) $e['periodo_fin_anterior']) : null,
                'periodo_fin_nuevo' => $e['periodo_fin_nuevo'] ? self::iso((string) $e['periodo_fin_nuevo']) : null,
                'pago_id' => $e['pago_id'] !== null ? (int) $e['pago_id'] : null, 'por' => $e['usuario_email'], 'notas' => $e['notas'],
            ], $eventos),
            'pagos' => $this->payments(['suscripcion_id' => $id], 50, 0)['items'],
        ];
    }

    /**
     * Alta manual: prueba gratuita o suscripción activa.
     *
     * @param array<string, mixed> $d tenant_id, plan_id, estado, ciclo, precio_pactado?, dias_prueba?, periodos?, notas?
     * @return array<string, mixed>
     */
    public function create(array $d, Request $request): array
    {
        $uid = self::actor($request);
        $id = Database::transaction(function () use ($d, $uid): int {
            $tenant = $this->fetchOne('SELECT id, estado FROM tenants WHERE id = :id AND deleted_at IS NULL FOR UPDATE', ['id' => (int) $d['tenant_id']]);
            if ($tenant === null) {
                throw new ValidationException(['tenant_id' => ['La barbería no existe.']]);
            }
            if ($tenant['estado'] === 'cancelado') {
                throw new HttpException(409, 'tenant_cancelled', 'La barbería está cancelada: no se le puede asignar una suscripción.');
            }
            $plan = $this->plan((int) $d['plan_id'], true);
            $this->expireDue((int) $tenant['id']);
            if ($this->vigente((int) $tenant['id']) !== null) {
                throw new HttpException(409, 'subscription_exists', 'La barbería ya tiene una suscripción vigente. Cambia su plan o renuévala.');
            }
            $ciclo = (string) ($d['ciclo'] ?? 'mensual');
            $estado = (string) $d['estado'];
            $inicio = Clock::nowUtc();
            if ($estado === 'prueba') {
                $dias = (int) ($d['dias_prueba'] ?? 0) ?: max(1, (int) $plan['dias_prueba']);
                $fin = $inicio->modify("+{$dias} days");
            } else {
                $fin = self::addCycles($inicio, $ciclo, (int) ($d['periodos'] ?? 1) ?: 1);
            }
            $precio = isset($d['precio_pactado']) ? (string) $d['precio_pactado'] : self::planPrice($plan, $ciclo);
            return $this->insertSubscription((int) $tenant['id'], $plan, $estado, $ciclo, $precio, $inicio, $fin, $d['notas'] ?? null, $uid, 'alta', null);
        });
        Audit::record('plataforma.suscripcion.creada', 'exito', $request, $uid, (int) $d['tenant_id'], 'suscripciones', $id, ['estado' => $d['estado'], 'plan_id' => (int) $d['plan_id']]);
        return $this->show($id);
    }

    /**
     * @param array<string, mixed> $d plan_id, ciclo?, precio_pactado?, notas?
     * @return array<string, mixed>
     */
    public function changePlan(int $id, array $d, Request $request): array
    {
        $uid = self::actor($request);
        $tenantId = Database::transaction(function () use ($id, $d, $uid): int {
            $s = $this->lock($id);
            $this->assertVigente($s, 'cambiar el plan de');
            $plan = $this->plan((int) $d['plan_id'], true);
            $ciclo = (string) ($d['ciclo'] ?? $s['ciclo']);
            $precio = isset($d['precio_pactado']) ? (string) $d['precio_pactado'] : self::planPrice($plan, $ciclo);
            if ((int) $plan['id'] === (int) $s['plan_id'] && $ciclo === $s['ciclo'] && (float) $precio === (float) $s['precio_pactado']) {
                throw new HttpException(409, 'no_changes', 'La suscripción ya tiene ese plan, ciclo y precio.');
            }
            $this->execute('UPDATE suscripciones SET plan_id = :p, ciclo = :c, precio_pactado = :pr, moneda = :m,
                                   plan_programado_id = NULL, ciclo_programado = NULL, precio_programado = NULL, cambio_programado_en = NULL WHERE id = :id',
                ['p' => (int) $plan['id'], 'c' => $ciclo, 'pr' => $precio, 'm' => (string) $plan['moneda'], 'id' => $id]);
            Entitlements::flush((int) $s['tenant_id']);
            $this->event($s, 'cambio_plan', [
                'plan_anterior_id' => (int) $s['plan_id'], 'plan_nuevo_id' => (int) $plan['id'],
                'precio_anterior' => (string) $s['precio_pactado'], 'precio_nuevo' => $precio, 'notas' => $d['notas'] ?? ($ciclo !== $s['ciclo'] ? "Ciclo: {$s['ciclo']} → {$ciclo}" : null),
            ], $uid);
            return (int) $s['tenant_id'];
        });
        Audit::record('plataforma.suscripcion.cambio_plan', 'exito', $request, $uid, $tenantId, 'suscripciones', $id, ['plan_id' => (int) $d['plan_id']]);
        return $this->show($id);
    }

    /**
     * Extiende el periodo pagado $periodos ciclos. Si la suscripción ya venció, crea una nueva
     * desde hoy con el mismo plan, ciclo y precio. Devuelve la suscripción resultante.
     *
     * @return array<string, mixed>
     */
    public function renew(int $id, int $periodos, ?string $notas, Request $request): array
    {
        $uid = self::actor($request);
        [$newId, $tenantId] = Database::transaction(fn (): array => $this->renewLocked($id, $periodos, $notas, $uid, null));
        Audit::record('plataforma.suscripcion.renovada', 'exito', $request, $uid, $tenantId, 'suscripciones', $newId, ['periodos' => $periodos, 'desde_suscripcion' => $id]);
        return $this->show($newId);
    }

    /** @return array<string, mixed> */
    public function suspend(int $id, string $motivo, Request $request): array
    {
        return $this->terminate($id, 'suspendida', 'suspension', $motivo, $request);
    }

    /** @return array<string, mixed> */
    public function cancel(int $id, string $motivo, Request $request): array
    {
        return $this->terminate($id, 'cancelada', 'cancelacion', $motivo, $request);
    }

    /**
     * Reactiva una suscripción suspendida con el tiempo que le quedaba (fila nueva desde hoy
     * hasta el fin del periodo original). Si el periodo ya terminó hay que renovarla.
     *
     * @return array<string, mixed>
     */
    public function reactivate(int $id, ?string $notas, Request $request): array
    {
        $uid = self::actor($request);
        [$newId, $tenantId] = Database::transaction(function () use ($id, $notas, $uid): array {
            $s = $this->lock($id);
            if ($s['estado'] !== 'suspendida') {
                throw new HttpException(409, 'invalid_subscription_state', 'Solo se puede reactivar una suscripción suspendida.');
            }
            if ((string) $s['periodo_fin_en'] <= Clock::nowSql()) {
                throw new HttpException(409, 'period_ended', 'El periodo de esta suscripción ya terminó: renuévala para reactivarla.');
            }
            $this->expireDue((int) $s['tenant_id']);
            if ($this->vigente((int) $s['tenant_id']) !== null) {
                throw new HttpException(409, 'subscription_exists', 'La barbería ya tiene otra suscripción vigente.');
            }
            $plan = $this->plan((int) $s['plan_id'], false);
            // Vuelve al estado que tenía al suspenderse (prueba o activa).
            $antes = $this->fetchOne(
                "SELECT estado_anterior FROM suscripcion_eventos WHERE tenant_id = :t AND suscripcion_id = :s AND tipo = 'suspension' ORDER BY id DESC LIMIT 1",
                ['t' => (int) $s['tenant_id'], 's' => $id]
            );
            $estado = ($antes['estado_anterior'] ?? null) === 'prueba' ? 'prueba' : 'activa';
            $newId = $this->insertSubscription((int) $s['tenant_id'], $plan, $estado, (string) $s['ciclo'], (string) $s['precio_pactado'], Clock::nowUtc(),
                new DateTimeImmutable((string) $s['periodo_fin_en'], new DateTimeZone('UTC')), $notas ?? "Reactivación de la suscripción #{$id}", $uid, 'reactivacion', $s);
            return [$newId, (int) $s['tenant_id']];
        });
        Audit::record('plataforma.suscripcion.reactivada', 'exito', $request, $uid, $tenantId, 'suscripciones', $newId, ['desde_suscripcion' => $id]);
        return $this->show($newId);
    }

    /**
     * Marca como vencidas las suscripciones vigentes cuyo periodo terminó (cron diario y antes
     * de operar sobre una barbería). Devuelve cuántas cambió.
     */
    public function expireDue(?int $tenantId = null): int
    {
        $p = ['now' => Clock::nowSql()];
        $sql = "SELECT * FROM suscripciones WHERE estado IN ('prueba','activa') AND periodo_fin_en <= :now";
        if ($tenantId !== null) {
            $sql .= ' AND tenant_id = :t';
            $p['t'] = $tenantId;
        }
        $n = 0;
        foreach ($this->fetchAll($sql . ' FOR UPDATE', $p) as $s) {
            $this->execute("UPDATE suscripciones SET estado = 'vencida', terminada_en = periodo_fin_en WHERE id = :id AND estado IN ('prueba','activa')", ['id' => (int) $s['id']]);
            $this->event($s, 'vencimiento', ['estado_anterior' => $s['estado'], 'estado_nuevo' => 'vencida'], null);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------ Fase 10: autoservicio y pasarela

    /**
     * Prueba gratuita automática al activarse una barbería (registro sin aprobación o aprobación).
     * Solo si está habilitada, el plan configurado tiene días de prueba y la barbería nunca tuvo
     * suscripción. Debe llamarse dentro de una transacción. Devuelve el id o null.
     */
    public function startTrial(int $tenantId): ?int
    {
        $cfg = PlatformSettings::all();
        if (!(bool) $cfg['plataforma.prueba_automatica']) {
            return null;
        }
        if ($this->fetchOne('SELECT id FROM suscripciones WHERE tenant_id = :t LIMIT 1', ['t' => $tenantId]) !== null) {
            return null;
        }
        $plan = $this->fetchOne('SELECT * FROM planes WHERE codigo = :c AND activo = 1', ['c' => (string) $cfg['plataforma.plan_prueba']]);
        if ($plan === null || (int) $plan['dias_prueba'] < 1) {
            return null;
        }
        $inicio = Clock::nowUtc();
        return $this->insertSubscription($tenantId, $plan, 'prueba', 'mensual', self::planPrice($plan, 'mensual'), $inicio,
            $inicio->modify('+' . (int) $plan['dias_prueba'] . ' days'), 'Prueba gratuita automática', null, 'prueba_automatica', null);
    }

    /**
     * Aplica una orden de pago en línea ya cobrada (dentro de la transacción del procesador, con la
     * orden bloqueada). Revalida que la suscripción siga como cuando se creó la orden; si no,
     * lanza HttpException y el pago queda para revisión manual.
     *
     * @param array<string, mixed> $o fila de ordenes_pago
     * @return array{0:int, 1:string, 2:string} [suscripción, periodo_inicio, periodo_fin] (AAAA-MM-DD)
     */
    public function applyOrder(array $o): array
    {
        $tenantId = (int) $o['tenant_id'];
        $this->fetchOne('SELECT id FROM tenants WHERE id = :id FOR UPDATE', ['id' => $tenantId]);
        $this->expireDue($tenantId);
        $vig = $this->vigente($tenantId);
        $vig = $vig !== null ? $this->lock((int) $vig['id']) : null;
        $plan = $this->plan((int) $o['plan_id'], false);
        $ciclo = (string) $o['ciclo'];
        $periodos = max(1, (int) $o['periodos']);
        $now = Clock::nowUtc();
        $changed = static fn (): HttpException => new HttpException(409, 'subscription_changed', 'La suscripción cambió después de crear la orden de pago.');

        if ($o['tipo'] === 'mejora') {
            if ($vig === null || (int) $vig['id'] !== (int) $o['suscripcion_id'] || $vig['estado'] !== 'activa' || $vig['ciclo'] !== $ciclo
                || (float) self::planPrice($plan, $ciclo) <= (float) $vig['precio_pactado']) {
                throw $changed();
            }
            $precio = self::planPrice($plan, $ciclo);
            $this->execute('UPDATE suscripciones SET plan_id = :p, precio_pactado = :pr, moneda = :m,
                                   plan_programado_id = NULL, ciclo_programado = NULL, precio_programado = NULL, cambio_programado_en = NULL WHERE id = :id',
                ['p' => (int) $plan['id'], 'pr' => $precio, 'm' => (string) $plan['moneda'], 'id' => (int) $vig['id']]);
            $this->event($vig, 'cambio_plan', ['plan_anterior_id' => (int) $vig['plan_id'], 'plan_nuevo_id' => (int) $plan['id'],
                'precio_anterior' => (string) $vig['precio_pactado'], 'precio_nuevo' => $precio, 'notas' => 'Mejora de plan pagada en línea (prorrateo del periodo restante)'], null);
            Entitlements::flush($tenantId);
            return [(int) $vig['id'], $now->format('Y-m-d'), self::date((string) $vig['periodo_fin_en'])];
        }

        $precio = number_format((float) $o['monto'] / $periodos, 2, '.', '');
        if ($o['tipo'] === 'renovacion' && $vig !== null && (int) $vig['id'] === (int) $o['suscripcion_id'] && $vig['estado'] === 'activa') {
            $nextPlan = (int) ($vig['plan_programado_id'] ?? $vig['plan_id']);
            $nextCiclo = (string) ($vig['ciclo_programado'] ?? $vig['ciclo']);
            if ($nextPlan !== (int) $plan['id'] || $nextCiclo !== $ciclo) {
                throw $changed();
            }
            $desde = new DateTimeImmutable((string) $vig['periodo_fin_en'], new DateTimeZone('UTC'));
            $fin = self::addCycles($desde, $ciclo, $periodos);
            $this->execute('UPDATE suscripciones SET periodo_fin_en = :f WHERE id = :id', ['f' => $fin->format('Y-m-d H:i:s'), 'id' => (int) $vig['id']]);
            $this->event($vig, 'renovacion', ['estado_anterior' => 'activa', 'estado_nuevo' => 'activa', 'periodo_fin_anterior' => (string) $vig['periodo_fin_en'],
                'periodo_fin_nuevo' => $fin->format('Y-m-d H:i:s'), 'notas' => "Renovación pagada en línea ({$periodos} periodo(s))"], null);
            $this->liftRestriction($tenantId);
            return [(int) $vig['id'], $desde->format('Y-m-d'), $fin->format('Y-m-d')];
        }

        // Contratación (o renovación cuyo periodo ya terminó): desde el fin de la prueba o desde hoy.
        if ($vig !== null && $vig['estado'] === 'prueba') {
            $desde = new DateTimeImmutable((string) $vig['periodo_fin_en'], new DateTimeZone('UTC'));
            $fin = self::addCycles($desde, $ciclo, $periodos);
            $this->execute("UPDATE suscripciones SET estado = 'activa', plan_id = :p, ciclo = :c, precio_pactado = :pr, moneda = :m, periodo_fin_en = :f,
                                   plan_programado_id = NULL, ciclo_programado = NULL, precio_programado = NULL, cambio_programado_en = NULL WHERE id = :id",
                ['p' => (int) $plan['id'], 'c' => $ciclo, 'pr' => $precio, 'm' => (string) $plan['moneda'], 'f' => $fin->format('Y-m-d H:i:s'), 'id' => (int) $vig['id']]);
            $this->event($vig, 'renovacion', ['estado_anterior' => 'prueba', 'estado_nuevo' => 'activa', 'plan_anterior_id' => (int) $vig['plan_id'], 'plan_nuevo_id' => (int) $plan['id'],
                'precio_anterior' => (string) $vig['precio_pactado'], 'precio_nuevo' => $precio, 'periodo_fin_anterior' => (string) $vig['periodo_fin_en'],
                'periodo_fin_nuevo' => $fin->format('Y-m-d H:i:s'), 'notas' => 'Plan contratado en línea al terminar la prueba'], null);
            $this->liftRestriction($tenantId);
            return [(int) $vig['id'], $desde->format('Y-m-d'), $fin->format('Y-m-d')];
        }
        if ($vig !== null) {
            throw $changed();
        }
        $tenant = $this->fetchOne('SELECT estado FROM tenants WHERE id = :id', ['id' => $tenantId]);
        if (($tenant['estado'] ?? 'cancelado') === 'cancelado') {
            throw new HttpException(409, 'tenant_cancelled', 'La barbería está dada de baja.');
        }
        $last = $this->fetchOne('SELECT * FROM suscripciones WHERE tenant_id = :t ORDER BY id DESC LIMIT 1', ['t' => $tenantId]);
        $fin = self::addCycles($now, $ciclo, $periodos);
        $id = $this->insertSubscription($tenantId, $plan, 'activa', $ciclo, $precio, $now, $fin, 'Plan contratado en línea', null, $last !== null ? 'renovacion' : 'alta', $last);
        return [$id, $now->format('Y-m-d'), $fin->format('Y-m-d')];
    }

    /**
     * Prueba en curso: cambia de plan al instante (mismo fin de prueba). Dentro de una transacción.
     *
     * @param array<string, mixed> $s    suscripción bloqueada
     * @param array<string, mixed> $plan
     */
    public function switchTrialPlan(array $s, array $plan, string $ciclo, ?int $uid): void
    {
        $precio = self::planPrice($plan, $ciclo);
        $this->execute('UPDATE suscripciones SET plan_id = :p, ciclo = :c, precio_pactado = :pr, moneda = :m WHERE id = :id',
            ['p' => (int) $plan['id'], 'c' => $ciclo, 'pr' => $precio, 'm' => (string) $plan['moneda'], 'id' => (int) $s['id']]);
        $this->event($s, 'cambio_plan', ['plan_anterior_id' => (int) $s['plan_id'], 'plan_nuevo_id' => (int) $plan['id'],
            'precio_anterior' => (string) $s['precio_pactado'], 'precio_nuevo' => $precio, 'notas' => 'Cambio de plan durante la prueba (propietario)'], $uid);
        Entitlements::flush((int) $s['tenant_id']);
    }

    /**
     * Mejora sin cobro (el prorrateo no llega al mínimo cobrable): cambia el plan ya. Dentro de una transacción.
     *
     * @param array<string, mixed> $s    suscripción activa bloqueada
     * @param array<string, mixed> $plan
     */
    public function upgradeWithoutCharge(array $s, array $plan, ?int $uid): void
    {
        $precio = self::planPrice($plan, (string) $s['ciclo']);
        $this->execute('UPDATE suscripciones SET plan_id = :p, precio_pactado = :pr, moneda = :m,
                               plan_programado_id = NULL, ciclo_programado = NULL, precio_programado = NULL, cambio_programado_en = NULL WHERE id = :id',
            ['p' => (int) $plan['id'], 'pr' => $precio, 'm' => (string) $plan['moneda'], 'id' => (int) $s['id']]);
        $this->event($s, 'cambio_plan', ['plan_anterior_id' => (int) $s['plan_id'], 'plan_nuevo_id' => (int) $plan['id'],
            'precio_anterior' => (string) $s['precio_pactado'], 'precio_nuevo' => $precio, 'notas' => 'Mejora sin cobro (prorrateo menor al mínimo)'], $uid);
        Entitlements::flush((int) $s['tenant_id']);
    }

    /**
     * Programa (o anula, con $plan null) un cambio de plan o de ciclo para el fin del periodo pagado.
     *
     * @param array<string, mixed>      $s
     * @param array<string, mixed>|null $plan
     */
    public function scheduleChange(array $s, ?array $plan, ?string $ciclo, ?int $uid): void
    {
        if ($plan === null) {
            $this->execute('UPDATE suscripciones SET plan_programado_id = NULL, ciclo_programado = NULL, precio_programado = NULL, cambio_programado_en = NULL WHERE id = :id', ['id' => (int) $s['id']]);
            $this->event($s, 'cambio_programado', ['notas' => 'Cambio programado anulado'], $uid);
            return;
        }
        $precio = self::planPrice($plan, (string) $ciclo);
        $this->execute('UPDATE suscripciones SET plan_programado_id = :p, ciclo_programado = :c, precio_programado = :pr, cambio_programado_en = :en WHERE id = :id',
            ['p' => (int) $plan['id'], 'c' => $ciclo, 'pr' => $precio, 'en' => (string) $s['periodo_fin_en'], 'id' => (int) $s['id']]);
        $this->event($s, 'cambio_programado', ['plan_anterior_id' => (int) $s['plan_id'], 'plan_nuevo_id' => (int) $plan['id'], 'precio_anterior' => (string) $s['precio_pactado'],
            'precio_nuevo' => $precio, 'periodo_fin_anterior' => (string) $s['periodo_fin_en'], 'notas' => "Desde el fin del periodo actual: {$plan['nombre']} {$ciclo}"], $uid);
    }

    /** Cron: aplica los cambios programados cuyo momento llegó (si la suscripción sigue vigente). */
    public function applyScheduledChanges(): int
    {
        $now = Clock::nowSql();
        $n = 0;
        foreach ($this->fetchAll("SELECT * FROM suscripciones WHERE cambio_programado_en IS NOT NULL AND cambio_programado_en <= :n1
                                     AND estado IN ('prueba','activa') AND periodo_fin_en > :n2 FOR UPDATE", ['n1' => $now, 'n2' => $now]) as $s) {
            $plan = $this->plan((int) $s['plan_programado_id'], false);
            $this->execute('UPDATE suscripciones SET plan_id = plan_programado_id, ciclo = ciclo_programado, precio_pactado = precio_programado, moneda = :m,
                                   plan_programado_id = NULL, ciclo_programado = NULL, precio_programado = NULL, cambio_programado_en = NULL WHERE id = :id',
                ['m' => (string) $plan['moneda'], 'id' => (int) $s['id']]);
            $this->event($s, 'cambio_plan', ['plan_anterior_id' => (int) $s['plan_id'], 'plan_nuevo_id' => (int) $plan['id'],
                'precio_anterior' => (string) $s['precio_pactado'], 'precio_nuevo' => (string) $s['precio_programado'], 'notas' => 'Cambio programado aplicado'], null);
            Entitlements::flush((int) $s['tenant_id']);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------ pagos

    /**
     * @param array<string, mixed> $f tenant_id, suscripcion_id, estado, metodo, desde, hasta, q
     * @return array{items: list<array<string, mixed>>, total:int, totales: list<array<string, mixed>>}
     */
    public function payments(array $f, int $limit, int $offset): array
    {
        $where = ['t.deleted_at IS NULL'];
        $p = [];
        foreach (['tenant_id' => 'pg.tenant_id', 'suscripcion_id' => 'pg.suscripcion_id'] as $k => $col) {
            if (!empty($f[$k])) {
                $where[] = "{$col} = :{$k}";
                $p[$k] = (int) $f[$k];
            }
        }
        if (!empty($f['estado']) && in_array($f['estado'], ['pendiente', 'confirmado', 'rechazado', 'reembolsado'], true)) {
            $where[] = 'pg.estado = :estado';
            $p['estado'] = $f['estado'];
        }
        if (!empty($f['metodo']) && in_array($f['metodo'], array_merge(self::METODOS, ['pasarela']), true)) {
            $where[] = 'pg.metodo = :metodo';
            $p['metodo'] = $f['metodo'];
        }
        $tz = new DateTimeZone(PlatformSettings::timezone());
        foreach (['desde' => '>=', 'hasta' => '<'] as $k => $op) {
            if (!empty($f[$k]) && is_string($f[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) {
                $d = new DateTimeImmutable($f[$k] . ' 00:00:00', $tz);
                if ($k === 'hasta') {
                    $d = $d->modify('+1 day');
                }
                $where[] = "COALESCE(pg.pagado_en, pg.created_at) {$op} :{$k}";
                $p[$k] = $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }
        if (!empty($f['q'])) {
            $q = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(trim((string) $f['q']), 0, 80)) . '%';
            $where[] = '(t.nombre_comercial LIKE :q1 OR t.slug LIKE :q2 OR pg.referencia_externa LIKE :q3)';
            $p += ['q1' => $q, 'q2' => $q, 'q3' => $q];
        }
        $w = implode(' AND ', $where);
        $from = "FROM pagos_saas pg JOIN tenants t ON t.id = pg.tenant_id
                 JOIN suscripciones s ON s.id = pg.suscripcion_id JOIN planes pl ON pl.id = s.plan_id
                 LEFT JOIN usuarios u ON u.id = pg.registrado_por WHERE {$w}";
        $total = (int) ($this->fetchOne("SELECT COUNT(*) n {$from}", $p)['n'] ?? 0);
        $rows = $this->fetchAll(
            "SELECT pg.*, t.slug tenant_slug, t.nombre_comercial tenant_nombre, t.es_demo tenant_demo, pl.nombre plan_nombre, u.email registrado_por_email
             {$from} ORDER BY COALESCE(pg.pagado_en, pg.created_at) DESC, pg.id DESC LIMIT {$limit} OFFSET {$offset}",
            $p
        );
        $totales = $this->fetchAll("SELECT pg.estado, pg.moneda, COUNT(*) n, SUM(pg.monto) monto {$from} GROUP BY pg.estado, pg.moneda ORDER BY pg.estado", $p);
        return [
            'items' => array_map([self::class, 'presentPayment'], $rows),
            'total' => $total,
            'totales' => array_map(static fn (array $r): array => ['estado' => (string) $r['estado'], 'moneda' => (string) $r['moneda'], 'pagos' => (int) $r['n'], 'monto' => number_format((float) $r['monto'], 2, '.', '')], $totales),
        ];
    }

    /**
     * Registra un pago manual. Confirmado y con periodos > 0 renueva la suscripción en la misma
     * transacción (el pago queda asociado a la suscripción resultante y a su nuevo periodo).
     *
     * @param array<string, mixed> $d monto, metodo, referencia?, estado, pagado_en?, periodos?, notas?
     * @return array<string, mixed>
     */
    public function registerPayment(int $subId, array $d, Request $request): array
    {
        $uid = self::actor($request);
        $estado = (string) ($d['estado'] ?? 'confirmado');
        $periodos = (int) ($d['periodos'] ?? ($estado === 'confirmado' ? 1 : 0));
        [$pagoId, $tenantId] = Database::transaction(function () use ($subId, $d, $estado, $periodos, $uid): array {
            $s = $this->lock($subId);
            if ($s['estado'] === 'cancelada') {
                throw new HttpException(409, 'invalid_subscription_state', 'La suscripción está cancelada: crea una nueva para registrar pagos.');
            }
            $pagadoEn = $estado === 'confirmado' ? self::paidAt($d['pagado_en'] ?? null) : null;
            [$ini, $fin] = [self::date((string) $s['inicio_en']), self::date((string) $s['periodo_fin_en'])];
            $targetId = $subId;
            if ($estado === 'confirmado' && $periodos > 0) {
                [$targetId, , $ini, $fin] = $this->renewLocked($subId, $periodos, null, $uid, null, true);
            }
            try {
                $pagoId = $this->insert(
                    'INSERT INTO pagos_saas (tenant_id, suscripcion_id, monto, moneda, metodo, estado, referencia_externa, periodo_inicio, periodo_fin, pagado_en, registrado_por, notas)
                     VALUES (:t, :s, :m, :mon, :met, :e, :ref, :pi, :pf, :pe, :u, :n)',
                    ['t' => (int) $s['tenant_id'], 's' => $targetId, 'm' => (string) $d['monto'], 'mon' => (string) $s['moneda'], 'met' => (string) $d['metodo'], 'e' => $estado,
                        'ref' => ($d['referencia'] ?? '') === '' ? null : $d['referencia'], 'pi' => $ini, 'pf' => $fin, 'pe' => $pagadoEn, 'u' => $uid, 'n' => $d['notas'] ?? null]
                );
            } catch (PDOException $e) {
                if ((string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'uk_pagos_saas_referencia')) {
                    throw new HttpException(409, 'duplicate_reference', 'Ya existe un pago con ese método y número de operación.');
                }
                throw $e;
            }
            $target = $this->lock($targetId);
            $this->event($target, 'pago', ['notas' => "Pago {$estado} de {$s['moneda']} " . number_format((float) $d['monto'], 2, '.', '') . " ({$d['metodo']})"], $uid, $pagoId);
            return [$pagoId, (int) $s['tenant_id']];
        });
        Audit::record('plataforma.pago.registrado', 'exito', $request, $uid, $tenantId, 'pagos_saas', $pagoId, ['estado' => $estado, 'monto' => (string) $d['monto'], 'metodo' => $d['metodo'], 'periodos' => $periodos]);
        return $this->payment($pagoId);
    }

    /** @return array<string, mixed> */
    public function confirmPayment(int $pagoId, int $periodos, ?string $pagadoEn, Request $request): array
    {
        $uid = self::actor($request);
        $tenantId = Database::transaction(function () use ($pagoId, $periodos, $pagadoEn, $uid): int {
            $pg = $this->lockPayment($pagoId);
            if ($pg['estado'] !== 'pendiente') {
                throw new HttpException(409, 'invalid_payment_state', 'Solo se puede confirmar un pago pendiente.');
            }
            $targetId = (int) $pg['suscripcion_id'];
            $ini = (string) $pg['periodo_inicio'];
            $fin = (string) $pg['periodo_fin'];
            if ($periodos > 0) {
                [$targetId, , $ini, $fin] = $this->renewLocked((int) $pg['suscripcion_id'], $periodos, null, $uid, $pagoId, true);
            }
            $this->execute("UPDATE pagos_saas SET estado = 'confirmado', pagado_en = :pe, suscripcion_id = :s, periodo_inicio = :pi, periodo_fin = :pf WHERE id = :id",
                ['pe' => self::paidAt($pagadoEn), 's' => $targetId, 'pi' => $ini, 'pf' => $fin, 'id' => $pagoId]);
            $this->event($this->lock($targetId), 'pago', ['notas' => 'Pago confirmado'], $uid, $pagoId);
            return (int) $pg['tenant_id'];
        });
        Audit::record('plataforma.pago.confirmado', 'exito', $request, $uid, $tenantId, 'pagos_saas', $pagoId, ['periodos' => $periodos]);
        return $this->payment($pagoId);
    }

    /** @return array<string, mixed> */
    public function rejectPayment(int $pagoId, string $motivo, Request $request): array
    {
        return $this->closePayment($pagoId, 'pendiente', 'rechazado', $motivo, $request);
    }

    /** Un reembolso no acorta el periodo: si corresponde, suspende o cancela la suscripción aparte. @return array<string, mixed> */
    public function refundPayment(int $pagoId, string $motivo, Request $request): array
    {
        return $this->closePayment($pagoId, 'confirmado', 'reembolsado', $motivo, $request);
    }

    /** @return array<string, mixed> */
    public function payment(int $id): array
    {
        $row = $this->fetchOne(
            'SELECT pg.*, t.slug tenant_slug, t.nombre_comercial tenant_nombre, t.es_demo tenant_demo, pl.nombre plan_nombre, u.email registrado_por_email
               FROM pagos_saas pg JOIN tenants t ON t.id = pg.tenant_id JOIN suscripciones s ON s.id = pg.suscripcion_id
               JOIN planes pl ON pl.id = s.plan_id LEFT JOIN usuarios u ON u.id = pg.registrado_por WHERE pg.id = :id',
            ['id' => $id]
        );
        if ($row === null) {
            throw new NotFoundException('Pago no encontrado.');
        }
        return self::presentPayment($row);
    }

    // ------------------------------------------------------------------ internos

    /** @return array<string, mixed> */
    private function closePayment(int $pagoId, string $from, string $to, string $motivo, Request $request): array
    {
        $uid = self::actor($request);
        $tenantId = Database::transaction(function () use ($pagoId, $from, $to, $motivo, $uid): int {
            $pg = $this->lockPayment($pagoId);
            if ($pg['estado'] !== $from) {
                throw new HttpException(409, 'invalid_payment_state', $to === 'rechazado' ? 'Solo se puede rechazar un pago pendiente.' : 'Solo se puede reembolsar un pago confirmado.');
            }
            $notas = trim(((string) ($pg['notas'] ?? '')) . ' · ' . ($to === 'rechazado' ? 'Rechazado' : 'Reembolsado') . ": {$motivo}", ' ·');
            $this->execute('UPDATE pagos_saas SET estado = :e, notas = :n WHERE id = :id', ['e' => $to, 'n' => mb_substr($notas, 0, 500), 'id' => $pagoId]);
            $this->event($this->lock((int) $pg['suscripcion_id']), 'pago', ['notas' => mb_substr(($to === 'rechazado' ? 'Pago rechazado: ' : 'Pago reembolsado: ') . $motivo, 0, 500)], $uid, $pagoId);
            return (int) $pg['tenant_id'];
        });
        Audit::record('plataforma.pago.' . $to, 'exito', $request, $uid, $tenantId, 'pagos_saas', $pagoId, ['motivo' => $motivo]);
        return $this->payment($pagoId);
    }

    /**
     * Dentro de una transacción. Devuelve [id resultante, tenant_id, periodo_inicio, periodo_fin] (fechas AAAA-MM-DD).
     *
     * @return array{0:int, 1:int, 2:string, 3:string}
     */
    public function renewLocked(int $id, int $periodos, ?string $notas, ?int $uid, ?int $pagoId, bool $fromPayment = false): array
    {
        if ($periodos < 1 || $periodos > 24) {
            throw new ValidationException(['periodos' => ['Indica entre 1 y 24 periodos.']]);
        }
        $s = $this->lock($id);
        $tenantId = (int) $s['tenant_id'];
        $now = Clock::nowUtc();
        if (in_array($s['estado'], ['prueba', 'activa'], true) && (string) $s['periodo_fin_en'] > $now->format('Y-m-d H:i:s')) {
            // Vigente: se extiende desde el fin del periodo actual (una prueba pasa a activa).
            $desde = new DateTimeImmutable((string) $s['periodo_fin_en'], new DateTimeZone('UTC'));
            $fin = self::addCycles($desde, (string) $s['ciclo'], $periodos);
            $this->execute("UPDATE suscripciones SET estado = 'activa', periodo_fin_en = :f WHERE id = :id", ['f' => $fin->format('Y-m-d H:i:s'), 'id' => $id]);
            $this->liftRestriction($tenantId);
            $this->event($s, 'renovacion', [
                'estado_anterior' => $s['estado'], 'estado_nuevo' => 'activa',
                'periodo_fin_anterior' => (string) $s['periodo_fin_en'], 'periodo_fin_nuevo' => $fin->format('Y-m-d H:i:s'),
                'notas' => $notas ?? ($fromPayment ? 'Renovación por pago' : "Renovación por {$periodos} periodo(s)"),
            ], $uid, $pagoId);
            return [$id, $tenantId, $desde->format('Y-m-d'), $fin->format('Y-m-d')];
        }
        if ($s['estado'] === 'cancelada') {
            throw new HttpException(409, 'invalid_subscription_state', 'La suscripción está cancelada: crea una nueva.');
        }
        if ($s['estado'] === 'suspendida' && (string) $s['periodo_fin_en'] > $now->format('Y-m-d H:i:s')) {
            throw new HttpException(409, 'invalid_subscription_state', 'La suscripción está suspendida: reactívala primero.');
        }
        // Vencida (o suspendida con el periodo ya terminado): nueva suscripción desde hoy.
        $this->expireDue($tenantId);
        if ($this->vigente($tenantId) !== null) {
            throw new HttpException(409, 'subscription_exists', 'La barbería ya tiene otra suscripción vigente: renueva esa.');
        }
        $plan = $this->plan((int) $s['plan_id'], false);
        $fin = self::addCycles($now, (string) $s['ciclo'], $periodos);
        $newId = $this->insertSubscription($tenantId, $plan, 'activa', (string) $s['ciclo'], (string) $s['precio_pactado'], $now, $fin,
            $notas ?? "Renovación de la suscripción #{$id}", $uid, 'renovacion', $s, $pagoId);
        return [$newId, $tenantId, $now->format('Y-m-d'), $fin->format('Y-m-d')];
    }

    /** @return array<string, mixed> */
    private function terminate(int $id, string $to, string $tipo, string $motivo, Request $request): array
    {
        $uid = self::actor($request);
        $tenantId = Database::transaction(function () use ($id, $to, $tipo, $motivo, $uid): int {
            $s = $this->lock($id);
            $allowed = $to === 'suspendida' ? ['prueba', 'activa'] : ['prueba', 'activa', 'suspendida', 'vencida'];
            if (!in_array($s['estado'], $allowed, true)) {
                throw new HttpException(409, 'invalid_subscription_state', $to === 'suspendida'
                    ? 'Solo se puede suspender una suscripción vigente.' : 'La suscripción ya está cancelada.');
            }
            $now = Clock::nowSql();
            $vigente = in_array($s['estado'], ['prueba', 'activa'], true);
            $this->execute(
                'UPDATE suscripciones SET estado = :e, terminada_en = COALESCE(terminada_en, :t), cancelada_en = IF(:e2 = \'cancelada\', :c, cancelada_en),
                        notas = :n WHERE id = :id',
                ['e' => $to, 't' => $vigente ? min($now, (string) $s['periodo_fin_en']) : (string) $s['periodo_fin_en'], 'e2' => $to, 'c' => $now,
                    'n' => mb_substr($motivo, 0, 500), 'id' => $id]
            );
            $this->event($s, $tipo, ['estado_anterior' => $s['estado'], 'estado_nuevo' => $to, 'notas' => mb_substr($motivo, 0, 500)], $uid);
            Entitlements::flush((int) $s['tenant_id']);
            return (int) $s['tenant_id'];
        });
        Audit::record('plataforma.suscripcion.' . $to, 'exito', $request, $uid, $tenantId, 'suscripciones', $id, ['motivo' => $motivo]);
        return $this->show($id);
    }

    /**
     * @param array<string, mixed>      $plan
     * @param array<string, mixed>|null $origen suscripción de la que proviene (renovación o reactivación)
     */
    public function insertSubscription(int $tenantId, array $plan, string $estado, string $ciclo, string $precio, DateTimeImmutable $inicio, DateTimeImmutable $fin,
        ?string $notas, ?int $uid, string $tipoEvento, ?array $origen, ?int $pagoId = null): int
    {
        if ($fin <= $inicio) {
            throw new ValidationException(['periodo' => ['El fin del periodo debe ser posterior al inicio.']]);
        }
        try {
            $id = $this->insert(
                'INSERT INTO suscripciones (tenant_id, plan_id, estado, ciclo, precio_pactado, moneda, inicio_en, periodo_fin_en, prueba_fin_en, notas)
                 VALUES (:t, :p, :e, :c, :pr, :m, :i, :f, :pf, :n)',
                ['t' => $tenantId, 'p' => (int) $plan['id'], 'e' => $estado, 'c' => $ciclo, 'pr' => $precio, 'm' => (string) $plan['moneda'],
                    'i' => $inicio->format('Y-m-d H:i:s'), 'f' => $fin->format('Y-m-d H:i:s'), 'pf' => $estado === 'prueba' ? $fin->format('Y-m-d H:i:s') : null,
                    'n' => $notas === null ? null : mb_substr($notas, 0, 500)]
            );
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'uk_suscripciones_vigente')) {
                throw new HttpException(409, 'subscription_exists', 'La barbería ya tiene una suscripción vigente.');
            }
            throw $e;
        }
        if (in_array($estado, ['prueba', 'activa'], true)) {
            $this->liftRestriction($tenantId);
        }
        $this->event(['id' => $id, 'tenant_id' => $tenantId], $tipoEvento, [
            'estado_anterior' => $origen['estado'] ?? null, 'estado_nuevo' => $estado,
            'plan_anterior_id' => $origen !== null ? (int) $origen['plan_id'] : null, 'plan_nuevo_id' => (int) $plan['id'],
            'precio_nuevo' => $precio, 'periodo_fin_anterior' => $origen['periodo_fin_en'] ?? null, 'periodo_fin_nuevo' => $fin->format('Y-m-d H:i:s'),
            'notas' => $notas,
        ], $uid, $pagoId);
        return $id;
    }

    /**
     * @param array<string, mixed> $s fila de suscripciones (id, tenant_id)
     * @param array<string, mixed> $f
     */
    public function event(array $s, string $tipo, array $f, ?int $uid, ?int $pagoId = null): void
    {
        $this->execute(
            'INSERT INTO suscripcion_eventos (tenant_id, suscripcion_id, tipo, estado_anterior, estado_nuevo, plan_anterior_id, plan_nuevo_id,
                    precio_anterior, precio_nuevo, periodo_fin_anterior, periodo_fin_nuevo, pago_id, usuario_id, notas, created_at)
             VALUES (:t, :s, :tipo, :ea, :en, :pa, :pn, :pra, :prn, :fa, :fn, :pago, :u, :n, :c)',
            ['t' => (int) $s['tenant_id'], 's' => (int) $s['id'], 'tipo' => $tipo, 'ea' => $f['estado_anterior'] ?? null, 'en' => $f['estado_nuevo'] ?? null,
                'pa' => $f['plan_anterior_id'] ?? null, 'pn' => $f['plan_nuevo_id'] ?? null, 'pra' => $f['precio_anterior'] ?? null, 'prn' => $f['precio_nuevo'] ?? null,
                'fa' => $f['periodo_fin_anterior'] ?? null, 'fn' => $f['periodo_fin_nuevo'] ?? null, 'pago' => $pagoId, 'u' => $uid,
                'n' => isset($f['notas']) ? mb_substr((string) $f['notas'], 0, 500) : null, 'c' => Clock::nowSql()]
        );
    }

    /** Levanta la restricción por falta de pago al quedar vigente una suscripción (Fase 10). */
    public function liftRestriction(int $tenantId): void
    {
        $this->execute('UPDATE tenants SET restringido_en = NULL WHERE id = :id AND restringido_en IS NOT NULL', ['id' => $tenantId]);
        Entitlements::flush($tenantId);
    }

    /** @return array<string, mixed> */
    public function lock(int $id): array
    {
        $s = $this->fetchOne('SELECT s.* FROM suscripciones s JOIN tenants t ON t.id = s.tenant_id WHERE s.id = :id AND t.deleted_at IS NULL FOR UPDATE', ['id' => $id]);
        if ($s === null) {
            throw new NotFoundException('Suscripción no encontrada.');
        }
        return $s;
    }

    /** @return array<string, mixed> */
    private function lockPayment(int $id): array
    {
        $p = $this->fetchOne('SELECT * FROM pagos_saas WHERE id = :id FOR UPDATE', ['id' => $id]);
        if ($p === null) {
            throw new NotFoundException('Pago no encontrado.');
        }
        return $p;
    }

    /** @return array<string, mixed>|null */
    public function vigente(int $tenantId): ?array
    {
        return $this->fetchOne("SELECT * FROM suscripciones WHERE tenant_id = :t AND estado IN ('prueba','activa') LIMIT 1", ['t' => $tenantId]);
    }

    /** @param array<string, mixed> $s */
    private function assertVigente(array $s, string $que): void
    {
        if (!in_array($s['estado'], ['prueba', 'activa'], true) || (string) $s['periodo_fin_en'] <= Clock::nowSql()) {
            throw new HttpException(409, 'invalid_subscription_state', "Solo se puede {$que} una suscripción vigente.");
        }
    }

    /** @return array<string, mixed> */
    public function plan(int $id, bool $mustBeActive): array
    {
        $p = $this->fetchOne('SELECT * FROM planes WHERE id = :id', ['id' => $id]);
        if ($p === null) {
            throw new ValidationException(['plan_id' => ['El plan no existe.']]);
        }
        if ($mustBeActive && !(bool) $p['activo']) {
            throw new ValidationException(['plan_id' => ['El plan está desactivado.']]);
        }
        return $p;
    }

    /** @param array<string, mixed> $plan */
    public static function planPrice(array $plan, string $ciclo): string
    {
        if ($ciclo === 'anual') {
            return $plan['precio_anual'] !== null ? (string) $plan['precio_anual'] : number_format((float) $plan['precio_mensual'] * 12, 2, '.', '');
        }
        return (string) $plan['precio_mensual'];
    }

    /** Suma meses sin desbordar: 31 ene + 1 mes = 28/29 feb. */
    public static function addCycles(DateTimeImmutable $from, string $ciclo, int $n): DateTimeImmutable
    {
        $months = $ciclo === 'anual' ? 12 * $n : $n;
        $y = (int) $from->format('Y');
        $m = (int) $from->format('n') + $months;
        $y += intdiv($m - 1, 12);
        $m = (($m - 1) % 12) + 1;
        $d = min((int) $from->format('j'), (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t'));
        return $from->setDate($y, $m, $d);
    }

    private static function paidAt(?string $date): string
    {
        $now = Clock::nowUtc();
        if ($date === null || $date === '') {
            return $now->format('Y-m-d H:i:s');
        }
        $tz = new DateTimeZone(PlatformSettings::timezone());
        $local = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
        if ($local === false) {
            throw new ValidationException(['pagado_en' => ['Fecha inválida (AAAA-MM-DD).']]);
        }
        $utc = $local->setTime(12, 0)->setTimezone(new DateTimeZone('UTC'));
        if ($local->format('Y-m-d') > $now->setTimezone($tz)->format('Y-m-d')) {
            throw new ValidationException(['pagado_en' => ['La fecha de pago no puede ser futura.']]);
        }
        return min($utc, $now)->format('Y-m-d H:i:s');
    }

    private static function date(string $sql): string
    {
        return substr($sql, 0, 10);
    }

    private static function actor(Request $request): ?int
    {
        $u = $request->attribute('auth_user');
        return is_array($u) && isset($u['id']) ? (int) $u['id'] : null;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public static function present(array $r): array
    {
        $efectivo = self::effectiveState((string) $r['estado'], (string) $r['periodo_fin_en']);
        $vigente = in_array($efectivo, ['prueba', 'activa'], true);
        $dias = $vigente ? (int) ceil((strtotime($r['periodo_fin_en'] . ' UTC') - Clock::nowUtc()->getTimestamp()) / 86400) : null;
        return [
            'id' => (int) $r['id'],
            'barberia' => ['id' => (int) $r['tenant_id'], 'slug' => (string) $r['tenant_slug'], 'nombre' => (string) $r['tenant_nombre'], 'es_demo' => (bool) $r['tenant_demo'], 'estado' => (string) $r['tenant_estado']],
            'plan' => ['id' => (int) $r['plan_id'], 'codigo' => (string) $r['plan_codigo'], 'nombre' => (string) $r['plan_nombre']],
            'estado' => (string) $r['estado'],
            'estado_efectivo' => $efectivo,
            'ciclo' => (string) $r['ciclo'],
            'precio_pactado' => (string) $r['precio_pactado'],
            'moneda' => (string) $r['moneda'],
            'mrr' => $efectivo === 'activa' ? number_format(round(PlatformMetrics::monthly($r), 2), 2, '.', '') : '0.00',
            'inicio_en' => self::iso((string) $r['inicio_en']),
            'periodo_fin_en' => self::iso((string) $r['periodo_fin_en']),
            'prueba_fin_en' => $r['prueba_fin_en'] ? self::iso((string) $r['prueba_fin_en']) : null,
            'terminada_en' => $r['terminada_en'] ? self::iso((string) $r['terminada_en']) : null,
            'cancelada_en' => $r['cancelada_en'] ? self::iso((string) $r['cancelada_en']) : null,
            'dias_restantes' => $dias,
            'cambio_programado' => !empty($r['plan_programado_id']) ? [
                'plan' => ['id' => (int) $r['plan_programado_id'], 'codigo' => (string) ($r['plan_programado_codigo'] ?? ''), 'nombre' => (string) ($r['plan_programado_nombre'] ?? '')],
                'ciclo' => (string) $r['ciclo_programado'], 'precio' => (string) $r['precio_programado'],
                'desde' => self::iso((string) $r['cambio_programado_en']),
            ] : null,
            'notas' => $r['notas'],
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public static function presentPayment(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'barberia' => ['id' => (int) $r['tenant_id'], 'slug' => (string) $r['tenant_slug'], 'nombre' => (string) $r['tenant_nombre'], 'es_demo' => (bool) $r['tenant_demo']],
            'suscripcion_id' => (int) $r['suscripcion_id'],
            'plan' => (string) $r['plan_nombre'],
            'monto' => (string) $r['monto'],
            'moneda' => (string) $r['moneda'],
            'metodo' => (string) $r['metodo'],
            'estado' => (string) $r['estado'],
            'referencia' => $r['referencia_externa'],
            'periodo_inicio' => (string) $r['periodo_inicio'],
            'periodo_fin' => (string) $r['periodo_fin'],
            'pagado_en' => $r['pagado_en'] ? self::iso((string) $r['pagado_en']) : null,
            'registrado_por' => $r['registrado_por_email'],
            'registrado_en' => self::iso((string) $r['created_at']),
            'notas' => $r['notas'],
        ];
    }

    private static function iso(string $sql): string
    {
        return str_replace(' ', 'T', $sql) . 'Z';
    }
}
