<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\MembershipRepository;
use App\Services\Auth\Gate;
use App\Services\StaffService;

/**
 * Panel de la barbería (contexto por X-Tenant o por la barbería seleccionada en la sesión).
 *   GET    /api/v1/panel/contexto            Barbería, mis roles y permisos (cualquier integrante)
 *   GET    /api/v1/panel/personal            Equipo de la barbería          (personal.gestionar)
 *   POST   /api/v1/panel/personal            Invitar administrador/barbero  (personal.gestionar)
 *   DELETE /api/v1/panel/personal/{id}       Revocar acceso                 (personal.gestionar)
 */
final class StaffController extends BaseController
{
    public function context(Request $request): Response
    {
        $membership = (array) $request->attribute('membership');
        return $this->ok([
            'tenant' => $this->tenant($request),
            'roles' => $membership['roles'],
            'permisos' => Gate::rolePermissions($membership['roles']),
        ]);
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
