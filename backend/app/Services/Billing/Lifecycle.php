<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Core\Database;
use App\Helpers\Clock;
use App\Repositories\BaseRepository;
use App\Services\Audit;
use App\Services\Platform\PlatformSettings;
use App\Services\Platform\SubscriptionService;

/**
 * Tareas periódicas de suscripciones (cron `suscripciones:vencer`, cada hora). Idempotente:
 *   1. aplica los cambios de plan programados cuyo momento llegó;
 *   2. marca vencidas las suscripciones cuyo periodo terminó;
 *   3. restringe las barberías sin suscripción vigente pasada la gracia (y levanta la marca de
 *      las que ya tienen una vigente);
 *   4. avisa al propietario antes del vencimiento (7, 3 y 1 días por defecto) y al vencer;
 *   5. expira las órdenes de pago abiertas.
 */
final class Lifecycle extends BaseRepository
{
    /** @return array<string, int> */
    public function run(): array
    {
        $subs = new SubscriptionService();
        $out = [];
        $out['cambios_aplicados'] = Database::transaction(fn (): int => $subs->applyScheduledChanges());
        $out['vencidas'] = Database::transaction(fn (): int => $subs->expireDue());
        [$out['restringidas'], $out['liberadas']] = $this->restrictions();
        $out['avisos'] = $this->notices();
        $out['ordenes_expiradas'] = $this->execute("UPDATE ordenes_pago SET estado = 'expirada' WHERE estado = 'pendiente' AND expira_en <= :n", ['n' => Clock::nowSql()]);
        Entitlements::flush();
        return $out;
    }

    /** @return array{0:int, 1:int} [restringidas, liberadas] */
    public function restrictions(): array
    {
        $cfg = PlatformSettings::all();
        $now = Clock::nowSql();
        $vigente = "EXISTS (SELECT 1 FROM suscripciones v WHERE v.tenant_id = t.id AND v.estado IN ('prueba','activa') AND v.periodo_fin_en > :nv)";
        $liberadas = $this->execute(
            "UPDATE tenants t SET t.restringido_en = NULL WHERE t.restringido_en IS NOT NULL AND (" . ((bool) $cfg['plataforma.restringir_sin_suscripcion'] ? $vigente : '1 = 1') . ')',
            (bool) $cfg['plataforma.restringir_sin_suscripcion'] ? ['nv' => $now] : []
        );
        if (!(bool) $cfg['plataforma.restringir_sin_suscripcion']) {
            return [0, $liberadas];
        }
        $limite = Clock::nowUtc()->modify('-' . (int) $cfg['plataforma.dias_gracia'] . ' days')->format('Y-m-d H:i:s');
        // Solo barberías que tuvieron suscripción (las anteriores a la Fase 10 sin ninguna no se tocan).
        $rows = $this->fetchAll(
            "SELECT t.id FROM tenants t
              WHERE t.estado = 'activo' AND t.deleted_at IS NULL AND t.restringido_en IS NULL
                AND EXISTS (SELECT 1 FROM suscripciones s WHERE s.tenant_id = t.id)
                AND NOT {$vigente}
                AND (SELECT MAX(LEAST(COALESCE(s2.terminada_en, s2.periodo_fin_en), s2.periodo_fin_en)) FROM suscripciones s2 WHERE s2.tenant_id = t.id) <= :lim",
            ['nv' => $now, 'lim' => $limite]
        );
        $n = 0;
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if ($this->execute('UPDATE tenants SET restringido_en = :n WHERE id = :id AND restringido_en IS NULL', ['n' => $now, 'id' => $id]) === 1) {
                Audit::record('suscripcion.barberia_restringida', 'exito', null, null, $id, 'tenants', $id, ['dias_gracia' => (int) $cfg['plataforma.dias_gracia']]);
                BillingNotifier::restricted($id, $now);
                $n++;
            }
        }
        return [$n, $liberadas];
    }

    /** Avisos de vencimiento próximo y de vencimiento. Devuelve cuántos avisos encoló. */
    public function notices(): int
    {
        $cfg = PlatformSettings::all();
        $umbrales = (array) $cfg['plataforma.avisos_vencimiento'];
        sort($umbrales);
        $now = Clock::nowUtc();
        $n = 0;
        if ($umbrales !== []) {
            $hasta = $now->modify('+' . max($umbrales) . ' days')->format('Y-m-d H:i:s');
            $rows = $this->fetchAll(
                "SELECT s.*, p.nombre plan_nombre FROM suscripciones s JOIN planes p ON p.id = s.plan_id JOIN tenants t ON t.id = s.tenant_id
                  WHERE s.estado IN ('prueba','activa') AND s.periodo_fin_en > :n AND s.periodo_fin_en <= :h AND t.deleted_at IS NULL AND t.estado <> 'cancelado'",
                ['n' => $now->format('Y-m-d H:i:s'), 'h' => $hasta]
            );
            foreach ($rows as $s) {
                $dias = (int) ceil((strtotime($s['periodo_fin_en'] . ' UTC') - $now->getTimestamp()) / 86400);
                // El umbral más pequeño ya alcanzado: cada umbral se avisa una sola vez por periodo.
                foreach ($umbrales as $u) {
                    if ($dias <= (int) $u) {
                        $n += BillingNotifier::expiring((int) $s['tenant_id'], $s, (int) $u, $dias);
                        break;
                    }
                }
            }
        }
        // Vencidas en los últimos 3 días cuya barbería no tiene otra vigente.
        $gracia = (int) $cfg['plataforma.dias_gracia'];
        $rows = $this->fetchAll(
            "SELECT s.*, p.nombre plan_nombre FROM suscripciones s JOIN planes p ON p.id = s.plan_id JOIN tenants t ON t.id = s.tenant_id
              WHERE s.estado = 'vencida' AND s.terminada_en > :desde AND t.deleted_at IS NULL AND t.estado = 'activo'
                AND s.id = (SELECT MAX(s2.id) FROM suscripciones s2 WHERE s2.tenant_id = s.tenant_id)",
            ['desde' => $now->modify('-3 days')->format('Y-m-d H:i:s')]
        );
        foreach ($rows as $s) {
            $restringe = gmdate('Y-m-d H:i:s', strtotime($s['terminada_en'] . ' UTC') + $gracia * 86400);
            $n += BillingNotifier::expired((int) $s['tenant_id'], $s, $restringe);
        }
        return $n;
    }
}
