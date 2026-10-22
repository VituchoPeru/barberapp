<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Validator;
use App\Services\Platform\PlatformUserService;

/**
 * Usuarios de plataforma (Fase 9). Permiso plataforma.usuarios.gestionar.
 *
 *   GET  /api/v1/plataforma/usuarios                    equipo de BARBERAPP (superadmin y operador)
 *   GET  /api/v1/plataforma/usuarios/buscar?q=          cuentas por correo o nombre (mín. 3 caracteres, máx. 20)
 *   GET  /api/v1/plataforma/usuarios/{id}
 *   POST /api/v1/plataforma/usuarios/{id}/bloquear      {motivo} — cierra todas sus sesiones
 *   POST /api/v1/plataforma/usuarios/{id}/desbloquear
 *   PUT  /api/v1/plataforma/usuarios/{id}/rol           {rol: superadmin|operador|null}
 */
final class PlatformUsersController extends BaseController
{
    public function team(Request $request): Response
    {
        return $this->ok((new PlatformUserService())->team());
    }

    public function search(Request $request): Response
    {
        return $this->ok((new PlatformUserService())->search((string) $request->query('q', ''), $request));
    }

    public function show(Request $request): Response
    {
        return $this->ok((new PlatformUserService())->show((int) $request->param('id'), $request));
    }

    public function block(Request $request): Response
    {
        $d = Validator::validate($request->json(), ['motivo' => 'required|string|min:5|max:255']);
        return $this->ok((new PlatformUserService())->block((int) $request->param('id'), (string) $d['motivo'], $request));
    }

    public function unblock(Request $request): Response
    {
        return $this->ok((new PlatformUserService())->unblock((int) $request->param('id'), $request));
    }

    public function role(Request $request): Response
    {
        $d = Validator::validate($request->json(), ['rol' => 'nullable|in:superadmin,operador']);
        return $this->ok((new PlatformUserService())->setRole((int) $request->param('id'), $d['rol'] ?? null, $request));
    }
}
