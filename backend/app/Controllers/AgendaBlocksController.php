<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Schedule\AgendaBlockService;

/**
 * Vacaciones, ausencias, feriados y bloqueos de agenda (Fase 4, permiso bloqueos.gestionar).
 *   GET    /api/v1/panel/bloqueos                 Filtros: desde, hasta, barbero_id, sucursal_id, estado, tipo
 *   POST   /api/v1/panel/bloqueos                 Registrar (queda aprobado)
 *   POST   /api/v1/panel/bloqueos/{id}/aprobar    Aprobar una solicitud de un barbero
 *   POST   /api/v1/panel/bloqueos/{id}/rechazar   Rechazar una solicitud
 *   DELETE /api/v1/panel/bloqueos/{id}            Eliminar (el intervalo vuelve a ofrecerse)
 */
final class AgendaBlocksController extends BaseController
{
    public function index(Request $request): Response
    {
        return $this->ok($this->service($request)->list($request->allQuery()));
    }

    public function store(Request $request): Response
    {
        return $this->created($this->service($request)->create($request->json(), $request));
    }

    public function approve(Request $request): Response
    {
        return $this->ok($this->service($request)->approve((int) $request->param('id'), $request));
    }

    public function reject(Request $request): Response
    {
        return $this->ok($this->service($request)->reject((int) $request->param('id'), $request));
    }

    public function destroy(Request $request): Response
    {
        $this->service($request)->delete((int) $request->param('id'), $request);
        return $this->noContent();
    }

    private function service(Request $request): AgendaBlockService
    {
        $t = $this->tenant($request);
        return new AgendaBlockService($t['id'], (string) $t['zona_horaria']);
    }
}
