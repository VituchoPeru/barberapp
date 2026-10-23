<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use App\Services\Security\SecurityCheck;
use Throwable;

/**
 * Revisión previa al despliegue (Fase 15): php bin/console despliegue:verificar
 *
 * La ejecuta desplegar.sh sobre la versión nueva ANTES de cambiar el enlace "current": si algo
 * crítico falla (PHP, extensiones, permisos, base, configuración de seguridad), la versión no
 * se publica y la anterior sigue atendiendo. Termina con código 1 si hay errores.
 */
final class Preflight
{
    /** @return list<array{nivel: string, tema: string, mensaje: string}> */
    public static function run(string $basePath, bool $checkDns = true): array
    {
        $prod = !in_array((string) Config::get('app.env'), ['local', 'testing'], true);
        $r = [];
        $add = static function (string $nivel, string $tema, string $mensaje) use (&$r): void {
            $r[] = ['nivel' => $nivel, 'tema' => $tema, 'mensaje' => $mensaje];
        };

        // PHP y extensiones
        $add(PHP_VERSION_ID >= 80000 ? 'ok' : 'error', 'PHP', 'PHP ' . PHP_VERSION . (PHP_VERSION_ID >= 80000 ? '.' : ': se requiere 8.0 o superior.'));
        if (PHP_VERSION_ID < 80100 && $prod) {
            $add('aviso', 'PHP', 'PHP 8.0 ya no recibe parches de seguridad: en producción usa una versión con soporte (8.3 o superior).');
        }
        foreach (['pdo_mysql' => true, 'mbstring' => true, 'json' => true, 'openssl' => true, 'zlib' => true, 'gd' => false, 'curl' => false, 'simplexml' => false] as $ext => $required) {
            if (!extension_loaded($ext)) {
                $why = ['zlib' => 'copias de seguridad', 'openssl' => 'cifrado de copias y FCM', 'gd' => 'fotos de las barberías', 'curl' => 'subida de copias grandes por partes', 'simplexml' => 'copias fuera del servidor'][$ext] ?? 'la API';
                $add($required ? 'error' : 'aviso', "Extensión {$ext}", "Falta la extensión {$ext} ({$why}).");
            }
        }
        $add('ok', 'Extensiones', 'pdo_mysql, mbstring, json, openssl y zlib disponibles.');

        // Carpetas con escritura
        foreach (['storage/logs' => Config::get('app.log_path'), 'storage/cache' => $basePath . '/storage/cache', 'copias' => Config::get('backup.path'), 'fotos' => Config::get('media.path')] as $label => $dir) {
            $dir = (string) $dir;
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $add(is_dir($dir) && is_writable($dir) ? 'ok' : 'error', "Carpeta {$label}", is_dir($dir) && is_writable($dir) ? "{$dir} con permiso de escritura." : "{$dir} no existe o no tiene permiso de escritura para " . self::user() . '.');
        }
        if (is_file($basePath . '/public/.env') || is_file(dirname($basePath) . '/frontend/www/.env')) {
            $add('error', '.env público', 'Hay un .env dentro de una carpeta pública: bórralo.');
        }
        $add(is_file($basePath . '/.env') ? 'ok' : ($prod ? 'error' : 'aviso'), '.env', is_file($basePath . '/.env') ? 'backend/.env presente.' : 'Falta backend/.env.');

        // Base de datos
        try {
            $db = Database::connection();
            $version = (string) $db->query('SELECT VERSION()')->fetchColumn();
            $mysql8 = stripos($version, 'mariadb') === false && version_compare((string) preg_replace('/[^0-9.].*$/', '', $version), '8.0.16', '>=');
            $add($mysql8 ? 'ok' : 'error', 'MySQL', $mysql8 ? "MySQL {$version}." : "{$version}: se requiere MySQL 8.0.16 o superior (no MariaDB).");
            $pending = array_filter((new Migrator($db, $basePath . '/database/migrations'))->status(), static fn (array $m): bool => $m['estado'] === 'pendiente');
            $add('ok', 'Migraciones', $pending === [] ? 'Sin migraciones pendientes.' : count($pending) . ' migración(es) por aplicar en este despliegue: ' . implode(', ', array_column($pending, 'migracion')) . '.');
        } catch (Throwable $e) {
            $add('error', 'MySQL', 'Sin conexión: ' . mb_substr($e->getMessage(), 0, 200));
        }

        // URLs, dominios y CORS
        $origins = (array) Config::get('cors.allowed_origins', []);
        foreach (['FRONTEND_URL' => 'app.frontend_url', 'ADMIN_URL' => 'app.admin_url', 'PLATFORM_URL' => 'app.platform_url'] as $env => $key) {
            $url = (string) Config::get($key, '');
            if ($url === '') {
                continue;
            }
            $p = parse_url($url);
            $origin = ($p['scheme'] ?? '') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
            if (in_array($origin, $origins, true)) {
                $add('ok', "CORS {$env}", "{$origin} puede usar la API.");
            } else {
                $add($prod ? 'error' : 'aviso', "CORS {$env}", "{$origin} no está en CORS_ALLOWED_ORIGINS: el navegador no podrá usar la API desde ese dominio.");
            }
        }
        if ($prod) {
            foreach (['capacitor://localhost', 'https://localhost'] as $app) {
                if (!in_array($app, $origins, true)) {
                    $add('aviso', 'CORS app', "{$app} no está en CORS_ALLOWED_ORIGINS: la app " . ($app === 'capacitor://localhost' ? 'de iOS' : 'de Android') . ' no podrá usar la API.');
                }
            }
        }
        if ($checkDns && $prod) {
            foreach (['APP_URL' => 'app.url', 'FRONTEND_URL' => 'app.frontend_url', 'ADMIN_URL' => 'app.admin_url', 'PLATFORM_URL' => 'app.platform_url'] as $env => $key) {
                $host = (string) parse_url((string) Config::get($key, ''), PHP_URL_HOST);
                if ($host === '') {
                    continue;
                }
                $ips = @gethostbynamel($host) ?: [];
                $add($ips !== [] ? 'ok' : 'aviso', "DNS {$host}", $ips !== [] ? "{$host} apunta a " . implode(', ', $ips) . '.' : "{$host} no resuelve: crea el registro A/AAAA antes de pedir el certificado (docs/DESPLIEGUE.md).");
            }
        }

        // Seguridad (Fase 13): en producción sus errores también detienen el despliegue.
        // Los datos legales del responsable se cargan desde el panel de la plataforma, que solo
        // existe después del primer despliegue: aquí son un aviso (seguridad:revisar sigue exigiéndolos).
        foreach (SecurityCheck::run($basePath) as $item) {
            if ($item['nivel'] !== 'ok') {
                $nivel = $item['tema'] === 'Datos legales' ? 'aviso' : $item['nivel'];
                $r[] = ['nivel' => $nivel, 'tema' => 'Seguridad · ' . $item['tema'], 'mensaje' => $item['mensaje']];
            }
        }
        return $r;
    }

    private static function user(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $u = posix_getpwuid(posix_geteuid());
            return is_array($u) ? (string) $u['name'] : 'el usuario de PHP';
        }
        return 'el usuario de PHP';
    }
}
