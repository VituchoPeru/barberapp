<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Catalog\BranchService;
use App\Services\Catalog\CatalogPresenter;
use DateTimeZone;

/**
 * Sucursales de la barbería (Fase 4).
 *   GET    /api/v1/panel/sucursales               Lista con horario comercial (cualquier gestor del catálogo)
 *   POST   /api/v1/panel/sucursales               Registrar sucursal                 (sucursales.gestionar)
 *   GET    /api/v1/panel/sucursales/{id}          Detalle
 *   PUT    /api/v1/panel/sucursales/{id}          Editar, activar/desactivar, principal (sucursales.gestionar)
 *   DELETE /api/v1/panel/sucursales/{id}          Eliminar (sin barberos ni reservas)  (sucursales.gestionar)
 *   PUT    /api/v1/panel/sucursales/{id}/horario  Horario comercial                  (sucursales.gestionar)
 */
final class BranchesController extends BaseController
{
    public function index(Request $request): Response
    {
        $svc = $this->service($request);
        return $this->ok(array_map([CatalogPresenter::class, 'branch'], $svc->repo()->all()), [
            'zonas_horarias' => DateTimeZone::listIdentifiers(DateTimeZone::AMERICA),
        ]);
    }

    public function show(Request $request): Response
    {
        return $this->ok(CatalogPresenter::branch($this->service($request)->get((int) $request->param('id'))));
    }

    public function store(Request $request): Response
    {
        return $this->created(CatalogPresenter::branch($this->service($request)->create($request->json(), $request)));
    }

    public function update(Request $request): Response
    {
        return $this->ok(CatalogPresenter::branch($this->service($request)->update((int) $request->param('id'), $request->json(), $request)));
    }

    public function destroy(Request $request): Response
    {
        $this->service($request)->delete((int) $request->param('id'), $request);
        return $this->noContent();
    }

    public function hours(Request $request): Response
    {
        $r = $this->service($request)->replaceHours((int) $request->param('id'), $request->json(), $request);
        return $this->ok(CatalogPresenter::branch($r['sucursal']), ['advertencias' => ['jornadas_fuera_de_horario' => $r['advertencias']]]);
    }

    private function service(Request $request): BranchService
    {
        $t = $this->tenant($request);
        return new BranchService($t['id'], (string) $t['zona_horaria']);
    }
}
