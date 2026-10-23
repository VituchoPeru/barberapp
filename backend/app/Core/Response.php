<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Respuesta HTTP. Las respuestas JSON siguen un sobre uniforme:
 *   éxito: {"ok": true, "data": ..., "meta": {...}}
 *   error: {"ok": false, "error": {"code": "...", "message": "...", "details": {...}}, "meta": {...}}
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var array<string, string> nombre => línea Set-Cookie completa */
    private array $cookies = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(private string $body = '', private int $status = 200, array $headers = [])
    {
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
        }
    }

    /**
     * @param mixed                $data
     * @param array<string, mixed> $meta
     */
    public static function success($data = null, int $status = 200, array $meta = []): self
    {
        $payload = ['ok' => true, 'data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        return self::json($payload, $status);
    }

    /**
     * @param array<string, mixed>|null $details
     */
    public static function error(int $status, string $code, string $message, ?array $details = null): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null && $details !== []) {
            $error['details'] = $details;
        }
        return self::json(['ok' => false, 'error' => $error], $status);
    }

    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_HEX_TAG;

    /** @param mixed $payload */
    public static function json($payload, int $status = 200): self
    {
        // JSON_HEX_TAG: < y > salen como \u003C/\u003E. Aunque alguien abra la respuesta como HTML,
        // un texto guardado con etiquetas no se interpreta (defensa adicional a nosniff; Fase 14).
        $body = json_encode($payload, self::JSON_FLAGS | JSON_THROW_ON_ERROR);
        return new self($body, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, mixed>|null */
    public function decoded(): ?array
    {
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : null;
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$this->canonical($name)] = $value;
        return $this;
    }

    public function header(string $name): ?string
    {
        return $this->headers[$this->canonical($name)] ?? null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Agrega una cabecera Set-Cookie.
     *
     * @param array{expires?:int, path?:string, domain?:string, secure?:bool, httponly?:bool, samesite?:string} $options
     */
    public function withCookie(string $name, string $value, array $options = []): self
    {
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $name)) {
            throw new \InvalidArgumentException('Nombre de cookie inválido.');
        }
        $line = $name . '=' . rawurlencode($value);
        if (isset($options['expires'])) {
            $expires = (int) $options['expires'];
            $line .= '; Expires=' . gmdate('D, d M Y H:i:s', $expires) . ' GMT';
            $line .= '; Max-Age=' . max(0, $expires - time());
        }
        $line .= '; Path=' . ($options['path'] ?? '/');
        if (!empty($options['domain'])) {
            $line .= '; Domain=' . $options['domain'];
        }
        if (!empty($options['secure'])) {
            $line .= '; Secure';
        }
        if ($options['httponly'] ?? true) {
            $line .= '; HttpOnly';
        }
        $sameSite = ucfirst(strtolower((string) ($options['samesite'] ?? 'Lax')));
        if (in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            $line .= '; SameSite=' . $sameSite;
        }
        $this->cookies[$name] = $line;
        return $this;
    }

    /** @return array<string, string> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /** Agrega request_id dentro de meta para trazabilidad en respuestas JSON. */
    public function withRequestId(string $requestId): self
    {
        $this->setHeader('X-Request-Id', $requestId);
        $data = $this->decoded();
        if ($data !== null && array_key_exists('ok', $data)) {
            $data['meta'] = array_merge($data['meta'] ?? [], ['request_id' => $requestId]);
            $this->body = (string) json_encode($data, self::JSON_FLAGS);
        }
        return $this;
    }

    public function send(bool $withBody = true): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
            foreach ($this->cookies as $line) {
                header('Set-Cookie: ' . $line, false);
            }
        }
        if ($withBody && $this->status !== 204) {
            echo $this->body;
        }
    }

    private function canonical(string $name): string
    {
        return implode('-', array_map('ucfirst', explode('-', strtolower($name))));
    }
}
