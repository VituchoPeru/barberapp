<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/** Registro de eventos en archivos JSON por día (storage/logs/app-AAAA-MM-DD.log). */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4];

    /** @param array<string, mixed> $context */
    public static function log(string $level, string $message, array $context = []): void
    {
        $min = (string) Config::get('app.log_level', 'info');
        if ((self::LEVELS[$level] ?? 1) < (self::LEVELS[$min] ?? 1)) {
            return;
        }
        $dir = (string) Config::get('app.log_path', dirname(__DIR__, 2) . '/storage/logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = json_encode([
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'message' => $message,
            'context' => self::sanitize($context),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /** @param array<string, mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    /**
     * Elimina claves sensibles y convierte excepciones a arreglos.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function sanitize(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (preg_match('/pass|secret|token|authorization|cookie/i', (string) $key)) {
                $out[$key] = '[redactado]';
            } elseif ($value instanceof Throwable) {
                $out[$key] = [
                    'class' => get_class($value),
                    'message' => $value->getMessage(),
                    'file' => $value->getFile() . ':' . $value->getLine(),
                    'trace' => array_slice(explode("\n", $value->getTraceAsString()), 0, 15),
                ];
            } elseif (is_array($value)) {
                $out[$key] = self::sanitize($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
