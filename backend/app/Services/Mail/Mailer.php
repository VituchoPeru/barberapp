<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Config;
use App\Core\Logger;
use RuntimeException;
use Throwable;

/**
 * Envío de correo con drivers intercambiables (config/mail.php):
 *   log   → archivo .eml en storage/mail (desarrollo)
 *   smtp  → servidor SMTP real
 *   array → memoria (pruebas)
 */
final class Mailer
{
    /** @var list<MailMessage> */
    private static array $sent = [];

    /** Envía el correo. Devuelve false (y registra el error) si falla: el flujo que lo llama sigue. */
    public static function send(MailMessage $message): bool
    {
        $driver = (string) Config::get('mail.driver', 'log');
        $from = (string) Config::get('mail.from_address', 'no-reply@barberapp.pe');
        $fromName = (string) Config::get('mail.from_name', 'BARBERAPP');
        try {
            switch ($driver) {
                case 'array':
                    self::$sent[] = $message;
                    break;
                case 'log':
                    self::toFile($message, $from, $fromName);
                    break;
                case 'smtp':
                    (new SmtpTransport((array) Config::get('mail.smtp', [])))->send($message, $from, $fromName);
                    break;
                default:
                    throw new RuntimeException("MAIL_DRIVER desconocido: {$driver}");
            }
            Logger::info('Correo enviado', ['driver' => $driver, 'to_domain' => substr((string) strrchr($message->to, '@'), 1), 'subject' => $message->subject]);
            return true;
        } catch (Throwable $e) {
            Logger::error('No se pudo enviar correo', ['driver' => $driver, 'subject' => $message->subject, 'exception' => $e]);
            return false;
        }
    }

    private static function toFile(MailMessage $message, string $from, string $fromName): void
    {
        $dir = (string) Config::get('mail.log_path');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("No se pudo crear {$dir}");
        }
        // Nombre ordenable (fecha + microsegundos) para encontrar fácilmente el último correo.
        $file = sprintf('%s/%s-%s.eml', $dir, (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd-His-u'), bin2hex(random_bytes(3)));
        if (file_put_contents($file, $message->toMime($from, $fromName)) === false) {
            throw new RuntimeException("No se pudo escribir {$file}");
        }
    }

    /** @return list<MailMessage> Solo driver array (pruebas). */
    public static function sent(): array
    {
        return self::$sent;
    }

    public static function reset(): void
    {
        self::$sent = [];
    }
}
