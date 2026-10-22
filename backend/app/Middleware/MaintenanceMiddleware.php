<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\Ops\Maintenance;

/**
 * Durante el mantenimiento (Fase 15) la API responde 503 `maintenance` con Retry-After, salvo
 * /health/live, para que un balanceador no saque el servidor. Los webhooks de pago también
 * reciben 503: Mercado Pago reintenta las notificaciones que no responden 2xx, y así ningún pago
 * se aplica contra un esquema a medio migrar. El frontend muestra el mensaje y nada se confirma
 * sin respuesta del servidor.
 */
final class MaintenanceMiddleware implements MiddlewareInterface
{
    private const ALLOWED = ['/api/v1/health/live'];

    public function handle(Request $request, callable $next): Response
    {
        $status = Maintenance::status();
        if ($status === null || in_array($request->path(), self::ALLOWED, true) || $request->method() === 'OPTIONS') {
            return $next($request);
        }
        $response = Response::error(503, 'maintenance', 'BARBERAPP se está actualizando. Vuelve a intentarlo en un momento.', ['reintentar_en_segundos' => $status['reintentar']]);
        $response->setHeader('Retry-After', (string) $status['reintentar']);
        $response->setHeader('Cache-Control', 'no-store');
        return $response;
    }
}
