<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Core\Config;

/** Construye URLs públicas de imágenes guardadas por la plataforma. */
final class Media
{
    public const RUTA_REGEX = '#^tenants/[0-9]+/[a-f0-9]{32}\.(webp|jpg|png)$#';

    /**
     * Ruta relativa subida a BARBERAPP → URL bajo MEDIA_URL.
     * URL absoluta https (p. ej. CDN) → se respeta. Cualquier otro valor → null
     * (nunca se devuelve javascript:, data: ni rutas arbitrarias al frontend).
     */
    public static function url(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        if (preg_match(self::RUTA_REGEX, $stored)) {
            return Config::get('media.url') . '/' . $stored;
        }
        if (preg_match('#^https://[^\s"\'<>]+$#i', $stored)) {
            return $stored;
        }
        return null;
    }
}
