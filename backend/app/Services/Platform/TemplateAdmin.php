<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Core\Database;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Services\Audit;
use App\Services\Notifications\Templates;

/**
 * Plantillas globales de avisos (Fase 9): las usa toda barbería que no tenga una propia.
 * Solo se editan los avisos que el sistema envía (Templates::DEFAULTS) y en los canales push
 * (también la bandeja de la app) y correo. Las variables se validan: una variable desconocida
 * saldría vacía en el aviso real.
 */
final class TemplateAdmin
{
    public const CANALES = ['push', 'email'];
    private const MAX = ['push' => [80, 400], 'email' => [160, 4000]];

    /** Datos de ejemplo para la vista previa (claramente ficticios). */
    private const EJEMPLO = [
        'barberia' => 'Barbería Ejemplo', 'sucursal' => 'Sede Principal', 'direccion' => 'Av. Ejemplo 123',
        'barbero' => 'Barbero Ejemplo', 'cliente' => 'Cliente Ejemplo', 'fecha' => 'viernes 24 de octubre', 'hora' => '16:30',
        'cuando' => 'mañana a las 16:30', 'servicios' => 'Corte clásico', 'codigo' => 'EJ-1234',
        'accion' => 'Confirma tu asistencia desde la app.', 'motivo' => 'Motivo de ejemplo.',
        'plan' => 'Profesional', 'dias' => '3', 'monto' => 'S/ 79.00',
    ];

