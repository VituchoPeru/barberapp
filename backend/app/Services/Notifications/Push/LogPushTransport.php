<?php

declare(strict_types=1);

namespace App\Services\Notifications\Push;

use RuntimeException;

/**
 * Driver de desarrollo (XAMPP): no envía nada; escribe cada push en
 * storage/push/push-AAAA-MM-DD.log (una línea JSON). El token se guarda abreviado.
 */
final class LogPushTransport implements PushTransport
{
    public function __construct(private string $dir)
    {
    }

    public function name(): string
    {
        return 'log';
    }

    public function send(string $token, string $plataforma, array $message): PushResult
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new RuntimeException("No se pudo crear {$this->dir}");
        }
        $line = json_encode([
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'token' => substr(hash('sha256', $token), 0, 12),
            'plataforma' => $plataforma,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($this->dir . '/push-' . gmdate('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            return new PushResult(PushResult::RETRY, 'log_write_error', "No se pudo escribir en {$this->dir}");
        }
        return PushResult::ok('logged');
    }
}
