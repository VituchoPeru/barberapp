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
 *   new PermissionMiddleware('personal.gestionar')                     // exige todos
 *   PermissionMiddleware::any('barberos.gestionar', 'horarios.gestionar') // basta uno
 */
final class PermissionMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $permisos;

    private bool $any = false;

    public function __construct(string ...$permisos)
    {
        $this->permisos = array_values($permisos);
    }

    /** Basta con tener uno de los permisos (p. ej. consultar barberos para asignar horarios). */
    public static function any(string ...$permisos): self
    {
        $m = new self(...$permisos);
        $m->any = true;
        return $m;
    }

    /** @return list<string> permisos exigidos (para la documentación) */
    public function permissions(): array
    {
        return $this->permisos;
    }

    /** true si basta con uno de los permisos; false si se exigen todos. */
    public function anyOf(): bool
    {
        return $this->any;
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($this->any) {
            foreach ($this->permisos as $permiso) {
                if (Gate::allows($request, $permiso)) {
                    return $next($request);
                }
            }
            Audit::denied('authz.permiso_denegado', $request, ['permiso' => implode('|', $this->permisos)]);
            throw new ForbiddenException('No tienes permiso para realizar esta acción.');
        }
        foreach ($this->permisos as $permiso) {
            if (!Gate::allows($request, $permiso)) {
                Audit::denied('authz.permiso_denegado', $request, ['permiso' => $permiso]);
                throw new ForbiddenException('No tienes permiso para realizar esta acción.');
            }
        }
        return $next($request);
    }
}
