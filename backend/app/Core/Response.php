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

    /** @param mixed $payload */
    public static function json($payload, int $status = 200): self
    {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
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

    /** Agrega request_id dentro de meta para trazabilidad en respuestas JSON. */
    public function withRequestId(string $requestId): self
    {
        $this->setHeader('X-Request-Id', $requestId);
        $data = $this->decoded();
        if ($data !== null && array_key_exists('ok', $data)) {
            $data['meta'] = array_merge($data['meta'] ?? [], ['request_id' => $requestId]);
            $this->body = (string) json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            );
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
