<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Core\Config;
use App\Helpers\Clock;

/**
 * Latidos de las tareas programadas (Fase 15). Cada comando de cron, al terminar bien, deja un
 * archivo storage/cache/latidos/{tarea}.json con la hora y un resumen. monitor:revisar avisa si
 * un latido es más antiguo de lo esperado: así se detecta un cron que dejó de correr, aunque no
 * haya dejado ningún error escrito.
 */
final class Heartbeat
{
    /** @param array<string, scalar|null> $data */
    public static function record(string $task, array $data = []): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $file = $dir . '/' . self::fileName($task);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode(['tarea' => $task, 'en' => Clock::nowUtc()->format('Y-m-d\TH:i:s\Z'), 'datos' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($tmp, (string) $json) !== false) {
            @rename($tmp, $file);
        }
    }

    /** @return array{tarea: string, en: string, datos: array<string, mixed>}|null */
    public static function read(string $task): ?array
    {
        $file = self::dir() . '/' . self::fileName($task);
        if (!is_file($file)) {
            return null;
        }
        $j = json_decode((string) @file_get_contents($file), true);
        return is_array($j) && isset($j['en']) ? $j + ['datos' => []] : null;
    }

    /** Minutos desde el último latido (null si nunca corrió). */
    public static function ageMinutes(string $task): ?int
    {
        $hb = self::read($task);
        if ($hb === null) {
            return null;
        }
        $ts = strtotime((string) $hb['en']);
        return $ts === false ? null : max(0, intdiv(Clock::nowUtc()->getTimestamp() - $ts, 60));
    }

    public static function dir(): string
    {
        return rtrim((string) Config::get('ops.heartbeat_path', dirname(__DIR__, 3) . '/storage/cache/latidos'), '/\\');
    }

    private static function fileName(string $task): string
    {
        return (string) preg_replace('/[^a-z0-9_-]+/i', '-', $task) . '.json';
    }
}
