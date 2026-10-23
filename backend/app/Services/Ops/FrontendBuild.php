<?php

declare(strict_types=1);

namespace App\Services\Ops;

use RuntimeException;

/**
 * Prepara frontend/www para un dominio de producción (Fase 15): php bin/console despliegue:frontend
 *
 *  - js/site-config.js: la URL de la API. En XAMPP queda vacía y el frontend la calcula a partir de
 *    su propia ubicación (backend/public dentro del mismo proyecto); en un servidor la API está en
 *    otro dominio (api.barberapp.pe) y hay que fijarla.
 *  - sw.js: agrega el identificador de la versión desplegada a VERSION, para que el navegador
 *    instale el service worker nuevo y deje de usar los archivos de la versión anterior.
 */
final class FrontendBuild
{
    /** @return array{api: string, version: string} */
    public static function apply(string $wwwDir, string $apiUrl, string $release): array
    {
        $apiUrl = rtrim($apiUrl, '/');
        if (!preg_match('#^https?://[^\s"\'\\\\<>]+$#', $apiUrl)) {
            throw new RuntimeException("URL de la API inválida: {$apiUrl}");
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $release)) {
            throw new RuntimeException('El identificador de la versión solo admite letras, números, punto, guion y guion bajo.');
        }
        $site = $wwwDir . '/js/site-config.js';
        $sw = $wwwDir . '/sw.js';
        if (!is_file($site) || !is_file($sw)) {
            throw new RuntimeException("{$wwwDir} no parece el frontend de BARBERAPP (faltan js/site-config.js o sw.js).");
        }
        file_put_contents($site, self::siteConfig($apiUrl));

        $code = (string) file_get_contents($sw);
        if (!preg_match("/^const VERSION = '([^'+]+)(?:\\+[^']*)?';$/m", $code, $m)) {
            throw new RuntimeException('No se encontró la línea VERSION en sw.js.');
        }
        $version = $m[1] . '+' . $release;
        file_put_contents($sw, (string) preg_replace("/^const VERSION = '[^']*';$/m", "const VERSION = '{$version}';", $code, 1));
        return ['api' => $apiUrl, 'version' => $version];
    }

    public static function siteConfig(string $apiUrl): string
    {
        return "// Configuración del sitio web. La escribe `php bin/console despliegue:frontend` en cada despliegue\n"
            . "// (Fase 15). Vacía = la API está en el mismo proyecto (XAMPP). La app nativa usa runtime-config.js.\n"
            . "export default {\n  apiUrl: " . json_encode($apiUrl, JSON_UNESCAPED_SLASHES) . ",\n};\n";
    }
}
