<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\Media;
use App\Services\Marketplace\MarketplacePresenter;

/** Datos comerciales básicos de la barbería editables desde la configuración del panel (Fase 6). */
final class TenantSettingsRepository extends TenantScopedRepository
{
    /** @return array<string, mixed> */
    public function presented(): array
    {
        $t = (array) $this->fetchOne(
            'SELECT id, slug, nombre_comercial, logo_url, color_primario, color_secundario, zona_horaria, estado, publicado_marketplace
               FROM tenants WHERE id = :t',
            ['t' => $this->tenantId]
        );
        return [
            'slug' => (string) $t['slug'],
            'nombre_comercial' => (string) $t['nombre_comercial'],
            'logo_url' => Media::url($t['logo_url']),
            'colores' => ['primario' => (string) $t['color_primario'], 'secundario' => (string) $t['color_secundario']],
            'zona_horaria' => (string) $t['zona_horaria'],
            'estado' => (string) $t['estado'],
            'publicado_marketplace' => (bool) $t['publicado_marketplace'],
            'enlace' => MarketplacePresenter::bookingUrl((string) $t['slug']),
        ];
    }

    /** @param array<string, string> $data */
    public function update(array $data): void
    {
        $sets = [];
        $params = ['t' => $this->tenantId];
        foreach ($data as $k => $v) {
            $sets[] = "{$k} = :{$k}";
            $params[$k] = $v;
        }
        $this->execute('UPDATE tenants SET ' . implode(', ', $sets) . ' WHERE id = :t', $params);
    }
}
