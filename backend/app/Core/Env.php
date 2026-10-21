<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Cargador mínimo de archivos .env (sin dependencias externas).
 *
 * Reglas:
 * - Las variables definidas en el entorno real del proceso tienen prioridad.
 * - Admite comentarios (#), comillas simples/dobles y el prefijo "export".
 * - Los valores true/false/null/empty se convierten a su tipo PHP en get().
 */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        if (!is_readable($file)) {
            throw new RuntimeException("No se puede leer el archivo de entorno: {$file}");
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                throw new RuntimeException(sprintf('Línea %d inválida en %s', $number + 1, basename($file)));
            }
            $key = trim(substr($line, 0, $pos));
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
                throw new RuntimeException(sprintf('Nombre de variable inválido en línea %d: %s', $number + 1, $key));
            }
            $value = self::parseValue(trim(substr($line, $pos + 1)));

            // El entorno real (servidor, contenedor, CI) gana sobre el archivo.
            $real = getenv($key);
            self::$values[$key] = $real !== false ? $real : $value;
        }
    }

    private static function parseValue(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $quote = $raw[0];
        if (($quote === '"' || $quote === "'") && strlen($raw) >= 2) {
            $end = strrpos($raw, $quote);
            if ($end !== false && $end > 0) {
                $inner = substr($raw, 1, $end - 1);
                return $quote === '"' ? stripcslashes($inner) : $inner;
            }
        }
        // Comentario en línea solo si va precedido de espacio: VALOR # comentario
        $hash = strpos($raw, ' #');
        if ($hash !== false) {
            $raw = substr($raw, 0, $hash);
        }
        return trim($raw);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        if (array_key_exists($key, self::$values)) {
            $value = self::$values[$key];
        } else {
            $real = getenv($key);
            if ($real === false) {
                return $default;
            }
            $value = $real;
        }

        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'null':
            case '(null)':
                return null;
            case 'empty':
            case '(empty)':
                return '';
        }
        return $value;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new RuntimeException("Falta la variable de entorno obligatoria: {$key}");
        }
        return (string) $value;
    }

    /** Solo para pruebas. */
    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
    }

    /** Solo para pruebas. */
    public static function reset(): void
    {
        self::$values = [];
    }
}
