<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Auth\Gate;
use App\Services\Barber\BarberPanelService;

/**
 * Panel del barbero (Fase 7). Todo se limita al barbero vinculado a la cuenta.
 *
 *   GET    /api/v1/barbero/hoy?fecha=                  Agenda del día, jornada, en atención y siguiente
 *   GET    /api/v1/barbero/proximas?dias=              Próximas citas activas (1–31 días)
 *   GET    /api/v1/barbero/historial?desde=&hasta=&estado=&page=   Citas pasadas
 *   GET    /api/v1/barbero/citas/{id}                  Detalle + visitas anteriores del cliente con este barbero
 *   POST   /api/v1/barbero/citas/{id}/confirmar | iniciar | completar | no-asistio
 *   PUT    /api/v1/barbero/citas/{id}/notas            Notas internas
 *   GET    /api/v1/barbero/horario?desde=&dias=        Jornada semanal y efectiva
 *   PUT    /api/v1/barbero/horario                     Cambiar su jornada (si la barbería lo permite)
 *   GET    /api/v1/barbero/ausencias                   Sus solicitudes y bloqueos
 *   POST   /api/v1/barbero/ausencias                   Solicitar una ausencia o bloqueo
 *   DELETE /api/v1/barbero/ausencias/{id}              Retirar una solicitud pendiente
 */
final class BarberPanelController extends BaseController
{
    public function day(Request $request): Response
    {
        return $this->ok($this->svc($request)->day($request->allQuery()));
    }

    public function upcoming(Request $request): Response
    {
        return $this->ok($this->svc($request)->upcoming($request->allQuery()));
    }

    public function history(Request $request): Response
    {
        $p = $this->pagination($request, 20, 50);
        $res = $this->svc($request)->history($request->allQuery(), $p['page'], $p['per_page']);
        return $this->ok($res['items'], $res['meta']);
    }

    public function show(Request $request): Response
    {
        return $this->ok($this->svc($request)->detail((int) $request->param('id')));
    }

    public function confirm(Request $request): Response
    {
        return $this->ok($this->svc($request)->transition((int) $request->param('id'), 'confirmar', $request));
    }

    public function start(Request $request): Response
    {
        return $this->ok($this->svc($request)->transition((int) $request->param('id'), 'iniciar', $request));
    }

    public function complete(Request $request): Response
    {
        return $this->ok($this->svc($request)->transition((int) $request->param('id'), 'completar', $request));
    }

    public function noShow(Request $request): Response
    {
        return $this->ok($this->svc($request)->transition((int) $request->param('id'), 'no_asistio', $request));
    }

    public function notes(Request $request): Response
    {
        return $this->ok($this->svc($request)->notes((int) $request->param('id'), $request->json(), $request));
    }

    public function schedule(Request $request): Response
    {
        return $this->ok($this->svc($request)->schedule($request->allQuery()));
    }

    public function updateSchedule(Request $request): Response
    {
        return $this->ok($this->svc($request)->updateSchedule($request->json(), $request));
    }

    public function blocks(Request $request): Response
    {
        return $this->ok($this->svc($request)->blocks($request->allQuery()));
    }

    public function requestBlock(Request $request): Response
    {
        return $this->created($this->svc($request)->requestBlock($request->json(), $request));
    }

    public function withdrawBlock(Request $request): Response
    {
        $this->svc($request)->withdrawBlock((int) $request->param('id'), $request);
        return $this->noContent();
    }

    private function svc(Request $request): BarberPanelService
    {
        $membership = (array) $request->attribute('membership');
        $tenant = $this->tenant($request);
        return new BarberPanelService(
            (int) $tenant['id'],
            Gate::rolePermissions($membership['roles'] ?? []),
            (int) ((array) $request->attribute('auth_user'))['id'],
            (string) $tenant['zona_horaria']
        );
    }
}
