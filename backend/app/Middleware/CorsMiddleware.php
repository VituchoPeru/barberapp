<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * CORS restrictivo: solo los orígenes listados en CORS_ALLOWED_ORIGINS reciben cabeceras.
 * Responde directamente las peticiones de verificación previa (OPTIONS).
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $origin = (string) $request->header('origin', '');
        $allowed = $origin !== '' && $this->isAllowed($origin);

        if ($request->method() === 'OPTIONS' && $request->header('access-control-request-method') !== null) {
            $response = new Response('', 204);
            if ($allowed) {
                $this->apply($response, $origin);
                $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
                $response->setHeader(
                    'Access-Control-Allow-Headers',
                    'Content-Type, Authorization, X-Requested-With, X-Request-Id, X-Tenant, X-CSRF-Token, Idempotency-Key'
                );
                $response->setHeader('Access-Control-Max-Age', '600');
            }
            return $response;
        }

        $response = $next($request);
        if ($allowed) {
            $this->apply($response, $origin);
        }
        return $response;
    }

    private function isAllowed(string $origin): bool
    {
        /** @var list<string> $origins */
        $origins = Config::get('cors.allowed_origins', []);
        return in_array($origin, $origins, true);
    }

    private function apply(Response $response, string $origin): void
    {
        $response->setHeader('Access-Control-Allow-Origin', $origin);
        $response->setHeader('Vary', 'Origin');
        $response->setHeader('Access-Control-Expose-Headers', 'X-Request-Id, Content-Disposition, Retry-After');
        if (Config::get('cors.allow_credentials', true)) {
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
        }
    }
}
