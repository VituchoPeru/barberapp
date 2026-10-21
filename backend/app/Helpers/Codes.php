<?php

declare(strict_types=1);

namespace App\Helpers;

/** Generación de códigos legibles y no secuenciales. */
final class Codes
{
    // Sin caracteres ambiguos (0/O, 1/I/L).
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function random(int $length = 10): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }
        return $code;
    }

    /** Código de comprobante de reserva (columna reservas.codigo, CHAR(10)). */
    public static function reserva(): string
    {
        return self::random(10);
    }
}
