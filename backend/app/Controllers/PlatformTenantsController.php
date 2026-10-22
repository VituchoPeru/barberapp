<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Validator;
use App\Services\Platform\TenantAdminService;

/**
 * Gestión de barberías del panel SaaS (Fase 9). Permiso plataforma.tenants.gestionar.
 *
 *   GET  /api/v1/plataforma/barberias?q=&estado=&plan=&demo=&page=&per_page=
 *   GET  /api/v1/plataforma/barberias/{id}
 *   PUT  /api/v1/plataforma/barberias/{id}               {es_demo?, nota_interna?}
 *   POST /api/v1/plataforma/barberias/{id}/aprobar
 *   POST /api/v1/plataforma/barberias/{id}/rechazar      {motivo}
 *   POST /api/v1/plataforma/barberias/{id}/suspender     {motivo}
 *   POST /api/v1/plataforma/barberias/{id}/reactivar
 */
final class PlatformTenantsController extends BaseController
{
    public function index(Request $request): Response
    {
        $pg = $this->pagination($request, 20, 100);
        $r = (new TenantAdminService())->list($request->allQuery(), $pg['per_page'], $pg['offset']);
        return $this->ok($r['items'], ['total' => $r['total'], 'page' => $pg['page'], 'per_page' => $pg['per_page'], 'resumen' => $r['resumen']]);
    }

    public function show(Request $request): Response
    {
        return $this->ok((new TenantAdminService())->show($this->id($request)));
    }

    public function update(Request $request): Response
    {
        $data = $this->validate($request, ['es_demo' => 'nullable|boolean', 'nota_interna' => 'nullable|string|max:500']);
        return $this->ok((new TenantAdminService())->update($this->id($request), $data, $request));
    }

    public function approve(Request $request): Response
    {
        return $this->ok((new TenantAdminService())->approve($this->id($request), $request));
    }

    public function reject(Request $request): Response
    {
        return $this->ok((new TenantAdminService())->reject($this->id($request), $this->motivo($request), $request));
    }

    public function suspend(Request $request): Response
    {
        return $this->ok((new TenantAdminService())->suspend($this->id($request), $this->motivo($request), $request));
    }

    public function reactivate(Request $request): Response
    {
        return $this->ok((new TenantAdminService())->reactivate($this->id($request), $request));
    }

    private function id(Request $request): int
    {
        return (int) $request->param('id');
    }

    private function motivo(Request $request): string
    {
        return (string) Validator::validate($request->json(), ['motivo' => 'required|string|min:5|max:255'])['motivo'];
    }
}
