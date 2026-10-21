<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;

/** Reglas de contraseña. Devuelve la lista de errores (vacía si es válida). */
final class PasswordPolicy
{
    /** Contraseñas extremadamente comunes que se rechazan siempre. */
    private const COMMON = [
        '12345678', '123456789', '1234567890', 'password', 'password1', 'contraseña', 'contrasena',
        'qwerty123', 'abc12345', 'barberapp', 'barberapp1', '11111111', '00000000', 'iloveyou', 'admin123',
        'peru1234', 'lima1234', 'password123', 'qwertyui', 'asdfghjk',
    ];

    /** @return list<string> */
    public static function errors(string $password, ?string $email = null): array
    {
        $errors = [];
        $min = (int) Config::get('auth.password_min', 8);
        $maxBytes = (int) Config::get('auth.password_max_bytes', 72);

        if (mb_strlen($password) < $min) {
            $errors[] = "Debe tener al menos {$min} caracteres.";
        }
        if (strlen($password) > $maxBytes) {
            $errors[] = "Debe tener como máximo {$maxBytes} bytes.";
        }
        if (!preg_match('/\pL/u', $password) || !preg_match('/\d/', $password)) {
            $errors[] = 'Debe combinar letras y números.';
        }
        $lower = mb_strtolower($password);
        if (in_array($lower, self::COMMON, true)) {
            $errors[] = 'Esta contraseña es demasiado común.';
        }
        if ($email !== null && $email !== '') {
            $local = mb_strtolower((string) strstr($email, '@', true));
            if ($lower === mb_strtolower($email) || ($local !== '' && strlen($local) >= 4 && str_contains($lower, $local))) {
                $errors[] = 'No debe contener tu correo.';
            }
        }
        return $errors;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
