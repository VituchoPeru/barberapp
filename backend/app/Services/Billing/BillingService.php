<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Helpers\Clock;
use App\Helpers\Validator;
use App\Repositories\BaseRepository;
use App\Services\Audit;
use App\Services\Billing\Gateway\GatewayError;
use App\Services\Billing\Gateway\Gateways;
use App\Services\Platform\PlanService;
use App\Services\Platform\PlatformSettings;
use App\Services\Platform\SubscriptionService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Suscripción vista y gestionada por la propia barbería (Fase 10).
 *
 * El importe de cada cobro lo calcula siempre el servidor (quote) y queda fijo en la orden:
 *   - contratación: sin suscripción vigente o durante la prueba (el periodo pagado empieza al
 *     terminar la prueba, o hoy si no hay ninguna vigente);
 *   - renovación: mismo plan y ciclo (o el cambio ya programado), se extiende desde el fin del
 *     periodo actual al precio pactado;
 *   - mejora: plan más caro con el mismo ciclo a mitad del periodo; se cobra la diferencia
 *     proporcional al tiempo restante y el plan cambia al confirmarse el pago.
 * Bajar de plan o cambiar el ciclo con un periodo pagado se programa para su fin (sin cobro).
 */
final class BillingService extends BaseRepository
{
    private SubscriptionService $subs;

    /** @param array<string, mixed> $tenant id, slug, nombre_comercial, estado */
    public function __construct(private array $tenant)
    {
        parent::__construct();
        $this->subs = new SubscriptionService();
    }

    private function tid(): int
    {
        return (int) $this->tenant['id'];
    }

    /** @return array<string, mixed> todo lo que muestra la página Suscripción */
    public function overview(bool $canManage): array
    {
        Entitlements::flush($this->tid());
        $e = Entitlements::for($this->tid());
        $current = $e->sub !== null ? $this->subs->show((int) $e->sub['id']) : null;
        $pagos = array_map(static function (array $p): array {
            unset($p['registrado_por'], $p['barberia']);
            return $p;
        }, $this->subs->payments(['tenant_id' => $this->tid()], 24, 0)['items']);
        $planes = [];
        $uso = Entitlements::usage($this->tid());
        foreach ($this->fetchAll('SELECT * FROM planes WHERE activo = 1 AND (publico = 1 OR id = :p) ORDER BY orden, id', ['p' => (int) $e->plan['id']]) as $row) {
            $p = PlanService::present($row);
            unset($p['suscripciones']);
            $p['actual'] = (int) $row['id'] === (int) $e->plan['id'];
            $p['excede'] = self::exceeds($p, $uso);
            $p['opciones'] = [];
            foreach (['mensual', 'anual'] as $ciclo) {
                try {
                    $q = $this->quote((int) $row['id'], $ciclo, 1);
                    $p['opciones'][$ciclo] = ['accion' => $q['tipo'], 'monto' => $q['monto'], 'desde' => $q['desde'], 'hasta' => $q['hasta']];
                } catch (HttpException $ex) {
                    $p['opciones'][$ciclo] = ['accion' => $ex->errorCode() === 'schedule_change' ? 'programar' : null, 'motivo' => $ex->getMessage()];
                }
            }
            $planes[] = $p;
        }
        return [
            'resumen' => $e->summary(),
            'suscripcion' => $current === null ? null : array_diff_key($current, array_flip(['pagos', 'barberia', 'notas'])) + [
                'eventos' => array_map(static fn (array $ev): array => array_diff_key($ev, ['por' => 1]), $current['eventos']),
            ],
            'planes' => $planes,
            'pagos' => $pagos,
            'ordenes' => $this->orders(10),
            'pasarela' => ['habilitada' => Gateways::enabled(), 'nombre' => Gateways::driver()],
            'puede_gestionar' => $canManage,
            'soporte_email' => ((string) PlatformSettings::all()['plataforma.soporte_email']) ?: null,
        ];
    }

