<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Auth\Gate;

/**
 * Exige uno o varios permisos (todos). Debe ir después de AuthMiddleware y, para permisos de
 * barbería, después de TenantContextMiddleware(requireMembership: true).
 *
 *   new PermissionMiddleware('personal.gestionar')
 */
final class PermissionMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $permisos;

    public function __construct(string ...$permisos)
    {
        $this->permisos = array_values($permisos);
    }

    public function handle(Request $request, callable $next): Response
    {
        foreach ($this->permisos as $permiso) {
            if (!Gate::allows($request, $permiso)) {
                Audit::denied('authz.permiso_denegado', $request, ['permiso' => $permiso]);
                throw new ForbiddenException('No tienes permiso para realizar esta acción.');
            }
        }
        return $next($request);
    }
}
