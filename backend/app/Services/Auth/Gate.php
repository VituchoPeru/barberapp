<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Request;
use App\Repositories\MembershipRepository;

/**
 * Autorización por permiso.
 *
 *  - plataforma.*  → solo el rol de plataforma del usuario (superadmin).
 *  - cliente.*     → cualquier cuenta activa (toda persona puede reservar como cliente).
 *  - resto         → roles ACTIVOS del usuario en la barbería del contexto ($request 'membership'),
 *                    resuelta y verificada por TenantContextMiddleware. Sin contexto, se niega.
 */
final class Gate
{
    /** @var array<string, list<string>> caché por proceso */
    private static array $cache = [];

    public static function allows(Request $request, string $permiso): bool
    {
        $user = $request->attribute('auth_user');
        if (!is_array($user)) {
            return false;
        }
        if (str_starts_with($permiso, 'plataforma.')) {
            return in_array($permiso, self::platformPermissions($user), true);
        }
        if (str_starts_with($permiso, 'cliente.')) {
            return true;
        }
        $membership = $request->attribute('membership');
        if (!is_array($membership) || empty($membership['roles'])) {
            return false;
        }
        return in_array($permiso, self::rolePermissions($membership['roles']), true);
    }

    /** @param array<string, mixed> $user @return list<string> */
    public static function platformPermissions(array $user): array
    {
        $rol = $user['rol_plataforma_id'] ?? null;
        if ($rol === null) {
            return [];
        }
        $key = 'plataforma:' . $rol;
        return self::$cache[$key] ??= (new MembershipRepository())->platformPermissions((int) $rol);
    }

    /** @param list<string> $roles @return list<string> */
    public static function rolePermissions(array $roles): array
    {
        sort($roles);
        $key = 'roles:' . implode(',', $roles);
        return self::$cache[$key] ??= (new MembershipRepository())->permissionsForRoles($roles);
    }

    /** Solo para pruebas: los permisos pueden cambiar entre pruebas. */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
