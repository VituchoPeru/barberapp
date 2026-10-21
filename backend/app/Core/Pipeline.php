<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\MiddlewareInterface;
use InvalidArgumentException;

/** Ejecuta una cadena de middleware alrededor de un manejador final. */
final class Pipeline
{
    /**
     * @param list<mixed>                  $middleware Nombres de clase o instancias de MiddlewareInterface
     * @param callable(Request): Response  $core
     */
    public static function run(array $middleware, Request $request, callable $core): Response
    {
        $next = $core;
        foreach (array_reverse($middleware) as $item) {
            $instance = is_string($item) ? new $item() : $item;
            if (!$instance instanceof MiddlewareInterface) {
                throw new InvalidArgumentException('Middleware inválido: ' . (is_string($item) ? $item : get_debug_type($item)));
            }
            $next = static function (Request $req) use ($instance, $next): Response {
                return $instance->handle($req, $next);
            };
        }
        return $next($request);
    }
}
