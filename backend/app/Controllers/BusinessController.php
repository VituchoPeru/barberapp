<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Validator;
use App\Repositories\TenantSettingsRepository;
use App\Services\Audit;

/**
 * Configuración comercial de la barbería (Fase 6, permiso negocio.perfil.gestionar).
 *   GET /api/v1/panel/negocio   Nombre comercial, colores, enlace y zona horaria
 *   PUT /api/v1/panel/negocio   nombre_comercial, color_primario, color_secundario
 * El logo, la portada, el enlace directo y el QR se gestionan en /panel/perfil-publico (Fase 3).
 */
final class BusinessController extends BaseController
{
    public function show(Request $request): Response
    {
        return $this->ok((new TenantSettingsRepository((int) $this->tenant($request)['id']))->presented());
    }

    public function update(Request $request): Response
    {
        $input = $request->json();
        foreach (['color_primario', 'color_secundario'] as $k) {
            if (isset($input[$k]) && is_string($input[$k])) {
                $input[$k] = strtoupper(trim($input[$k]));
            }
        }
        $data = Validator::validate($input, [
            'nombre_comercial' => 'nullable|string|min:2|max:120',
            'color_primario' => 'nullable|regex:/^#[0-9A-F]{6}$/',
            'color_secundario' => 'nullable|regex:/^#[0-9A-F]{6}$/',
        ]);
        $data = array_filter($data, static fn ($v): bool => $v !== null);
        if ($data === []) {
            throw new ValidationException(['nombre_comercial' => ['No hay cambios para guardar.']]);
        }
        $t = $this->tenant($request);
        $repo = new TenantSettingsRepository((int) $t['id']);
        $before = $repo->presented();
        $repo->update($data);
        Audit::record('negocio.actualizado', 'exito', $request, null, (int) $t['id'], 'tenants', (int) $t['id'], $data,
            array_intersect_key(['nombre_comercial' => $before['nombre_comercial'], 'color_primario' => $before['colores']['primario'], 'color_secundario' => $before['colores']['secundario']], $data));
        return $this->ok($repo->presented());
    }
}
