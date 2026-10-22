<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;

/**
 * CORS restrictivo: solo los orígenes listados en CORS_ALLOWED_ORIGINS reciben cabeceras.
 * Responde directamente las peticiones de verificación previa (OPTIONS).
 *
 * Fase 13: una petición que modifica datos con una cabecera Origin que no es de BARBERAPP
 * (lista CORS, FRONTEND_URL, APP_URL o el mismo host de la petición) se rechaza con 403 antes de llegar al controlador.
 * Es una segunda barrera contra CSRF además del token X-CSRF-Token y SameSite. Las peticiones
 * sin Origin (apps de servidor, webhooks, curl) no se ven afectadas.
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

        if ($origin !== '' && !$allowed && !$request->isSafeMethod() && !$this->isOwnOrigin($origin) && !$this->isSameOrigin($origin, $request)) {
            Logger::warning('Origen no autorizado en petición de escritura', ['origin' => substr($origin, 0, 120), 'ip' => $request->ip(), 'ruta' => $request->path()]);
            return Response::error(403, 'origin_not_allowed', 'Solicitud rechazada: el origen no está autorizado.');
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

    /** Origen del propio frontend o de la API (despliegues en el mismo dominio). */
    private function isOwnOrigin(string $origin): bool
    {
        foreach (['app.frontend_url', 'app.url'] as $key) {
            $p = parse_url((string) Config::get($key, ''));
            if (is_array($p) && isset($p['scheme'], $p['host'])) {
                $own = strtolower($p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''));
                if (strtolower($origin) === $own) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Página servida desde el mismo host y puerto que la API (el navegador envía su Origin). */
    private function isSameOrigin(string $origin, Request $request): bool
    {
        $host = strtolower((string) $request->header('host', ''));
        if (!preg_match('/^[a-z0-9.-]{1,253}(:\d{1,5})?$/', $host)) {
            return false;
        }
        return strtolower($origin) === ($request->isSecure() ? 'https://' : 'http://') . $host;
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
