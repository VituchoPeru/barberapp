<?php

declare(strict_types=1);

namespace App\Helpers;

/** Conversión de direcciones IP para columnas VARBINARY(16). */
final class Ip
{
    public static function toBinary(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        return $packed === false ? null : $packed;
    }

    public static function fromBinary(?string $binary): ?string
    {
        if ($binary === null || $binary === '') {
            return null;
        }
        $ip = @inet_ntop($binary);
        return $ip === false ? null : $ip;
    }
}