    /**
     * Calcula qué se cobraría por elegir un plan y ciclo. Lanza 409 schedule_change si el cambio
     * debe programarse (bajar de plan o cambiar el ciclo con un periodo pagado).
     *
     * @return array<string, mixed>
     */
    public function quote(int $planId, string $ciclo, int $periodos): array
    {
        if (!in_array($ciclo, ['mensual', 'anual'], true)) {
            throw new ValidationException(['ciclo' => ['Elige mensual o anual.']]);
        }
        if ($periodos < 1 || $periodos > ($ciclo === 'anual' ? 2 : 12)) {
            throw new ValidationException(['periodos' => [$ciclo === 'anual' ? 'Puedes pagar 1 o 2 años.' : 'Puedes pagar de 1 a 12 meses.']]);
        }
        $this->assertTenantCanPay();
        $vig = $this->vigente();
        $plan = $this->plan($planId, $vig !== null ? (int) $vig['plan_id'] : null);
        $now = Clock::nowUtc();
        $uso = Entitlements::usage($this->tid());

        if ($vig !== null && $vig['estado'] === 'activa') {
            $nextPlan = (int) ($vig['plan_programado_id'] ?? $vig['plan_id']);
            $nextCiclo = (string) ($vig['ciclo_programado'] ?? $vig['ciclo']);
            $fin = new DateTimeImmutable((string) $vig['periodo_fin_en'], new DateTimeZone('UTC'));
            if ((int) $plan['id'] === $nextPlan && $ciclo === $nextCiclo) {
                $precio = $vig['plan_programado_id'] !== null ? (string) $vig['precio_programado'] : (string) $vig['precio_pactado'];
                return $this->result('renovacion', $plan, $ciclo, $periodos, self::money((float) $precio * $periodos), $fin, SubscriptionService::addCycles($fin, $ciclo, $periodos), (int) $vig['id']);
            }
            $nuevo = (float) SubscriptionService::planPrice($plan, $ciclo);
            if ($ciclo === $vig['ciclo'] && $nuevo > (float) $vig['precio_pactado'] && (int) $plan['id'] !== (int) $vig['plan_id']) {
                $restante = max(0, $fin->getTimestamp() - $now->getTimestamp());
                $ciclo_s = ($ciclo === 'anual' ? 365 : 30) * 86400;
                $monto = round(($nuevo - (float) $vig['precio_pactado']) * $restante / $ciclo_s, 2);
                $tipo = $monto < (float) Config::get('billing.min_amount', '1.00') ? 'mejora_sin_cobro' : 'mejora';
                return $this->result($tipo, $plan, $ciclo, 1, self::money($monto), $now, $fin, (int) $vig['id']);
            }
            throw new HttpException(409, 'schedule_change', 'Bajar de plan o cambiar el ciclo se programa para el fin de tu periodo pagado ('
                . self::localDate($fin) . '). Usa «Programar cambio».', ['desde' => self::iso($fin)]);
        }
        if (self::exceeds(PlanService::present($plan), $uso)) {
            throw $this->exceedsError($plan, $uso);
        }
        $desde = $vig !== null ? new DateTimeImmutable((string) $vig['periodo_fin_en'], new DateTimeZone('UTC')) : $now;
        $monto = self::money((float) SubscriptionService::planPrice($plan, $ciclo) * $periodos);
        return $this->result('contratacion', $plan, $ciclo, $periodos, $monto, $desde, SubscriptionService::addCycles($desde, $ciclo, $periodos), $vig !== null ? (int) $vig['id'] : null);
    }

