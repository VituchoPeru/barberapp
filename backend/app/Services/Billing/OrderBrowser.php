<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Helpers\Clock;
use App\Repositories\BaseRepository;
use App\Services\Billing\Gateway\Gateways;

/** Órdenes de pago en línea de todas las barberías, para el panel de la plataforma (Fase 10). */
final class OrderBrowser extends BaseRepository
{
    public const ESTADOS = ['pendiente', 'pagada', 'fallida', 'expirada', 'anulada'];

    /**
     * @param array<string, mixed> $f estado, tenant_id, por_revisar
     * @return array{items: list<array<string, mixed>>, total:int, pasarela: array<string, mixed>}
     */
    public function list(array $f, int $limit, int $offset): array
    {
        $where = ['t.deleted_at IS NULL'];
        $p = [];
        if (!empty($f['estado']) && in_array($f['estado'], self::ESTADOS, true)) {
            if ($f['estado'] === 'expirada') {
                $where[] = "(o.estado = 'expirada' OR (o.estado = 'pendiente' AND o.expira_en <= :now))";
                $p['now'] = Clock::nowSql();
            } else {
                $where[] = 'o.estado = :estado';
                $p['estado'] = $f['estado'];
            }
        }
        if (!empty($f['tenant_id'])) {
            $where[] = 'o.tenant_id = :t';
            $p['t'] = (int) $f['tenant_id'];
        }
        if (!empty($f['por_revisar'])) {
            $where[] = "o.error LIKE 'Requiere revisión%'";
        }
        $w = implode(' AND ', $where);
        $from = "FROM ordenes_pago o JOIN tenants t ON t.id = o.tenant_id JOIN planes p ON p.id = o.plan_id LEFT JOIN usuarios u ON u.id = o.solicitado_por WHERE {$w}";
        $total = (int) ($this->fetchOne("SELECT COUNT(*) n {$from}", $p)['n'] ?? 0);
        $rows = $this->fetchAll("SELECT o.*, p.nombre plan_nombre, t.slug tenant_slug, t.nombre_comercial tenant_nombre, t.es_demo tenant_demo, u.email solicitado_por_email
                                  {$from} ORDER BY o.id DESC LIMIT {$limit} OFFSET {$offset}", $p);
        return [
            'items' => array_map(static fn (array $o): array => BillingService::presentOrder($o) + [
                'barberia' => ['id' => (int) $o['tenant_id'], 'slug' => (string) $o['tenant_slug'], 'nombre' => (string) $o['tenant_nombre'], 'es_demo' => (bool) $o['tenant_demo']],
                'solicitado_por' => $o['solicitado_por_email'], 'pago_externo_id' => $o['pago_externo_id'],
                'pago_saas_id' => $o['pago_saas_id'] !== null ? (int) $o['pago_saas_id'] : null, 'error' => $o['error'],
            ], $rows),
            'total' => $total,
            'pasarela' => ['habilitada' => Gateways::enabled(), 'nombre' => Gateways::driver()],
        ];
    }
}
