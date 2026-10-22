<?php

declare(strict_types=1);

namespace App\Services\Notifications\Push;

/**
 * Envío push a un dispositivo.
 *
 * $message = ['title' => string, 'body' => string, 'data' => array<string, string>]
 */
interface PushTransport
{
    /** Nombre corto del proveedor para el registro de envíos (fcm, log, array). */
    public function name(): string;

    /** @param array{title:string, body:string, data:array<string,string>} $message */
    public function send(string $token, string $plataforma, array $message): PushResult;
}
