<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Auth\Gate;

/**
 * Puerta del panel SaaS (Fase 9): exige una cuenta con rol de plataforma (superadmin u
 * operador). Va después de AuthMiddleware; cada ruta exige además su permiso plataforma.*.
 * Las cuentas de barberías y clientes reciben 403 y el intento queda en la auditoría.
 */
final class PlatformMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $user = $request->attribute('auth_user');
        if (!is_array($user) || Gate::platformPermissions($user) === []) {
            Audit::denied('authz.plataforma_denegado', $request);
            throw new ForbiddenException('Esta sección es solo para el equipo de BARBERAPP.');
        }
        return $next($request);
    }
}
