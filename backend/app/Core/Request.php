<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;

/**
 * Petición HTTP inmutable en sus datos de entrada; los atributos (parámetros de ruta,
 * contexto de tenant, usuario autenticado) se agregan durante el ciclo de vida.
 */
final class Request
{
    /** @var array<string, string> */
    private array $headers;

    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @var array<string, mixed>|null */
    private ?array $json = null;

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     * @param array<string, mixed>  $server
     */
    public function __construct(
        private string $method,
        private string $path,
        private array $query = [],
        array $headers = [],
        private string $body = '',
        private array $server = []
    ) {
        $this->method = strtoupper($method);
        $this->path = self::normalizePath($path);
        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = (string) $value;
        }
    }

    public static function fromGlobals(): self
    {
        $server = $_SERVER;
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');

        // Soporta instalación en subcarpeta (XAMPP: /barberapp/backend/public/...).
        $scriptDir = str_replace('\\', '/', dirname((string) ($server['SCRIPT_NAME'] ?? '')));
        if ($scriptDir !== '/' && $scriptDir !== '.' && $scriptDir !== '' && str_starts_with($path, $scriptDir)) {
            $path = substr($path, strlen($scriptDir)) ?: '/';
        }
        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php')) ?: '/';
        }

        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr((string) $key, 5))] = (string) $value;
            }
        }
        if (isset($server['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $server['CONTENT_TYPE'];
        }
        if (isset($server['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = (string) $server['CONTENT_LENGTH'];
        }

        $method = (string) ($server['REQUEST_METHOD'] ?? 'GET');
        $body = in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)
            ? ''
            : (string) file_get_contents('php://input');

        return new self($method, $path, $_GET, $headers, $body, $server);
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        return preg_replace('#/+#', '/', $path) ?? '/';
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function allQuery(): array
    {
        return $this->query;
    }

    public function rawBody(): string
    {
        return $this->body;
    }

    /**
     * Cuerpo JSON decodificado. Lanza 400 si el JSON es inválido y 415 si el tipo no es JSON.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        if (trim($this->body) === '') {
            return $this->json = [];
        }
        $type = strtolower($this->header('content-type', '') ?? '');
        if (!str_contains($type, 'application/json')) {
            throw new HttpException(415, 'unsupported_media_type', 'El cuerpo debe enviarse como application/json.');
        }
        $data = json_decode($this->body, true, 64);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new HttpException(400, 'invalid_json', 'El cuerpo JSON no es válido.');
        }
        return $this->json = $data;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        return $this->json()[$key] ?? $default;
    }

    public function ip(): string
    {
        // Solo se confía en REMOTE_ADDR. Los proxies de confianza se configurarán en despliegue.
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return substr((string) $this->header('user-agent', ''), 0, 255);
    }

    /** @param mixed $value */
    public function withAttribute(string $key, $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function attribute(string $key, $default = null)
    {
        return $this->attributes[$key] ?? $default;
    }

    public function param(string $key, ?string $default = null): ?string
    {
        $params = $this->attributes['route_params'] ?? [];
        return isset($params[$key]) ? (string) $params[$key] : $default;
    }
}
