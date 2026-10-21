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
 * Excepción: subida de imágenes (Content-Type image/jpeg|png|webp), con su propio límite;
 * el controlador valida el contenido real del archivo.
 */
final class JsonBodyMiddleware implements MiddlewareInterface
{
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $type = strtolower(trim(explode(';', (string) $request->header('content-type', ''))[0]));
            $isImage = in_array($type, self::IMAGE_TYPES, true);
            $max = $isImage
                ? (int) Config::get('media.max_upload_bytes', 5242880)
                : (int) Config::get('app.max_body_bytes', 1048576);
            if (strlen($request->rawBody()) > $max) {
                throw new HttpException(413, 'payload_too_large', 'El cuerpo de la petición excede el tamaño permitido.');
            }
            if (!$isImage) {
                $request->json();
            }
        }
        return $next($request);
    }
}
