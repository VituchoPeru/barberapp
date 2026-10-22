<?php

declare(strict_types=1);

namespace App\Services\Notifications\Push;

/** Resultado del envío push a UN dispositivo. */
final class PushResult
{
    public const OK = 'ok';
    /** El proveedor rechazó el token (app desinstalada, token vencido): se desactiva el dispositivo. */
    public const INVALID_TOKEN = 'invalid_token';
    /** Error temporal (red, 429, 5xx, credenciales en revisión): se reintenta con backoff. */
    public const RETRY = 'retry';
    /** Error permanente del mensaje: no se reintenta. */
    public const FAILED = 'failed';

    public function __construct(public string $status, public ?string $code = null, public ?string $detail = null)
    {
    }

    public static function ok(?string $code = null): self
    {
        return new self(self::OK, $code);
    }
}
