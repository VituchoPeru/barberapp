<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Services\Auth\RateLimiter;
use App\Core\Exceptions\HttpException;

/**
 * Límite general de peticiones que modifican datos por IP (RATE_LIMIT_WRITES por minuto).
 * Complementa los límites específicos de inicio de sesión, recuperación, exportación, etc.
 * Las lecturas no se cuentan (no escriben en la base) y los webhooks de la pasarela tampoco:
 * llegan desde pocas IP y ya se validan por firma.
 */
final class WriteRateLimitMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $limit = (array) Config::get('security.write_limit', [0, 60]);
        if ($request->isSafeMethod() || (int) ($limit[0] ?? 0) <= 0 || str_starts_with($request->path(), '/api/v1/webhooks/')) {
            return $next($request);
        }
        $limiter = new RateLimiter();
        try {
            $limiter->ensureNotLimited('api_escritura_ip', $request->ip());
        } catch (HttpException $e) {
            // Solo al log: auditar cada petición rechazada permitiría llenar la bitácora a propósito.
            Logger::warning('Límite general de escrituras alcanzado', ['ip' => $request->ip(), 'ruta' => $request->method() . ' ' . $request->path()]);
            throw $e;
        }
        $limiter->hit('api_escritura_ip', $request->ip());
        return $next($request);
    }
}
