<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * Valida el cuerpo de peticiones de escritura: tamaño máximo y JSON bien formado.
 * El JSON se decodifica una sola vez y queda en caché dentro de Request.
 */
final class JsonBodyMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $max = (int) Config::get('app.max_body_bytes', 1048576);
            if (strlen($request->rawBody()) > $max) {
                throw new HttpException(413, 'payload_too_large', 'El cuerpo de la petición excede el tamaño permitido.');
            }
            $request->json();
        }
        return $next($request);
    }
}
