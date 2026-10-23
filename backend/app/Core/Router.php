<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;
use App\Middleware\MiddlewareInterface;
use Closure;
use InvalidArgumentException;

/**
 * Enrutador REST sin dependencias.
 *
 * - Parámetros: /reservas/{id} o con restricción /reservas/{id:\d+}
 * - Grupos con prefijo y middleware: $r->group('/api/v1', fn($r) => ..., [Mw::class])
 * - 404 si la ruta no existe y 405 (con cabecera Allow) si existe con otro método.
 * - HEAD se resuelve con la ruta GET.
 */
final class Router
{
    /** @var list<array{method:string, path:string, regex:string, params:list<string>, handler:mixed, middleware:list<mixed>, name:?string}> */
    private array $routes = [];

    private string $prefix = '';

    /** @var list<mixed> */
    private array $groupMiddleware = [];

    /** @param list<mixed> $middleware */
    public function get(string $path, $handler, array $middleware = [], ?string $name = null): self
    {
        return $this->add('GET', $path, $handler, $middleware, $name);
    }

    /** @param list<mixed> $middleware */
    public function post(string $path, $handler, array $middleware = [], ?string $name = null): self
    {
        return $this->add('POST', $path, $handler, $middleware, $name);
    }

    /** @param list<mixed> $middleware */
    public function put(string $path, $handler, array $middleware = [], ?string $name = null): self
    {
        return $this->add('PUT', $path, $handler, $middleware, $name);
    }

    /** @param list<mixed> $middleware */
    public function patch(string $path, $handler, array $middleware = [], ?string $name = null): self
    {
        return $this->add('PATCH', $path, $handler, $middleware, $name);
    }

    /** @param list<mixed> $middleware */
    public function delete(string $path, $handler, array $middleware = [], ?string $name = null): self
    {
        return $this->add('DELETE', $path, $handler, $middleware, $name);
    }

    /**
     * @param callable(Router): void $callback
     * @param list<mixed>            $middleware
     */
    public function group(string $prefix, callable $callback, array $middleware = []): void
    {
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->prefix = rtrim($previousPrefix . '/' . trim($prefix, '/'), '/');
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);
        try {
            $callback($this);
        } finally {
            $this->prefix = $previousPrefix;
            $this->groupMiddleware = $previousMiddleware;
        }
    }

    /**
     * @param mixed       $handler    [Clase::class, 'metodo'] o callable(Request): Response
     * @param list<mixed> $middleware
     */
    public function add(string $method, string $path, $handler, array $middleware = [], ?string $name = null): self
    {
        $full = Request::normalizePath($this->prefix . '/' . trim($path, '/'));
        [$regex, $params] = $this->compile($full);

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $full,
            'regex' => $regex,
            'params' => $params,
            'handler' => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
            'name' => $name,
        ];
        return $this;
    }

    /** @return array{0:string, 1:list<string>} */
    private function compile(string $path): array
    {
        $params = [];
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^{}]+(?:\{[^{}]*\}[^{}]*)*))?\}#',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                $pattern = isset($m[2]) && $m[2] !== '' ? $m[2] : '[^/]+';
                return '(' . $pattern . ')';
            },
            $path
        );
        if ($regex === null) {
            throw new InvalidArgumentException("Ruta inválida: {$path}");
        }
        return ['#^' . $regex . '$#', $params];
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path(), $matches)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }

            $values = array_slice($matches, 1);
            $params = $route['params'] === [] ? [] : array_combine($route['params'], array_map('rawurldecode', $values));
            $request->withAttribute('route_params', $params);
            $request->withAttribute('route_name', $route['name']);
            $request->withAttribute('route_path', $route['path']);

            $core = function (Request $req) use ($route): Response {
                return $this->invoke($route['handler'], $req);
            };
            return Pipeline::run($route['middleware'], $request, $core);
        }

        if ($allowed !== []) {
            $allowed = array_values(array_unique($allowed));
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }
            throw new HttpException(
                405,
                'method_not_allowed',
                'Método no permitido para este recurso.',
                ['allowed' => $allowed],
                ['Allow' => implode(', ', $allowed)]
            );
        }

        throw new HttpException(404, 'route_not_found', 'La ruta solicitada no existe.');
    }

    /** @param mixed $handler */
    private function invoke($handler, Request $request): Response
    {
        if (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
            [$class, $action] = $handler;
            $controller = new $class();
            if (!is_callable([$controller, $action])) {
                throw new InvalidArgumentException("Acción no encontrada: {$class}::{$action}");
            }
            $result = $controller->{$action}($request);
        } elseif ($handler instanceof Closure || is_callable($handler)) {
            $result = $handler($request);
        } else {
            throw new InvalidArgumentException('Manejador de ruta inválido.');
        }

        if (!$result instanceof Response) {
            throw new InvalidArgumentException('El controlador debe devolver una instancia de Response.');
        }
        return $result;
    }

    /**
     * Rutas con su controlador y middleware (para generar la documentación OpenAPI).
     *
     * @return list<array{method:string, path:string, name:?string, handler:mixed, middleware:list<mixed>}>
     */
    public function definitions(): array
    {
        return array_map(
            static fn (array $r): array => ['method' => $r['method'], 'path' => $r['path'], 'name' => $r['name'], 'handler' => $r['handler'], 'middleware' => $r['middleware']],
            $this->routes
        );
    }

    /** @return list<array{method:string, path:string, name:?string}> */
    public function routes(): array
    {
        return array_map(
            static fn (array $r): array => ['method' => $r['method'], 'path' => $r['path'], 'name' => $r['name']],
            $this->routes
        );
    }
}
