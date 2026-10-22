<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Core\Config;
use App\Helpers\Clock;

/**
 * Modo mantenimiento (Fase 15). Mientras exista el archivo ops.maintenance_file la API responde
 * 503 con Retry-After (ver MaintenanceMiddleware). El despliegue lo activa solo mientras aplica
 * migraciones, para que nadie reserve contra un esquema a medio cambiar.
 */
final class Maintenance
{
    public static function enable(string $reason = 'actualizacion', int $retryAfterSeconds = 60): void
    {
        $file = self::file();
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, (string) json_encode([
            'desde' => Clock::nowUtc()->format('Y-m-d\TH:i:s\Z'),
            'motivo' => mb_substr($reason, 0, 120),
            'reintentar' => max(5, $retryAfterSeconds),
        ], JSON_UNESCAPED_UNICODE));
    }

    public static function disable(): bool
    {
        $file = self::file();
        return !is_file($file) || @unlink($file);
    }

    /** @return array{desde: string, motivo: string, reintentar: int}|null */
    public static function status(): ?array
    {
        $file = self::file();
        if (!is_file($file)) {
            return null;
        }
        $j = json_decode((string) @file_get_contents($file), true);
        return [
            'desde' => (string) ($j['desde'] ?? ''),
            'motivo' => (string) ($j['motivo'] ?? 'actualizacion'),
            'reintentar' => (int) ($j['reintentar'] ?? 60),
        ];
    }

    public static function file(): string
    {
        return (string) Config::get('ops.maintenance_file', dirname(__DIR__, 3) . '/storage/cache/mantenimiento.json');
    }
}
