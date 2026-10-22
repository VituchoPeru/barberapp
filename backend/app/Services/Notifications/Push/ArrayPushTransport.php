<?php

declare(strict_types=1);

namespace App\Services\Notifications\Push;

/**
 * Driver de pruebas: guarda los envíos en memoria. $responses permite simular la respuesta
 * del proveedor para un token concreto (token rechazado, error temporal…).
 */
final class ArrayPushTransport implements PushTransport
{
    /** @var list<array{token:string, plataforma:string, message:array<string,mixed>}> */
    public static array $sent = [];
    /** @var array<string, PushResult> token → respuesta simulada */
    public static array $responses = [];

    public function name(): string
    {
        return 'array';
    }

    public function send(string $token, string $plataforma, array $message): PushResult
    {
        $result = self::$responses[$token] ?? PushResult::ok();
        if ($result->status === PushResult::OK) {
            self::$sent[] = ['token' => $token, 'plataforma' => $plataforma, 'message' => $message];
        }
        return $result;
    }

    public static function reset(): void
    {
        self::$sent = [];
        self::$responses = [];
    }
}
