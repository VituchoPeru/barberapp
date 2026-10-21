<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Catalog\CatalogPresenter;
use App\Services\Catalog\ServiceCatalog;

/**
 * Servicios y categorías (Fase 4, permiso servicios.gestionar).
 *   GET    /api/v1/panel/servicios           Todos (activos e inactivos) + categorías y tipos en meta
 *   POST   /api/v1/panel/servicios           Crear
 *   GET    /api/v1/panel/servicios/{id}      Detalle
 *   PUT    /api/v1/panel/servicios/{id}      Editar (parcial): nombre, duración, precio, activo…
 *   DELETE /api/v1/panel/servicios/{id}      Eliminar (lógico; las reservas conservan su copia)
 *   GET|POST /api/v1/panel/categorias        Categorías
 *   PUT|DELETE /api/v1/panel/categorias/{id}
 */
final class ServicesController extends BaseController
{
    public function index(Request $request): Response
    {
        $svc = $this->service($request);
        return $this->ok(array_map([CatalogPresenter::class, 'service'], $svc->repo()->all()), [
            'categorias' => array_map([CatalogPresenter::class, 'category'], $svc->repo()->categories()),
            'tipos' => $svc->repo()->tipos(),
            'reglas' => ['duracion_min' => ServiceCatalog::DURACION_MIN, 'duracion_max' => ServiceCatalog::DURACION_MAX, 'duracion_paso' => 5, 'precio_max' => ServiceCatalog::PRECIO_MAX],
        ]);
    }

    public function show(Request $request): Response
    {
        return $this->ok(CatalogPresenter::service($this->service($request)->get((int) $request->param('id'))));
    }

    public function store(Request $request): Response
    {
        return $this->created(CatalogPresenter::service($this->service($request)->create($request->json(), $request)));
    }

    public function update(Request $request): Response
    {
        $r = $this->service($request)->update((int) $request->param('id'), $request->json(), $request);
        return $this->ok(CatalogPresenter::service($r['servicio']), ['reservas_futuras' => $r['reservas_futuras']]);
    }

    public function destroy(Request $request): Response
    {
        $futuras = $this->service($request)->delete((int) $request->param('id'), $request);
        return $this->ok(null, ['reservas_futuras' => $futuras]);
    }

    public function categories(Request $request): Response
    {
        return $this->ok(array_map([CatalogPresenter::class, 'category'], $this->service($request)->repo()->categories()));
    }

    public function storeCategory(Request $request): Response
    {
        return $this->created(CatalogPresenter::category($this->service($request)->createCategory($request->json(), $request)));
    }

    public function updateCategory(Request $request): Response
    {
        return $this->ok(CatalogPresenter::category($this->service($request)->updateCategory((int) $request->param('id'), $request->json(), $request)));
    }

    public function destroyCategory(Request $request): Response
    {
        $this->service($request)->deleteCategory((int) $request->param('id'), $request);
        return $this->noContent();
    }

    private function service(Request $request): ServiceCatalog
    {
        return new ServiceCatalog($this->tenant($request)['id']);
    }
}
