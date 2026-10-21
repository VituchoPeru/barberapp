<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/** Asigna un identificador único a cada petición (o reutiliza uno válido enviado por el cliente). */
final class RequestIdMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $incoming = (string) $request->header('x-request-id', '');
        $id = preg_match('/^[A-Za-z0-9\-]{8,64}$/', $incoming) ? $incoming : bin2hex(random_bytes(16));
        $request->withAttribute('request_id', $id);
        $request->withAttribute('request_started', microtime(true));
        return $next($request);
    }
}