    /**
     * Crea la orden de pago y la sesión en la pasarela. Una mejora sin cobro se aplica al instante.
     *
     * @param array<string, mixed> $input plan_id, ciclo, periodos
     * @return array<string, mixed>
     */
    public function checkout(array $input, Request $request): array
    {
        $d = Validator::validate($input, ['plan_id' => 'required|integer|min:1', 'ciclo' => 'required|in:mensual,anual', 'periodos' => 'nullable|integer|min:1|max:12']);
        $user = (array) $request->attribute('auth_user');
        $uid = (int) $user['id'];
        $periodos = (int) ($d['periodos'] ?? 1);
        if (!Gateways::enabled()) {
            Gateways::make(); // 503 payments_disabled
        }
        $result = Database::transaction(function () use ($d, $periodos, $uid): array {
            $this->lockTenant();
            $q = $this->quote((int) $d['plan_id'], (string) $d['ciclo'], $periodos);
            if ($q['tipo'] === 'mejora_sin_cobro') {
                $this->subs->upgradeWithoutCharge($this->subs->lock((int) $q['suscripcion_id']), $this->plan((int) $d['plan_id'], null), $uid);
                return ['aplicado' => true, 'cotizacion' => $q];
            }
            // Una orden abierta a la vez: las anteriores sin pagar se anulan.
            $this->execute("UPDATE ordenes_pago SET estado = 'anulada', error = 'Reemplazada por una orden nueva' WHERE tenant_id = :t AND estado = 'pendiente'", ['t' => $this->tid()]);
            $codigo = bin2hex(random_bytes(12));
            $ttl = max(15, (int) Config::get('billing.order_ttl_minutes', 1440));
            $id = $this->insert(
                'INSERT INTO ordenes_pago (tenant_id, codigo, tipo, suscripcion_id, plan_id, ciclo, periodos, monto, moneda, detalle, estado, pasarela, solicitado_por, expira_en)
                 VALUES (:t, :c, :tipo, :s, :p, :ci, :pe, :m, :mon, :det, \'pendiente\', :pas, :u, :exp)',
                ['t' => $this->tid(), 'c' => $codigo, 'tipo' => $q['tipo'], 's' => $q['suscripcion_id'], 'p' => (int) $q['plan']['id'], 'ci' => $q['ciclo'], 'pe' => $q['periodos'],
                    'm' => $q['monto'], 'mon' => $q['moneda'], 'det' => $q['detalle'], 'pas' => Gateways::driver(), 'u' => $uid,
                    'exp' => Clock::nowUtc()->modify("+{$ttl} minutes")->format('Y-m-d H:i:s')]
            );
            return ['aplicado' => false, 'orden_id' => $id, 'cotizacion' => $q];
        });
        if ($result['aplicado']) {
            Audit::record('suscripcion.mejora_sin_cobro', 'exito', $request, $uid, $this->tid(), 'suscripciones', (int) $result['cotizacion']['suscripcion_id'], ['plan_id' => (int) $d['plan_id']]);
            return ['aplicado' => true, 'orden' => null, 'url' => null, 'mensaje' => 'Listo: tu plan cambió sin cobro adicional.'];
        }
        $order = (array) $this->fetchOne('SELECT * FROM ordenes_pago WHERE id = :id', ['id' => (int) $result['orden_id']]);
        $gateway = Gateways::make((string) $order['pasarela']);
        $front = rtrim((string) Config::get('app.frontend_url', ''), '/');
        $slug = rawurlencode((string) $this->tenant['slug']);
        $back = "{$front}/pages/panel/suscripcion.html?tenant={$slug}&orden={$order['codigo']}";
        try {
            $co = $gateway->createCheckout($order, ['email' => (string) ($user['email'] ?? ''), 'nombre' => trim((string) ($user['nombres'] ?? '') . ' ' . (string) ($user['apellidos'] ?? ''))], [
                'success' => $back . '&resultado=aprobado', 'failure' => $back . '&resultado=rechazado', 'pending' => $back . '&resultado=pendiente',
                'webhook' => rtrim((string) Config::get('app.url', ''), '/') . '/api/v1/webhooks/pagos/' . $gateway->name(),
                'sandbox' => "{$front}/pages/panel/pago-simulado.html?tenant={$slug}&orden={$order['codigo']}",
            ]);
        } catch (GatewayError $e) {
            $this->execute("UPDATE ordenes_pago SET estado = 'fallida', error = :e WHERE id = :id", ['e' => mb_substr($e->getMessage(), 0, 500), 'id' => (int) $order['id']]);
            Audit::record('suscripcion.orden_error', 'error', $request, $uid, $this->tid(), 'ordenes_pago', (int) $order['id'], ['error' => $e->getMessage()]);
            throw new HttpException(502, 'gateway_error', 'No pudimos conectar con la pasarela de pago. Intenta de nuevo en unos minutos.');
        }
        $this->execute('UPDATE ordenes_pago SET checkout_id = :c, checkout_url = :u WHERE id = :id', ['c' => $co['checkout_id'], 'u' => mb_substr($co['url'], 0, 500), 'id' => (int) $order['id']]);
        Audit::record('suscripcion.orden_creada', 'exito', $request, $uid, $this->tid(), 'ordenes_pago', (int) $order['id'],
            ['tipo' => $order['tipo'], 'plan_id' => (int) $order['plan_id'], 'monto' => (string) $order['monto'], 'pasarela' => $order['pasarela']]);
        return ['aplicado' => false, 'orden' => $this->order((string) $order['codigo']), 'url' => $co['url']];
    }

    /**
     * Cambio de plan sin pagar ahora: durante la prueba es inmediato; con un periodo pagado,
     * bajar de plan o cambiar el ciclo se programa para su fin.
     *
     * @param array<string, mixed> $input plan_id, ciclo
     * @return array<string, mixed>
     */
    public function changePlan(array $input, Request $request): array
    {
        $d = Validator::validate($input, ['plan_id' => 'required|integer|min:1', 'ciclo' => 'required|in:mensual,anual']);
        $uid = (int) ((array) $request->attribute('auth_user'))['id'];
        $accion = Database::transaction(function () use ($d, $uid): string {
            $this->lockTenant();
            $this->assertTenantCanPay();
            $vig = $this->vigente();
            if ($vig === null) {
                throw new HttpException(409, 'no_subscription', 'No tienes una suscripción vigente: elige el plan al pagar.');
            }
            $s = $this->subs->lock((int) $vig['id']);
            $plan = $this->plan((int) $d['plan_id'], (int) $s['plan_id']);
            $ciclo = (string) $d['ciclo'];
            $uso = Entitlements::usage($this->tid());
            $menor = (float) SubscriptionService::planPrice($plan, $ciclo) < (float) SubscriptionService::planPrice($this->plan((int) $s['plan_id'], (int) $s['plan_id']), $ciclo);
            if (self::exceeds(PlanService::present($plan), $uso)) {
                throw $this->exceedsError($plan, $uso);
            }
            if ($s['estado'] === 'prueba') {
                if ((int) $plan['id'] === (int) $s['plan_id'] && $ciclo === $s['ciclo']) {
                    throw new HttpException(409, 'no_changes', 'Ya estás probando ese plan.');
                }
                $this->subs->switchTrialPlan($s, $plan, $ciclo, $uid);
                return 'prueba';
            }
            if ((int) $plan['id'] === (int) $s['plan_id'] && $ciclo === $s['ciclo']) {
                if ($s['plan_programado_id'] !== null) {
                    $this->subs->scheduleChange($s, null, null, $uid);
                    return 'anulado';
                }
                throw new HttpException(409, 'no_changes', 'Ya tienes ese plan.');
            }
            if (!$menor && $ciclo === $s['ciclo']) {
                throw new HttpException(409, 'payment_required', 'Subir de plan se paga: elige el plan y pulsa «Pagar».');
            }
            $this->subs->scheduleChange($s, $plan, $ciclo, $uid);
            return 'programado';
        });
        Audit::record('suscripcion.cambio_plan_propietario', 'exito', $request, $uid, $this->tid(), 'suscripciones', null, ['plan_id' => (int) $d['plan_id'], 'ciclo' => $d['ciclo'], 'accion' => $accion]);
        $mensajes = [
            'prueba' => 'Listo: tu prueba continúa con el plan elegido.',
            'programado' => 'Cambio programado: regirá al terminar tu periodo pagado.',
            'anulado' => 'Anulamos el cambio programado: sigues con tu plan actual.',
        ];
        return ['accion' => $accion, 'mensaje' => $mensajes[$accion]] + $this->overview(true);
    }

    /** @return array<string, mixed> */
    public function cancelScheduled(Request $request): array
    {
        $uid = (int) ((array) $request->attribute('auth_user'))['id'];
        Database::transaction(function () use ($uid): void {
            $vig = $this->vigente();
            if ($vig === null || $vig['plan_programado_id'] === null) {
                throw new HttpException(409, 'no_scheduled_change', 'No hay un cambio programado.');
            }
            $this->subs->scheduleChange($this->subs->lock((int) $vig['id']), null, null, $uid);
        });
        Audit::record('suscripcion.cambio_programado_anulado', 'exito', $request, $uid, $this->tid(), 'suscripciones', null);
        return ['mensaje' => 'Anulamos el cambio programado.'] + $this->overview(true);
    }

    /** @return array<string, mixed> */
    public function order(string $codigo): array
    {
        $o = $this->fetchOne('SELECT o.*, p.nombre plan_nombre FROM ordenes_pago o JOIN planes p ON p.id = o.plan_id WHERE o.tenant_id = :t AND o.codigo = :c',
            ['t' => $this->tid(), 'c' => $codigo]);
        if ($o === null) {
            throw new NotFoundException('Orden de pago no encontrada.');
        }
        return self::presentOrder($o);
    }

    /** @return list<array<string, mixed>> */
    public function orders(int $limit): array
    {
        return array_map([self::class, 'presentOrder'], $this->fetchAll(
            "SELECT o.*, p.nombre plan_nombre FROM ordenes_pago o JOIN planes p ON p.id = o.plan_id WHERE o.tenant_id = :t ORDER BY o.id DESC LIMIT {$limit}",
            ['t' => $this->tid()]
        ));
    }

    /**
     * @param array<string, mixed> $o
     * @return array<string, mixed>
     */
    public static function presentOrder(array $o): array
    {
        $estado = (string) $o['estado'];
        if ($estado === 'pendiente' && (string) $o['expira_en'] <= Clock::nowSql()) {
            $estado = 'expirada';
        }
        return [
            'codigo' => (string) $o['codigo'], 'tipo' => (string) $o['tipo'], 'estado' => $estado,
            'plan' => ['id' => (int) $o['plan_id'], 'nombre' => (string) ($o['plan_nombre'] ?? '')],
            'ciclo' => (string) $o['ciclo'], 'periodos' => (int) $o['periodos'], 'monto' => (string) $o['monto'], 'moneda' => (string) $o['moneda'],
            'detalle' => (string) $o['detalle'], 'pasarela' => (string) $o['pasarela'],
            'url' => $estado === 'pendiente' ? $o['checkout_url'] : null,
            'por_revisar' => $o['error'] !== null && str_starts_with((string) $o['error'], 'Requiere revisión'),
            'creada_en' => self::iso((string) $o['created_at']), 'expira_en' => self::iso((string) $o['expira_en']),
            'pagada_en' => $o['pagada_en'] ? self::iso((string) $o['pagada_en']) : null,
        ];
    }

    // ------------------------------------------------------------------ internos

    private function lockTenant(): void
    {
        $this->fetchOne('SELECT id FROM tenants WHERE id = :id FOR UPDATE', ['id' => $this->tid()]);
    }

    private function assertTenantCanPay(): void
    {
        $estado = (string) ($this->fetchOne('SELECT estado FROM tenants WHERE id = :id', ['id' => $this->tid()])['estado'] ?? 'cancelado');
        if ($estado === 'pendiente') {
            throw new HttpException(409, 'tenant_pending', 'Tu barbería aún está en revisión. La prueba gratuita empieza cuando BARBERAPP la apruebe.');
        }
        if ($estado === 'cancelado') {
            throw new HttpException(409, 'tenant_cancelled', 'La barbería está dada de baja.');
        }
        $last = $this->fetchOne('SELECT estado, periodo_fin_en FROM suscripciones WHERE tenant_id = :t ORDER BY id DESC LIMIT 1', ['t' => $this->tid()]);
        if ($last !== null && $last['estado'] === 'suspendida' && (string) $last['periodo_fin_en'] > Clock::nowSql()) {
            throw new HttpException(409, 'subscription_suspended', 'Tu suscripción está suspendida por BARBERAPP. Escríbenos a soporte para resolverlo.');
        }
    }

    /** Suscripción vigente real (prueba o activa con periodo en curso), o null. @return array<string, mixed>|null */
    private function vigente(): ?array
    {
        return $this->fetchOne("SELECT * FROM suscripciones WHERE tenant_id = :t AND estado IN ('prueba','activa') AND periodo_fin_en > :n ORDER BY id DESC LIMIT 1",
            ['t' => $this->tid(), 'n' => Clock::nowSql()]);
    }

    /** @return array<string, mixed> plan activo y público (o el actual) */
    private function plan(int $id, ?int $currentPlan): array
    {
        $p = $this->fetchOne('SELECT * FROM planes WHERE id = :id AND activo = 1', ['id' => $id]);
        if ($p === null || (!(bool) $p['publico'] && $id !== $currentPlan)) {
            throw new ValidationException(['plan_id' => ['Ese plan no está disponible.']]);
        }
        return $p;
    }

    /**
     * @param array<string, mixed> $plan presentado
     * @param array<string, int>   $uso
     */
    private static function exceeds(array $plan, array $uso): bool
    {
        return ($plan['max_sucursales'] !== null && $uso['sucursales'] > (int) $plan['max_sucursales'])
            || ($plan['max_barberos'] !== null && $uso['barberos'] > (int) $plan['max_barberos']);
    }

    /**
     * @param array<string, mixed> $plan fila
     * @param array<string, int>   $uso
     */
    private function exceedsError(array $plan, array $uso): HttpException
    {
        $p = PlanService::present($plan);
        $partes = [];
        if ($p['max_sucursales'] !== null && $uso['sucursales'] > $p['max_sucursales']) {
            $partes[] = "{$p['max_sucursales']} sucursal(es) activa(s) (tienes {$uso['sucursales']})";
        }
        if ($p['max_barberos'] !== null && $uso['barberos'] > $p['max_barberos']) {
            $partes[] = "{$p['max_barberos']} barbero(s) activo(s) (tienes {$uso['barberos']})";
        }
        return new HttpException(409, 'plan_limit_exceeded', "El plan {$p['nombre']} permite " . implode(' y ', $partes) . '. Desactiva lo que sobra antes de cambiar.',
            ['uso' => $uso, 'max_sucursales' => $p['max_sucursales'], 'max_barberos' => $p['max_barberos']]);
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function result(string $tipo, array $plan, string $ciclo, int $periodos, string $monto, DateTimeImmutable $desde, DateTimeImmutable $hasta, ?int $subId): array
    {
        $unidad = $ciclo === 'anual' ? ($periodos === 1 ? 'año' : 'años') : ($periodos === 1 ? 'mes' : 'meses');
        $concepto = ['renovacion' => 'Renovación', 'contratacion' => 'Plan', 'mejora' => 'Mejora a', 'mejora_sin_cobro' => 'Mejora a'][$tipo];
        $detalle = $tipo === 'mejora' || $tipo === 'mejora_sin_cobro'
            ? "BARBERAPP · {$concepto} {$plan['nombre']} hasta el " . self::localDate($hasta)
            : "BARBERAPP · {$concepto} {$plan['nombre']} {$ciclo} · {$periodos} {$unidad}";
        return [
            'tipo' => $tipo, 'suscripcion_id' => $subId,
            'plan' => ['id' => (int) $plan['id'], 'codigo' => (string) $plan['codigo'], 'nombre' => (string) $plan['nombre']],
            'ciclo' => $ciclo, 'periodos' => $periodos, 'monto' => $monto, 'moneda' => (string) $plan['moneda'],
            'desde' => self::iso($desde), 'hasta' => self::iso($hasta), 'detalle' => mb_substr($detalle . ' · ' . $this->tenant['nombre_comercial'], 0, 255),
        ];
    }

    private static function money(float $v): string
    {
        return number_format(round($v, 2), 2, '.', '');
    }

    /** @param DateTimeImmutable|string $d */
    private static function iso($d): string
    {
        return $d instanceof DateTimeImmutable ? $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') : str_replace(' ', 'T', $d) . 'Z';
    }

    public static function localDate(DateTimeImmutable $d): string
    {
        return $d->setTimezone(new DateTimeZone(PlatformSettings::timezone()))->format('d/m/Y');
    }
}
