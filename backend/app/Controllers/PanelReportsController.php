<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Auth\Gate;
use App\Services\Panel\ReportService;

/**
 * Dashboard y reportes operativos de la barbería (Fase 6).
 *   GET /api/v1/panel/dashboard?fecha=&sucursal_id=                         (reservas.ver_todas o reportes.operativos)
 *   GET /api/v1/panel/reportes?desde=&hasta=&sucursal_id=&barbero_id=       (reportes.operativos)
 * Los ingresos registrados solo se incluyen con reportes.financieros.
 */
final class PanelReportsController extends BaseController
{
    public function dashboard(Request $request): Response
    {
        return $this->ok($this->svc($request)->dashboard($request->allQuery()));
    }

    public function report(Request $request): Response
    {
        return $this->ok($this->svc($request)->report($request->allQuery()));
    }

    private function svc(Request $request): ReportService
    {
        $t = $this->tenant($request);
        $roles = ((array) $request->attribute('membership'))['roles'] ?? [];
        return new ReportService((int) $t['id'], Gate::rolePermissions($roles), $t);
    }
}
