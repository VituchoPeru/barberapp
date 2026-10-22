<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Helpers\Clock;
use App\Services\Platform\PlanService;
use App\Services\Platform\PlatformSettings;
use App\Services\Platform\SubscriptionService;

/**
 * Qué puede usar una barbería según su plan (Fase 10).
 *
 * El plan que rige es el de la suscripción vigente (prueba o activa). Sin suscripción vigente
 * rige el plan de la última que tuvo (sus límites no crecen por dejar de pagar). Una barbería
 * que nunca tuvo suscripción (instalaciones anteriores a la Fase 10) usa el plan de la prueba
 * automática y no se restringe.
 *
 * Los límites se aplican al crear o activar sucursales y barberos; nunca se desactiva nada
 * por superarlos (p. ej. tras bajar de plan): solo se impide crecer más. La restricción por
 * falta de pago (tenants.restringido_en) la marca el cron pasada la gracia y se levanta al
 * pagar: sin marketplace ni reservas nuevas, pero las citas existentes se atienden.
 */
final class Entitlements
{
    public const RECURSOS = ['sucursales' => 'max_sucursales', 'barberos' => 'max_barberos'];

    private const FEATURE_LABELS = [
        'reportes_avanzados' => 'Los reportes avanzados (ocupación, evolución mensual y rangos de más de 31 días)',
        'personalizacion' => 'La personalización de colores',
    ];

    /** @var array<int, self> */
    private static array $cache = [];

    /**
     * @param array<string, mixed>      $plan plan presentado (PlanService::present)
     * @param array<string, mixed>|null $sub  fila de la suscripción que rige (vigente o la última)
     */
    private function __construct(
        public int $tenantId,
        public array $plan,
        public ?array $sub,
        public string $estado,
        public ?string $restringidoEn
    ) {
    }

    public static function for(int $tenantId): self
    {
        return self::$cache[$tenantId] ??= self::load($tenantId);
    }

