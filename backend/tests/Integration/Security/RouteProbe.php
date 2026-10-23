<?php

declare(strict_types=1);

namespace Tests\Integration\Security;

use App\Core\Router;

/**
 * Recorre la tabla de rutas real (routes/api.php) para las pruebas de seguridad de la Fase 14.
 * Así una ruta nueva entra sola en las pruebas de acceso, CSRF e inyección.
 */
final class RouteProbe
{
    /** Rutas sin sesión obligatoria. Cualquier otra debe responder 401 sin sesión. */
    public const PUBLICAS = [
        'health', 'health.live', 'legal', 'webhooks.payments',
        'auth.register', 'auth.login', 'auth.refresh', 'auth.forgot', 'auth.reset', 'auth.verify', 'auth.confirm_email',
        'attendance.show', 'attendance.confirm', // enlace firmado del recordatorio
        'marketplace.filters', 'marketplace.search', 'marketplace.show', 'marketplace.qr', 'bookings.availability',
    ];

    /** @return list<array{method:string, path:string, name:string}> rutas con su ruta de prueba ya resuelta (sin /api/v1) */
    public static function routes(): array
    {
        $router = new Router();
        (require BARBERAPP_BASE . '/routes/api.php')($router);
        $out = [];
        foreach ($router->routes() as $r) {
            $out[] = ['method' => $r['method'], 'path' => self::fill(substr($r['path'], strlen('/api/v1'))), 'name' => (string) $r['name']];
        }
        return $out;
    }

    /** Reemplaza cada {nombre:regex} por un valor válido para su patrón (ids inexistentes a propósito). */
    public static function fill(string $path): string
    {
        $values = ['slug' => 'demo-centro', 'proveedor' => 'mercadopago', 'codigo' => null, 'canal' => 'push'];
        $out = '';
        $len = strlen($path);
        for ($i = 0; $i < $len; $i++) {
            if ($path[$i] !== '{') {
                $out .= $path[$i];
                continue;
            }
            $depth = 0;
            $j = $i;
            for (; $j < $len; $j++) {
                $depth += $path[$j] === '{' ? 1 : ($path[$j] === '}' ? -1 : 0);
                if ($depth === 0) {
                    break;
                }
            }
            $inner = substr($path, $i + 1, $j - $i - 1);
            [$name, $regex] = array_pad(explode(':', $inner, 2), 2, '');
            $value = $values[$name] ?? '999999';
            if ($name === 'codigo') {
                $value = str_contains($regex, 'a-f0-9') ? str_repeat('a', 24) : 'reserva_confirmada';
            }
            $out .= $value;
            $i = $j;
        }
        return $out;
    }

    public static function isWrite(string $method): bool
    {
        return !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
    }
}
