<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/** Cabeceras de seguridad por defecto para respuestas de la API. */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->setHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        // Las respuestas pueden declarar su propia caché (p. ej. imágenes QR públicas).
        if ($response->header('Cache-Control') === null) {
            $response->setHeader('Cache-Control', 'no-store');
        }
        if (Config::get('app.force_https', false)) {
            $response->setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        return $response;
    }
}
