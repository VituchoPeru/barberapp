<?php

declare(strict_types=1);

namespace App\Helpers;

/** Tokens aleatorios opacos. En la base solo se guarda su SHA-256. */
final class Tokens
{
    /** 32 bytes aleatorios en base64url (43 caracteres, 256 bits de entropía). */
    public static function random(int $bytes = 32): string
    {
        return self::base64url(random_bytes($bytes));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64urlDecode(string $data): ?string
    {
        if (!preg_match('/^[A-Za-z0-9\-_]*$/', $data)) {
            return null;
        }
        $decoded = base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
        return $decoded === false ? null : $decoded;
    }

    /** Formato aceptado para tokens opacos recibidos del cliente (evita consultas con basura). */
    public static function looksValid(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9\-_]{32,128}$/', $token);
    }
}
