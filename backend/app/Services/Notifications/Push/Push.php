<?php

declare(strict_types=1);

namespace App\Services\Notifications\Push;

use App\Core\Config;
use RuntimeException;

/** Fábrica del transporte push según PUSH_DRIVER (config/notifications.php). */
final class Push
{
    private static ?PushTransport $override = null;

    /** null = push desactivado (PUSH_DRIVER=none). */
    public static function transport(): ?PushTransport
    {
        if (self::$override !== null) {
            return self::$override;
        }
        $driver = (string) Config::get('notifications.push.driver', 'log');
        switch ($driver) {
            case 'fcm':
                return new FcmTransport((array) Config::get('notifications.push.fcm', []));
            case 'log':
                return new LogPushTransport((string) Config::get('notifications.push.log_path'));
            case 'array':
                return new ArrayPushTransport();
            case 'none':
            case '':
                return null;
            default:
                throw new RuntimeException("PUSH_DRIVER desconocido: {$driver}");
        }
    }

    /** Solo pruebas: fija un transporte (p. ej. FcmTransport con HTTP simulado). */
    public static function use(?PushTransport $transport): void
    {
        self::$override = $transport;
    }
}