    public static function flush(?int $tenantId = null): void
    {
        if ($tenantId === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$tenantId]);
        }
    }

    private static function load(int $tenantId): self
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT * FROM suscripciones WHERE tenant_id = ?
              ORDER BY (estado IN ('prueba','activa') AND periodo_fin_en > ?) DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$tenantId, Clock::nowSql()]);
        $sub = $stmt->fetch() ?: null;
        $planId = $sub !== null ? (int) $sub['plan_id'] : null;
        if ($planId === null) {
            $p = $db->prepare('SELECT id FROM planes WHERE codigo = ?');
            $p->execute([(string) PlatformSettings::all()['plataforma.plan_prueba']]);
            $planId = (int) ($p->fetchColumn() ?: 0);
        }
        $p = $db->prepare('SELECT * FROM planes WHERE id = ?');
        $p->execute([$planId]);
        $planRow = $p->fetch();
        if (!$planRow) {
            $planRow = $db->query('SELECT * FROM planes ORDER BY orden, id LIMIT 1')->fetch();
        }
        $plan = $planRow ? PlanService::present($planRow) : [
            'id' => 0, 'codigo' => 'ninguno', 'nombre' => 'Sin plan', 'max_sucursales' => 1, 'max_barberos' => 1,
            'caracteristicas' => PlanService::features([]),
        ];
        $t = $db->prepare('SELECT restringido_en FROM tenants WHERE id = ?');
        $t->execute([$tenantId]);
        $restringido = $t->fetchColumn();
        $estado = $sub === null ? 'sin_suscripcion' : SubscriptionService::effectiveState((string) $sub['estado'], (string) $sub['periodo_fin_en']);
        return new self($tenantId, $plan, $sub, $estado, $restringido ? (string) $restringido : null);
    }

    public function vigente(): bool
    {
        return in_array($this->estado, ['prueba', 'activa'], true);
    }

    public function restricted(): bool
    {
        return $this->restringidoEn !== null;
    }

    public function limit(string $recurso): ?int
    {
        $v = $this->plan[self::RECURSOS[$recurso]] ?? null;
        return $v === null ? null : (int) $v;
    }

    public function feature(string $feature): bool
    {
        return (bool) ($this->plan['caracteristicas'][$feature] ?? false);
    }

    public function channel(string $canal): bool
    {
        return $canal === 'interna' || in_array($canal, (array) ($this->plan['caracteristicas']['canales'] ?? []), true);
    }

    /** @return array{sucursales:int, barberos:int} */
    public static function usage(int $tenantId): array
    {
        $db = Database::connection();
        $s = $db->prepare('SELECT COUNT(*) FROM sucursales WHERE tenant_id = ? AND activo = 1 AND deleted_at IS NULL');
        $s->execute([$tenantId]);
        $b = $db->prepare('SELECT COUNT(*) FROM barberos WHERE tenant_id = ? AND activo = 1 AND deleted_at IS NULL');
        $b->execute([$tenantId]);
        return ['sucursales' => (int) $s->fetchColumn(), 'barberos' => (int) $b->fetchColumn()];
    }

    /**
     * Dentro de la transacción que crea o activa el recurso: bloquea la fila de la barbería (dos
     * altas simultáneas no superan el límite) y comprueba el uso actual.
     */
    public static function assertCanAdd(int $tenantId, string $recurso): void
    {
        $db = Database::connection();
        $db->prepare('SELECT id FROM tenants WHERE id = ? FOR UPDATE')->execute([$tenantId]);
        self::flush($tenantId);
        $e = self::for($tenantId);
        if ($e->restricted()) {
            throw self::restrictedError();
        }
        $limit = $e->limit($recurso);
        if ($limit === null) {
            return;
        }
        $uso = self::usage($tenantId)[$recurso];
        if ($uso >= $limit) {
            $nombre = $recurso === 'sucursales' ? ($limit === 1 ? 'sucursal activa' : 'sucursales activas') : ($limit === 1 ? 'barbero activo' : 'barberos activos');
            throw new HttpException(409, 'plan_limit',
                "Tu plan {$e->plan['nombre']} permite {$limit} {$nombre} y ya tienes {$uso}. Sube de plan desde Suscripción o desactiva " . ($recurso === 'sucursales' ? 'una' : 'uno') . '.',
                ['recurso' => $recurso, 'limite' => $limit, 'uso' => $uso, 'plan' => $e->plan['codigo']]);
        }
    }

    public function assertFeature(string $feature): void
    {
        if (!$this->feature($feature)) {
            $que = self::FEATURE_LABELS[$feature] ?? 'Esta función';
            throw new HttpException(403, 'plan_feature', "{$que} no están incluidos en tu plan {$this->plan['nombre']}. Puedes subir de plan desde Suscripción.",
                ['funcion' => $feature, 'plan' => $this->plan['codigo']]);
        }
    }

    public function assertNotRestricted(): void
    {
        if ($this->restricted()) {
            throw self::restrictedError();
        }
    }

    public static function restrictedError(): HttpException
    {
        return new HttpException(402, 'subscription_required',
            'La suscripción de la barbería venció. Renueva desde Suscripción para volver a recibir reservas nuevas y ampliar tu equipo; las citas ya registradas se mantienen.');
    }

    /**
     * Resumen para el panel de la barbería: plan, estado, uso frente a límites y funciones.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $uso = self::usage($this->tenantId);
        $fin = $this->sub !== null ? (string) $this->sub['periodo_fin_en'] : null;
        $dias = $fin !== null && $this->vigente() ? (int) ceil((strtotime($fin . ' UTC') - Clock::nowUtc()->getTimestamp()) / 86400) : null;
        $gracia = (int) PlatformSettings::all()['plataforma.dias_gracia'];
        $terminada = $this->sub !== null && !$this->vigente() ? (string) ($this->sub['terminada_en'] ?? $fin) : null;
        $restringeEn = null;
        if ($terminada !== null && !$this->restricted() && (bool) PlatformSettings::all()['plataforma.restringir_sin_suscripcion']) {
            $restringeEn = self::iso(gmdate('Y-m-d H:i:s', strtotime($terminada . ' UTC') + $gracia * 86400));
        }
        $limites = [];
        foreach (self::RECURSOS as $r => $_) {
            $limites[$r] = ['limite' => $this->limit($r), 'uso' => $uso[$r], 'excedido' => $this->limit($r) !== null && $uso[$r] > (int) $this->limit($r)];
        }
        return [
            'estado' => $this->estado,
            'vigente' => $this->vigente(),
            'plan' => ['id' => (int) $this->plan['id'], 'codigo' => (string) $this->plan['codigo'], 'nombre' => (string) $this->plan['nombre']],
            'suscripcion_id' => $this->sub !== null ? (int) $this->sub['id'] : null,
            'periodo_fin_en' => $fin !== null ? self::iso($fin) : null,
            'dias_restantes' => $dias,
            'restringida' => $this->restricted(),
            'restringida_desde' => $this->restringidoEn !== null ? self::iso($this->restringidoEn) : null,
            'restriccion_en' => $restringeEn,
            'limites' => $limites,
            'funciones' => $this->plan['caracteristicas'],
        ];
    }

    private static function iso(string $sql): string
    {
        return str_replace(' ', 'T', $sql) . 'Z';
    }
}
