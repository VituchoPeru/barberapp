<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Core\Exceptions\ValidationException;
use DateTimeImmutable;

/**
 * Validador declarativo del lado del servidor.
 *
 *   $data = Validator::validate($input, [
 *       'nombre'           => 'required|string|max:120',
 *       'duracion_minutos' => 'required|integer|min:1|max:600',
 *       'precio'           => 'required|decimal|min:0',
 *       'email'            => 'nullable|email',
 *   ]);
 *
 * Devuelve solo los campos declarados (lista blanca), con strings recortados.
 */
final class Validator
{
    /**
     * @param array<string, mixed>  $input
     * @param array<string, string> $rules
     * @return array<string, mixed>
     */
    public static function validate(array $input, array $rules): array
    {
        $errors = [];
        $clean = [];

        foreach ($rules as $field => $ruleString) {
            $fieldRules = explode('|', $ruleString);
            $present = array_key_exists($field, $input);
            $value = $present ? $input[$field] : null;
            if (is_string($value)) {
                $value = trim($value);
            }

            $nullable = in_array('nullable', $fieldRules, true);
            $required = in_array('required', $fieldRules, true);

            if ($value === null || $value === '') {
                if ($required) {
                    $errors[$field][] = 'Este campo es obligatorio.';
                } elseif ($present && $nullable) {
                    $clean[$field] = null;
                }
                continue;
            }

            foreach ($fieldRules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $message = self::check($name, $arg, $value);
                if ($message !== null) {
                    $errors[$field][] = $message;
                    break;
                }
            }

            if (!isset($errors[$field])) {
                $clean[$field] = self::cast($fieldRules, $value);
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $clean;
    }

    /** @param mixed $value */
    private static function check(string $rule, ?string $arg, $value): ?string
    {
        switch ($rule) {
            case 'required':
            case 'nullable':
                return null;
            case 'string':
                return is_string($value) ? null : 'Debe ser un texto.';
            case 'integer':
                return filter_var($value, FILTER_VALIDATE_INT) !== false && !is_bool($value) ? null : 'Debe ser un número entero.';
            case 'decimal':
                return (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?\d{1,8}(\.\d{1,2})?$/', $value)))
                    ? null : 'Debe ser un importe válido (máximo 2 decimales).';
            case 'boolean':
                return is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true) ? null : 'Debe ser verdadero o falso.';
            case 'email':
                return is_string($value) && strlen($value) <= 190 && filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Correo electrónico inválido.';
            case 'slug':
                return is_string($value) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) ? null : 'Solo minúsculas, números y guiones.';
            case 'date':
                return is_string($value) && self::isDate($value, 'Y-m-d') ? null : 'Fecha inválida (AAAA-MM-DD).';
            case 'time':
                return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? null : 'Hora inválida (HH:MM).';
            case 'in':
                return in_array((string) $value, explode(',', (string) $arg), true) ? null : 'Valor no permitido.';
            case 'regex':
                return is_string($value) && preg_match((string) $arg, $value) ? null : 'Formato inválido.';
            case 'min':
                if (is_string($value) && !is_numeric($value)) {
                    return mb_strlen($value) >= (int) $arg ? null : "Debe tener al menos {$arg} caracteres.";
                }
                return is_numeric($value) && (float) $value >= (float) $arg ? null : "Debe ser mayor o igual a {$arg}.";
            case 'max':
                if (is_string($value) && !is_numeric($value)) {
                    return mb_strlen($value) <= (int) $arg ? null : "Debe tener como máximo {$arg} caracteres.";
                }
                return is_numeric($value) && (float) $value <= (float) $arg ? null : "Debe ser menor o igual a {$arg}.";
            case 'array':
                return is_array($value) ? null : 'Debe ser una lista.';
            default:
                return "Regla desconocida: {$rule}";
        }
    }

    /**
     * @param list<string> $rules
     * @param mixed        $value
     * @return mixed
     */
    private static function cast(array $rules, $value)
    {
        if (in_array('integer', $rules, true)) {
            return (int) $value;
        }
        if (in_array('decimal', $rules, true)) {
            // Se mantiene como string con 2 decimales para no perder precisión al guardar en DECIMAL.
            return number_format((float) $value, 2, '.', '');
        }
        if (in_array('boolean', $rules, true)) {
            return in_array($value, [true, 1, '1', 'true'], true);
        }
        return $value;
    }

    private static function isDate(string $value, string $format): bool
    {
        $d = DateTimeImmutable::createFromFormat('!' . $format, $value);
        return $d !== false && $d->format($format) === $value;
    }
}