    /** @return list<array<string, mixed>> */
    public static function list(): array
    {
        $db = Database::connection();
        $rows = [];
        foreach ($db->query("SELECT id, codigo, canal, asunto, cuerpo, activo, version, updated_at FROM plantillas_notificacion WHERE tenant_id IS NULL AND idioma = 'es-PE'")->fetchAll() as $r) {
            $rows[$r['codigo'] . '|' . $r['canal']] = $r;
        }
        $overrides = [];
        foreach ($db->query('SELECT codigo, canal, COUNT(DISTINCT tenant_id) n FROM plantillas_notificacion WHERE tenant_id IS NOT NULL AND activo = 1 GROUP BY codigo, canal')->fetchAll() as $r) {
            $overrides[$r['codigo'] . '|' . $r['canal']] = (int) $r['n'];
        }
        $out = [];
        foreach (Templates::DEFAULTS as $codigo => $canales) {
            foreach (self::CANALES as $canal) {
                if (!isset($canales[$canal])) {
                    continue;
                }
                $key = "{$codigo}|{$canal}";
                $row = $rows[$key] ?? null;
                [$defAsunto, $defCuerpo] = $canales[$canal];
                $asunto = $row !== null && (string) ($row['asunto'] ?? '') !== '' ? (string) $row['asunto'] : $defAsunto;
                $cuerpo = $row !== null ? (string) $row['cuerpo'] : $defCuerpo;
                $out[] = [
                    'codigo' => $codigo,
                    'nombre' => Templates::NOMBRES[$codigo] ?? $codigo,
                    'grupo' => Templates::isSubscription($codigo) ? 'suscripcion' : (str_starts_with($codigo, 'barberia_') ? 'cuenta' : 'citas'),
                    'canal' => $canal,
                    'asunto' => $asunto,
                    'cuerpo' => $cuerpo,
                    'personalizada' => $row !== null && ($asunto !== $defAsunto || $cuerpo !== $defCuerpo),
                    'version' => $row !== null ? (int) $row['version'] : 0,
                    'actualizada_en' => $row !== null ? str_replace(' ', 'T', (string) $row['updated_at']) . 'Z' : null,
                    'variables' => Templates::variablesFor($codigo),
                    'barberias_con_plantilla_propia' => $overrides[$key] ?? 0,
                    'limites' => ['asunto' => self::MAX[$canal][0], 'cuerpo' => self::MAX[$canal][1]],
                ];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $input asunto, cuerpo
     * @return array<string, mixed>
     */
    public static function save(string $codigo, string $canal, array $input, Request $request): array
    {
        self::assertKnown($codigo, $canal);
        [$asunto, $cuerpo] = self::clean($codigo, $canal, $input);
        $before = Templates::find($codigo, $canal, null);
        self::upsert($codigo, $canal, $asunto, $cuerpo);
        Audit::record('plataforma.plantilla.actualizada', 'exito', $request, null, null, 'plantillas_notificacion', null,
            ['codigo' => $codigo, 'canal' => $canal, 'asunto' => $asunto, 'cuerpo' => $cuerpo], ['asunto' => $before[0], 'cuerpo' => $before[1]]);
        return self::one($codigo, $canal);
    }

    /** Vuelve al texto por defecto del sistema. @return array<string, mixed> */
    public static function restore(string $codigo, string $canal, Request $request): array
    {
        self::assertKnown($codigo, $canal);
        [$asunto, $cuerpo] = Templates::DEFAULTS[$codigo][$canal];
        self::upsert($codigo, $canal, $asunto, $cuerpo);
        Audit::record('plataforma.plantilla.restaurada', 'exito', $request, null, null, 'plantillas_notificacion', null, ['codigo' => $codigo, 'canal' => $canal]);
        return self::one($codigo, $canal);
    }

    /**
     * @param array<string, mixed> $input asunto, cuerpo (sin guardar)
     * @return array{asunto:string, cuerpo:string, ejemplo:bool}
     */
    public static function preview(string $codigo, string $canal, array $input): array
    {
        self::assertKnown($codigo, $canal);
        [$asunto, $cuerpo] = self::clean($codigo, $canal, $input);
        return ['asunto' => Templates::fill($asunto, self::EJEMPLO), 'cuerpo' => Templates::fill($cuerpo, self::EJEMPLO), 'ejemplo' => true];
    }

    // ------------------------------------------------------------------ internos

    private static function assertKnown(string $codigo, string $canal): void
    {
        if (!isset(Templates::DEFAULTS[$codigo][$canal]) || !in_array($canal, self::CANALES, true)) {
            throw new NotFoundException('Plantilla no encontrada.');
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0:string, 1:string}
     */
    private static function clean(string $codigo, string $canal, array $input): array
    {
        $asunto = is_string($input['asunto'] ?? null) ? trim($input['asunto']) : '';
        $cuerpo = is_string($input['cuerpo'] ?? null) ? trim(str_replace("\r\n", "\n", $input['cuerpo'])) : '';
        $errors = [];
        [$maxA, $maxC] = self::MAX[$canal];
        if ($asunto === '' || mb_strlen($asunto) > $maxA) {
            $errors['asunto'][] = "Escribe un título de 1 a {$maxA} caracteres.";
        }
        if ($cuerpo === '' || mb_strlen($cuerpo) > $maxC) {
            $errors['cuerpo'][] = "Escribe un texto de 1 a {$maxC} caracteres.";
        }
        if (preg_match('/[<>]/', $asunto . $cuerpo)) {
            $errors['cuerpo'][] = 'El texto no puede contener < ni >: los avisos son texto plano.';
        }
        $allowed = Templates::variablesFor($codigo);
        foreach (['asunto' => $asunto, 'cuerpo' => $cuerpo] as $field => $text) {
            preg_match_all('/\{\{\s*([^}]*?)\s*\}\}/', $text, $m);
            $unknown = array_values(array_unique(array_diff($m[1], $allowed)));
            if ($unknown !== []) {
                $errors[$field][] = 'Variables no disponibles en este aviso: ' . implode(', ', array_map(static fn (string $v): string => '{{' . $v . '}}', $unknown))
                    . '. Usa: ' . implode(', ', array_map(static fn (string $v): string => '{{' . $v . '}}', $allowed)) . '.';
            }
            if (substr_count($text, '{{') !== substr_count($text, '}}')) {
                $errors[$field][] = 'Hay llaves {{ }} sin cerrar.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [$asunto, $cuerpo];
    }

    private static function upsert(string $codigo, string $canal, string $asunto, string $cuerpo): void
    {
        Database::connection()->prepare(
            "INSERT INTO plantillas_notificacion (tenant_id, codigo, canal, idioma, asunto, cuerpo, variables, activo, version)
             VALUES (NULL, ?, ?, 'es-PE', ?, ?, ?, 1, 1)
             ON DUPLICATE KEY UPDATE version = version + IF(asunto <=> VALUES(asunto) AND cuerpo <=> VALUES(cuerpo) AND activo = 1, 0, 1),
                                     asunto = VALUES(asunto), cuerpo = VALUES(cuerpo), variables = VALUES(variables), activo = 1"
        )->execute([$codigo, $canal, $asunto, $cuerpo, json_encode(Templates::variablesFor($codigo))]);
        Templates::flush();
    }

    /** @return array<string, mixed> */
    private static function one(string $codigo, string $canal): array
    {
        foreach (self::list() as $t) {
            if ($t['codigo'] === $codigo && $t['canal'] === $canal) {
                return $t;
            }
        }
        throw new NotFoundException('Plantilla no encontrada.');
    }
}
