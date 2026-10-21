<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\MembershipRepository;
use App\Repositories\SessionRepository;
use App\Repositories\TenantRepository;
use App\Repositories\UserRepository;
use App\Services\Audit;
use App\Services\Auth\Gate;

/**
 * GET  /api/v1/me                         Perfil, rol de plataforma y contexto actual
 * GET  /api/v1/me/memberships             Barberías donde trabaja (roles, permisos, invitaciones)
 * POST /api/v1/me/context                 Selecciona la barbería de trabajo de la sesión
 * POST /api/v1/me/memberships/{id}/aceptar | rechazar
 * GET  /api/v1/me/sessions                Sesiones abiertas
 * DELETE /api/v1/me/sessions/{id}         Cierra una sesión (otro dispositivo)
 */
final class MeController extends BaseController
{
    public function show(Request $request): Response
    {
        $user = (array) $request->attribute('auth_user');
        $session = (array) $request->attribute('auth_session');
        $memberships = new MembershipRepository();

        $contexto = null;
        if ($session['tenant_id'] !== null) {
            $roles = $memberships->activeRoles((int) $user['id'], (int) $session['tenant_id']);
            $tenant = (new TenantRepository())->findById((int) $session['tenant_id']);
            if ($roles !== [] && $tenant !== null) {
                $contexto = [
                    'tenant' => ['id' => (int) $tenant['id'], 'slug' => $tenant['slug'], 'nombre_comercial' => $tenant['nombre_comercial'], 'estado' => $tenant['estado']],
                    'roles' => $roles,
                    'permisos' => Gate::rolePermissions($roles),
                ];
            }
        }
        $rolPlataforma = $memberships->roleCode($user['rol_plataforma_id'] !== null ? (int) $user['rol_plataforma_id'] : null);

        return $this->ok([
            'usuario' => UserRepository::publicProfile($user),
            'es_cliente' => (new UserRepository())->isCliente((int) $user['id']),
            'plataforma' => $rolPlataforma === null ? null : ['rol' => $rolPlataforma, 'permisos' => Gate::platformPermissions($user)],
            'sesion' => [
                'id' => $session['id'],
                'canal' => $session['canal'],
                'expira_en' => str_replace(' ', 'T', (string) $session['expira_en']) . 'Z',
            ],
            'contexto' => $contexto,
        ]);
    }

    public function memberships(Request $request): Response
    {
        $user = (array) $request->attribute('auth_user');
        return $this->ok((new MembershipRepository())->forUser((int) $user['id']));
    }

    public function setContext(Request $request): Response
    {
        $data = $this->validate($request, ['tenant' => 'nullable|slug|max:80']);
        $user = (array) $request->attribute('auth_user');
        $session = (array) $request->attribute('auth_session');
        $sessions = new SessionRepository();

        if (($data['tenant'] ?? null) === null) {
            $sessions->setTenant((int) $session['id'], null);
            return $this->ok(['contexto' => null]);
        }
        $tenant = (new TenantRepository())->findBySlug((string) $data['tenant']);
        $roles = $tenant ? (new MembershipRepository())->activeRoles((int) $user['id'], (int) $tenant['id']) : [];
        if ($tenant === null || $roles === []) {
            Audit::denied('authz.contexto_denegado', $request, ['tenant' => $data['tenant']]);
            throw new NotFoundException('Barbería no encontrada.');
        }
        $sessions->setTenant((int) $session['id'], (int) $tenant['id']);
        Audit::record('auth.contexto_seleccionado', 'exito', $request, (int) $user['id'], (int) $tenant['id'], 'sesiones', (int) $session['id']);
        return $this->ok(['contexto' => [
            'tenant' => ['id' => (int) $tenant['id'], 'slug' => $tenant['slug'], 'nombre_comercial' => $tenant['nombre_comercial'], 'estado' => $tenant['estado']],
            'roles' => $roles,
            'permisos' => Gate::rolePermissions($roles),
        ]]);
    }

    public function acceptInvitation(Request $request): Response
    {
        return $this->answerInvitation($request, 'activo');
    }

    public function rejectInvitation(Request $request): Response
    {
        return $this->answerInvitation($request, 'revocado');
    }

    private function answerInvitation(Request $request, string $estado): Response
    {
        $user = (array) $request->attribute('auth_user');
        $id = (int) $request->param('id');
        $memberships = new MembershipRepository();
        $row = Database::transaction(static function () use ($memberships, $user, $id, $estado): ?array {
            $row = $memberships->findForUser((int) $user['id'], $id);
            if ($row === null || $row['estado'] !== 'invitado') {
                return null;
            }
            $memberships->setStatus($id, $estado);
            return $row;
        });
        if ($row === null) {
            throw new NotFoundException('Invitación no encontrada o ya respondida.');
        }
        Audit::record($estado === 'activo' ? 'personal.invitacion_aceptada' : 'personal.invitacion_rechazada', 'exito', $request, (int) $user['id'], (int) $row['tenant_id'], 'membresias', $id);
        return $this->ok(['membresia_id' => $id, 'estado' => $estado, 'rol' => $row['rol']]);
    }

    public function sessions(Request $request): Response
    {
        $user = (array) $request->attribute('auth_user');
        $current = (int) ((array) $request->attribute('auth_session'))['id'];
        $rows = (new SessionRepository())->listActiveForUser((int) $user['id']);
        foreach ($rows as &$row) {
            $row['actual'] = $row['id'] === $current;
        }
        return $this->ok($rows);
    }

    public function revokeSession(Request $request): Response
    {
        $user = (array) $request->attribute('auth_user');
        $id = (int) $request->param('id');
        $sessions = new SessionRepository();
        $owned = array_filter($sessions->listActiveForUser((int) $user['id']), static fn (array $s): bool => $s['id'] === $id);
        if ($owned === []) {
            throw new NotFoundException('Sesión no encontrada.');
        }
        if ($id === (int) ((array) $request->attribute('auth_session'))['id']) {
            throw new HttpException(422, 'use_logout', 'Para cerrar la sesión actual usa "Cerrar sesión".');
        }
        $sessions->revoke($id, 'usuario');
        Audit::record('auth.sesion_revocada', 'exito', $request, (int) $user['id'], null, 'sesiones', $id);
        return $this->noContent();
    }
}
