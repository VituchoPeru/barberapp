<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Clock;
use App\Services\Audit;
use App\Services\Auth\Gate;
use App\Services\Platform\AuditBrowser;
use App\Services\Platform\PlatformMetrics;
use App\Services\Platform\PlatformSettings;
use App\Services\Platform\SystemStatus;
use App\Services\Platform\TemplateAdmin;

/**
 * Panel del superadministrador SaaS (Fase 9): contexto, dashboard, reportes globales, estado
 * del sistema, configuración, plantillas de avisos y auditoría.
 *
 *   GET  /api/v1/plataforma/contexto                       rol y permisos de plataforma
 *   GET  /api/v1/plataforma/dashboard?excluir_demo=1       (plataforma.reportes.ver)
 *   GET  /api/v1/plataforma/reportes?meses=12&formato=csv  (plataforma.reportes.ver)
 *   GET  /api/v1/plataforma/sistema                        (plataforma.reportes.ver)
 *   GET  /api/v1/plataforma/configuracion | PUT            (plataforma.configuracion.gestionar)
 *   GET  /api/v1/plataforma/plantillas                     (plataforma.plantillas.gestionar)
 *   PUT  /api/v1/plataforma/plantillas/{codigo}/{canal}    · DELETE = restaurar · POST …/vista-previa
 *   GET  /api/v1/plataforma/auditoria?accion=&resultado=&tenant=&usuario=&desde=&hasta=&antes_de=
 *   GET  /api/v1/plataforma/auditoria/acciones             (plataforma.auditoria.ver)
 */
final class PlatformController extends BaseController
{
    public function context(Request $request): Response
    {
        $user = (array) $request->attribute('auth_user');
        $stmt = Database::connection()->prepare('SELECT codigo, nombre FROM roles WHERE id = ?');
        $stmt->execute([(int) $user['rol_plataforma_id']]);
        $rol = $stmt->fetch() ?: ['codigo' => null, 'nombre' => null];
        $settings = PlatformSettings::all();
        return $this->ok([
            'usuario' => ['id' => (int) $user['id'], 'nombre' => trim($user['nombres'] . ' ' . ($user['apellidos'] ?? '')), 'email' => $user['email']],
            'rol' => ['codigo' => $rol['codigo'], 'nombre' => $rol['nombre']],
            'permisos' => Gate::platformPermissions($user),
            'plataforma' => ['nombre' => $settings['plataforma.nombre'], 'zona_horaria' => PlatformSettings::timezone(), 'moneda' => $settings['plataforma.moneda']],
        ]);
    }

    public function dashboard(Request $request): Response
    {
        return $this->ok((new PlatformMetrics(self::flag($request, 'excluir_demo')))->dashboard());
    }

    public function report(Request $request): Response
    {
        $data = (new PlatformMetrics(self::flag($request, 'excluir_demo')))->report((int) $request->query('meses', 12));
        if ($request->query('formato') === 'csv') {
            Audit::record('plataforma.reportes.exportado', 'exito', $request, null, null, null, null, ['meses' => count($data['meses']), 'excluir_demo' => $data['excluye_demo']]);
            $res = new Response(PlatformMetrics::csv($data), 200);
            $res->setHeader('Content-Type', 'text/csv; charset=utf-8');
            $res->setHeader('Content-Disposition', 'attachment; filename="barberapp-reporte-global-' . Clock::nowUtc()->format('Ymd') . '.csv"');
            return $res;
        }
        return $this->ok($data);
    }

    public function system(Request $request): Response
    {
        return $this->ok(SystemStatus::snapshot());
    }

    public function settings(Request $request): Response
    {
        return $this->ok(PlatformSettings::view());
    }

    public function updateSettings(Request $request): Response
    {
        $uid = (int) ((array) $request->attribute('auth_user'))['id'];
        [$antes, $despues] = PlatformSettings::update($request->json(), $uid);
        if ($despues !== []) {
            Audit::record('plataforma.configuracion.actualizada', 'exito', $request, $uid, null, 'configuraciones', null, $despues, $antes);
        }
        return $this->ok(PlatformSettings::view(), ['cambios' => array_keys($despues)]);
    }

    public function templates(Request $request): Response
    {
        return $this->ok(TemplateAdmin::list());
    }

    public function saveTemplate(Request $request): Response
    {
        return $this->ok(TemplateAdmin::save((string) $request->param('codigo'), (string) $request->param('canal'), $request->json(), $request));
    }

    public function restoreTemplate(Request $request): Response
    {
        return $this->ok(TemplateAdmin::restore((string) $request->param('codigo'), (string) $request->param('canal'), $request));
    }

    public function previewTemplate(Request $request): Response
    {
        return $this->ok(TemplateAdmin::preview((string) $request->param('codigo'), (string) $request->param('canal'), $request->json()));
    }

    public function audit(Request $request): Response
    {
        $limit = min(100, max(10, (int) $request->query('per_page', 50)));
        $r = (new AuditBrowser())->list($request->allQuery(), $limit);
        return $this->ok($r['items'], ['siguiente' => $r['siguiente'], 'per_page' => $limit]);
    }

    public function auditActions(Request $request): Response
    {
        return $this->ok((new AuditBrowser())->actions());
    }

    private static function flag(Request $request, string $key): bool
    {
        return in_array((string) $request->query($key, ''), ['1', 'true', 'si'], true);
    }
}
