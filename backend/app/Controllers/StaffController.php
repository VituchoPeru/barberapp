<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Media;
use App\Helpers\Validator;
use App\Repositories\AgendaRepository;
use App\Repositories\BarberRepository;
use App\Repositories\MembershipRepository;
use App\Repositories\TeamSettingsRepository;
use App\Services\Audit;
use App\Services\Platform\PlatformSettings;
use App\Services\Auth\Gate;
use App\Services\StaffService;

/**
 * Panel de la barbería (contexto por X-Tenant o por la barbería seleccionada en la sesión).
 *   GET    /api/v1/panel/contexto            Barbería, mis roles y permisos (cualquier integrante)
 *   GET    /api/v1/panel/personal            Equipo de la barbería          (personal.gestionar)
 *   POST   /api/v1/panel/personal            Invitar administrador/barbero  (personal.gestionar)
 *   DELETE /api/v1/panel/personal/{id}       Revocar acceso                 (personal.gestionar)
 *   GET    /api/v1/panel/equipo/ajustes      Ajustes del equipo (Fase 7)    (personal.gestionar)
 *   PUT    /api/v1/panel/equipo/ajustes      ausencias_requieren_aprobacion, barbero_edita_horario
 */
final class StaffController extends BaseController
{
    public function context(Request $request): Response
    {
        $membership = (array) $request->attribute('membership');
        $tenant = $this->tenant($request);
        $user = (array) $request->attribute('auth_user');
        // Ficha de barbero vinculada a la cuenta (Fase 7): habilita el panel del barbero.
        $barberoId = (new AgendaRepository((int) $tenant['id']))->barberIdForUser((int) $user['id']);
        $barbero = $barberoId !== null ? (new BarberRepository((int) $tenant['id']))->find($barberoId) : null;
        return $this->ok([
            'tenant' => $tenant,
            // Fase 9: la barbería pendiente, suspendida o rechazada ve por qué y a quién escribir.
            'aviso_estado' => $tenant['estado'] === 'activo' ? null : self::stateNotice((int) $tenant['id'], (string) $tenant['estado']),
            'roles' => $membership['roles'],
            'permisos' => Gate::rolePermissions($membership['roles']),
            'barbero' => $barbero === null ? null : [
                'id' => (int) $barbero['id'], 'nombre' => (string) $barbero['nombre_publico'], 'foto_url' => Media::url($barbero['foto_url']),
            ],
        ]);
    }

    /** @return array{estado:string, motivo:?string, soporte_email:?string} */
    private static function stateNotice(int $tenantId, string $estado): array
    {
        $stmt = Database::connection()->prepare('SELECT motivo_suspension FROM tenants WHERE id = ?');
        $stmt->execute([$tenantId]);
        $soporte = (string) PlatformSettings::all()['plataforma.soporte_email'];
        return ['estado' => $estado, 'motivo' => ($m = $stmt->fetchColumn()) ? (string) $m : null, 'soporte_email' => $soporte !== '' ? $soporte : null];
    }

    public function teamSettings(Request $request): Response
    {
        return $this->ok((new TeamSettingsRepository((int) $this->tenant($request)['id']))->all());
    }

    public function updateTeamSettings(Request $request): Response
    {
        $data = Validator::validate($request->json(), array_map(static fn (): string => 'nullable|boolean', TeamSettingsRepository::AJUSTES));
        $tenantId = (int) $this->tenant($request)['id'];
        $repo = new TeamSettingsRepository($tenantId);
        $before = $repo->all();
        $userId = (int) ((array) $request->attribute('auth_user'))['id'];
        $changed = [];
        foreach ($data as $k => $v) {
            if ($v !== null && (bool) $v !== $before[$k]) {
                $repo->set($k, (bool) $v, $userId);
                $changed[$k] = (bool) $v;
            }
        }
        if ($changed !== []) {
            Audit::record('equipo.ajustes_actualizados', 'exito', $request, $userId, $tenantId, 'configuraciones', null, $changed, array_intersect_key($before, $changed));
        }
        return $this->ok($repo->all());
    }

    public function index(Request $request): Response
    {
        return $this->ok((new MembershipRepository())->staff($this->tenant($request)['id']));
    }

    public function invite(Request $request): Response
    {
        $data = $this->validate($request, [
            'email' => 'required|email',
            'rol' => 'required|in:' . implode(',', StaffService::ROLES_INVITABLES),
            'nombres' => 'nullable|string|min:2|max:100',
        ]);
        $data['nombres'] = $data['nombres'] ?? null;
        return $this->created((new StaffService($this->tenant($request)['id']))->invite($data, $request));
    }

    public function revoke(Request $request): Response
    {
        (new StaffService($this->tenant($request)['id']))->revoke((int) $request->param('id'), $request);
        return $this->noContent();
    }
}
