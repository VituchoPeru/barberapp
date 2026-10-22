<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * HTTPS obligatorio (FORCE_HTTPS=true). Una petición por HTTP:
 *   - GET/HEAD → 308 a la misma dirección con https:// (el navegador repite la petición);
 *   - el resto → 403 `https_required`, sin procesarla: los datos ya viajaron sin cifrar y
 *     redirigir un POST con credenciales solo repetiría el problema.
 * Detrás de un balanceador, el esquema real llega en X-Forwarded-Proto desde un proxy de
 * confianza (TRUSTED_PROXIES). La cabecera HSTS la agrega SecurityHeadersMiddleware.
 */
final class HttpsMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if (!Config::get('app.force_https', false) || $request->isSecure()) {
            return $next($request);
        }
        if (in_array($request->method(), ['GET', 'HEAD'], true)) {
            $host = $request->host() ?? (string) parse_url((string) Config::get('app.url'), PHP_URL_HOST);
            if ($host !== '') {
                $response = Response::error(308, 'https_required', 'Usa la dirección segura (https).');
                $response->setHeader('Location', 'https://' . $host . $request->uri());
                return $response;
            }
        }
        return Response::error(403, 'https_required', 'La conexión no es segura. Usa la dirección https:// de BARBERAPP.');
    }
}
