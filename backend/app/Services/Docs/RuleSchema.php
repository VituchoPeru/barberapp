<?php

declare(strict_types=1);

namespace App\Services\Docs;

use InvalidArgumentException;

/**
 * Convierte las reglas del Validator ("required|string|max:120") en un esquema JSON Schema
 * (OpenAPI 3.1). Así la documentación de la API usa la misma notación que el código.
 *
 * Un campo se describe con una regla en texto o con un arreglo:
 *   'nombre' => 'required|string|max:120'
 *   'horario' => ['regla' => 'required|array|max:21', 'descripcion' => '…', 'items' => ['dia_semana' => 'required|integer|min:1|max:7']]
 *   'barberia' => ['regla' => 'nullable', 'campos' => ['nombre_comercial' => 'required|string']]
 */
final class RuleSchema
{
    /** Reglas que entiende el Validator (más "object" y "number" para la documentación). */
    public const REGLAS = [
        'required', 'nullable', 'string', 'integer', 'decimal', 'number', 'boolean', 'email', 'slug',
        'date', 'time', 'datetime', 'in', 'regex', 'min', 'max', 'array', 'object',
    ];

    /**
     * @param array<string, string|array<string, mixed>> $fields
     * @return array<string, mixed> esquema de tipo object
     */
    public static function object(array $fields, string $context = ''): array
    {
        $props = [];
        $required = [];
        foreach ($fields as $name => $spec) {
            [$schema, $isRequired] = self::field($spec, $context . '.' . $name);
            $props[$name] = $schema;
            if ($isRequired) {
                $required[] = $name;
            }
        }
        $out = ['type' => 'object', 'properties' => $props === [] ? new \stdClass() : $props];
        if ($required !== []) {
            $out['required'] = $required;
        }
        return $out;
    }

    /**
     * @param string|array<string, mixed> $spec
     * @return array{0: array<string, mixed>, 1: bool} [esquema, obligatorio]
     */
    public static function field($spec, string $context = ''): array
    {
        if (is_string($spec)) {
            $spec = ['regla' => $spec];
        }
        if (!is_array($spec) || !isset($spec['regla']) || !is_string($spec['regla'])) {
            throw new InvalidArgumentException("Campo sin regla en {$context}.");
        }
        $unknown = array_diff(array_keys($spec), ['regla', 'descripcion', 'campos', 'items', 'ejemplo']);
        if ($unknown !== []) {
            throw new InvalidArgumentException("Claves desconocidas en {$context}: " . implode(', ', $unknown));
        }
        $rules = self::parse($spec['regla'], $context);
        $schema = self::fromRules($rules, $context);

        if (isset($spec['campos'])) {
            $schema = array_merge($schema, self::object((array) $spec['campos'], $context));
        }
        if (isset($spec['items'])) {
            $schema['type'] = 'array';
            $schema['items'] = is_array($spec['items']) && !isset($spec['items']['regla'])
                ? self::object($spec['items'], $context . '[]')
                : self::field($spec['items'], $context . '[]')[0];
        }
        if (isset($spec['descripcion'])) {
            $schema['description'] = trim(($schema['description'] ?? '') === '' ? (string) $spec['descripcion'] : $spec['descripcion'] . ' ' . $schema['description']);
        }
        if (array_key_exists('ejemplo', $spec)) {
            $schema['examples'] = [$spec['ejemplo']];
        }
        if (isset($rules['nullable']) && isset($schema['type'])) {
            $schema['type'] = array_values(array_unique(array_merge((array) $schema['type'], ['null'])));
            if (isset($schema['enum'])) {
                $schema['enum'][] = null;
            }
        }
        return [$schema, isset($rules['required'])];
    }

    /** @return array<string, string> regla => argumento (regex se toma completa: puede tener "|") */
    public static function parse(string $rule, string $context = ''): array
    {
        $out = [];
        $rest = $rule;
        while ($rest !== '') {
            if (str_starts_with($rest, 'regex:')) {
                $out['regex'] = substr($rest, 6);
                break;
            }
            $pos = strpos($rest, '|');
            $part = $pos === false ? $rest : substr($rest, 0, $pos);
            $rest = $pos === false ? '' : substr($rest, $pos + 1);
            [$name, $arg] = array_pad(explode(':', $part, 2), 2, null);
            if (!in_array($name, self::REGLAS, true)) {
                throw new InvalidArgumentException("Regla desconocida \"{$name}\" en {$context}.");
            }
            $out[$name] = $arg ?? ''; // '' (no null) para poder usar isset()
        }
        return $out;
    }

    /**
     * @param array<string, ?string> $r
     * @return array<string, mixed>
     */
    private static function fromRules(array $r, string $context): array
    {
        $s = [];
        $type = 'string';
        if (isset($r['integer'])) {
            $type = 'integer';
        } elseif (isset($r['decimal'])) {
            $type = ['number', 'string'];
            $s['description'] = 'Importe con hasta 2 decimales (número o texto, p. ej. "35.00").';
        } elseif (isset($r['number'])) {
            $type = 'number';
        } elseif (isset($r['boolean'])) {
            $type = 'boolean';
        } elseif (isset($r['array'])) {
            $type = 'array';
        } elseif (isset($r['object'])) {
            $type = 'object';
        }
        $s = ['type' => $type] + $s;

        if (isset($r['email'])) {
            $s['format'] = 'email';
            $s['maxLength'] = 190;
        }
        if (isset($r['slug'])) {
            $s['pattern'] = '^[a-z0-9]+(?:-[a-z0-9]+)*$';
        }
        if (isset($r['date'])) {
            $s['format'] = 'date';
        }
        if (isset($r['time'])) {
            $s['pattern'] = '^([01]\\d|2[0-3]):[0-5]\\d$';
            $s['description'] = 'Hora local HH:MM.';
        }
        if (isset($r['datetime'])) {
            $s['format'] = 'date-time';
        }
        if (isset($r['in'])) {
            $s['enum'] = explode(',', (string) $r['in']);
            if ($type === 'integer') {
                $s['enum'] = array_map('intval', $s['enum']);
            }
        }
        if (isset($r['regex'])) {
            $re = (string) $r['regex'];
            $delim = $re[0] ?? '/';
            $end = strrpos($re, $delim);
            if ($end === false || $end === 0) {
                throw new InvalidArgumentException("Expresión regular inválida en {$context}.");
            }
            $flags = substr($re, $end + 1);
            $pattern = substr($re, 1, $end - 1);
            if ($flags === '') {
                $s['pattern'] = $pattern;
            } else {
                // JSON Schema no admite modificadores: se informa el patrón en la descripción.
                $s['description'] = trim(($s['description'] ?? '') . " Formato (sin distinguir mayúsculas): {$pattern}");
            }
        }
        foreach (['min' => ['minLength', 'minimum', 'minItems'], 'max' => ['maxLength', 'maximum', 'maxItems']] as $rule => [$len, $num, $items]) {
            if (!isset($r[$rule])) {
                continue;
            }
            $v = (string) $r[$rule];
            if ($type === 'string') {
                $s[$len] = (int) $v;
            } elseif ($type === 'array') {
                $s[$items] = (int) $v;
            } else {
                $s[$num] = str_contains($v, '.') ? (float) $v : (int) $v;
            }
        }
        return $s;
    }
}
