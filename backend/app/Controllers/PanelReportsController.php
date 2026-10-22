<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Auth\Gate;
use App\Services\Panel\ReportService;
use App\Services\Reports\Export\CsvWriter;
use App\Services\Reports\Export\PdfWriter;
use App\Services\Reports\Export\XlsxWriter;
use App\Services\Reports\ReportExport;

/**
 * Dashboard, reportes y exportaciones de la barbería (Fases 6 y 11).
 *   GET /api/v1/panel/dashboard?fecha=&sucursal_id=                         (reservas.ver_todas o reportes.operativos)
 *   GET /api/v1/panel/reportes?desde=&hasta=&sucursal_id=&barbero_id=       (reportes.operativos)
 *   GET /api/v1/panel/reportes/exportar?tipo=resumen|reservas&formato=csv|xlsx|pdf&desde=&hasta=&sucursal_id=&barbero_id=
 *                                                                           (reportes.operativos; queda en la auditoría)
 * Los ingresos registrados solo se incluyen con reportes.financieros y los nombres de clientes
 * del detalle solo con clientes.ver.
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

    /** Máximo de reservas en el detalle exportado (se arma en memoria). */
    public const MAX_FILAS_DETALLE = 20000;

    public function export(Request $request): Response
    {
        $query = $request->allQuery();
        $tipo = (string) ($query['tipo'] ?? 'resumen');
        $formato = (string) ($query['formato'] ?? 'csv');
        $errors = [];
        if (!in_array($tipo, ['resumen', 'reservas'], true)) {
            $errors['tipo'] = ['Elige resumen o reservas.'];
        }
        if (!in_array($formato, ['csv', 'xlsx', 'pdf'], true)) {
            $errors['formato'] = ['Elige csv, xlsx o pdf.'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        unset($query['tipo'], $query['formato']);
        $svc = $this->svc($request);
        $t = $this->tenant($request);
        $c = $svc->criteria($query);
        $labels = [
            'sucursal' => $c['sucursalId'] !== null ? (string) $c['branches'][$c['sucursalId']]['nombre'] : null,
            'barbero' => null,
        ];
        if ($tipo === 'resumen') {
            $report = $svc->report($query);
            if ($c['barberoId'] !== null) {
                $labels['barbero'] = array_column($report['barberos'], 'nombre', 'id')[$c['barberoId']] ?? null;
            }
            $tables = ReportExport::summary($report, $labels);
            $filas = count($tables);
        } else {
            $rows = $svc->detailRows($c, self::MAX_FILAS_DETALLE);
            $tables = [ReportExport::detail($rows, $c['tz']->getName(), $svc->canSeeClients(), $svc->canSeeIncome())];
            $filas = count($rows);
        }
        Audit::record('reportes.exportado', 'exito', $request, null, (int) $t['id'], null, null, [
            'tipo' => $tipo, 'formato' => $formato, 'desde' => $c['desde'], 'hasta' => $c['hasta'],
            'sucursal_id' => $c['sucursalId'], 'barbero_id' => $c['barberoId'], ($tipo === 'resumen' ? 'tablas' : 'filas') => $filas,
        ]);

        $name = sprintf('%s-%s-%s_%s.%s', $tipo === 'resumen' ? 'reporte' : 'reservas', preg_replace('/[^a-z0-9-]/', '', (string) $t['slug']), $c['desde'], $c['hasta'], $formato);
        if ($formato === 'csv') {
            $preamble = [['Reporte BARBERAPP', (string) $t['nombre_comercial'], $c['desde'] . ' a ' . $c['hasta'], $c['tz']->getName()]];
            $res = new Response(CsvWriter::write($tables, $tipo === 'resumen' ? $preamble : []), 200);
            $res->setHeader('Content-Type', 'text/csv; charset=utf-8');
        } elseif ($formato === 'pdf') {
            // PDF generado en el servidor (Fase 12): la app nativa no tiene diálogo de impresión.
            $heading = array_values(array_filter([
                (string) $t['nombre_comercial'],
                'Periodo: ' . $c['desde'] . ' a ' . $c['hasta'] . ' · ' . $c['tz']->getName(),
                'Sucursal: ' . ($labels['sucursal'] ?? 'Todas') . ($tipo === 'resumen' ? ' · Barbero: ' . ($labels['barbero'] ?? 'Todos') : ''),
            ]));
            $generated = (new \DateTimeImmutable('now', $c['tz']))->format('Y-m-d H:i');
            $res = new Response(PdfWriter::write($tables, $tipo === 'resumen' ? 'Reporte de la barbería' : 'Reservas una por una', $heading, 'Generado el ' . $generated), 200);
            $res->setHeader('Content-Type', 'application/pdf');
        } else {
            $res = new Response(XlsxWriter::write($tables), 200);
            $res->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        $res->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"');
        $res->setHeader('Cache-Control', 'no-store');
        $res->setHeader('X-Content-Type-Options', 'nosniff');
        return $res;
    }

    private function svc(Request $request): ReportService
    {
        $t = $this->tenant($request);
        $roles = ((array) $request->attribute('membership'))['roles'] ?? [];
        return new ReportService((int) $t['id'], Gate::rolePermissions($roles), $t);
    }
}
