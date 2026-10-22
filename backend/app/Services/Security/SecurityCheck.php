<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Core\Config;
use App\Core\Database;
use App\Services\Backup\BackupCipher;
use App\Services\Privacy\LegalInfo;
use Throwable;

/**
 * Revisión de la configuración de seguridad (php bin/console seguridad:revisar).
 * No cambia nada: lista problemas con su gravedad. En producción, un "error" debe corregirse
 * antes de publicar; el comando termina con código 1 si hay alguno.
 */
final class SecurityCheck
{
    /** @return list<array{nivel: string, tema: string, mensaje: string}> nivel: error | aviso | ok */
    public static function run(string $basePath): array
    {
        $prod = !in_array((string) Config::get('app.env'), ['local', 'testing'], true);
        $r = [];
        $add = static function (bool $ok, string $tema, string $okMsg, string $badMsg, bool $critical = true) use (&$r, $prod): void {
            $r[] = $ok
                ? ['nivel' => 'ok', 'tema' => $tema, 'mensaje' => $okMsg]
                : ['nivel' => $critical && $prod ? 'error' : 'aviso', 'tema' => $tema, 'mensaje' => $badMsg];
        };

        // Secretos
        $key = (string) Config::get('auth.key', '');
        $raw = str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
        $add(strlen($raw) >= 32, 'APP_KEY', 'APP_KEY configurada (32 bytes o más).', 'APP_KEY vacía o corta: ejecuta php bin/console key:generate.');
        $add(!Config::get('app.debug', false), 'APP_DEBUG', 'APP_DEBUG desactivado.', 'APP_DEBUG=true muestra detalles internos en los errores: desactívalo en producción.');
        $env = $basePath . '/.env';
        if (is_file($env) && DIRECTORY_SEPARATOR === '/') {
            $perms = fileperms($env) & 0777;
            $add(($perms & 0007) === 0, '.env', 'backend/.env no es legible por otros usuarios del servidor.', sprintf('backend/.env tiene permisos %o: usa chmod 640 (o 600).', $perms), false);
        }
        $publicEnv = is_file($basePath . '/public/.env');
        $add(!$publicEnv, '.env público', 'No hay .env dentro de public/.', 'Hay un .env dentro de backend/public: bórralo, quedaría descargable.');

        // Transporte
        $add((bool) Config::get('app.force_https', false), 'HTTPS', 'FORCE_HTTPS activo (redirección y HSTS).', 'FORCE_HTTPS=false: la API acepta conexiones sin cifrar.');
        $add((bool) Config::get('auth.cookie.secure', true), 'Cookie de sesión', 'La cookie de sesión solo viaja por HTTPS (Secure).', 'SESSION_SECURE_COOKIE=false: la cookie viajaría por HTTP.');
        $add(str_starts_with((string) Config::get('app.url', ''), 'https://'), 'APP_URL', 'APP_URL usa https.', 'APP_URL no usa https://.');
        $add(str_starts_with((string) Config::get('app.frontend_url', ''), 'https://'), 'FRONTEND_URL', 'FRONTEND_URL usa https.', 'FRONTEND_URL no usa https:// (los enlaces de los correos irían sin cifrar).');

        // CORS
        $origins = (array) Config::get('cors.allowed_origins', []);
        $bad = array_filter($origins, static fn ($o) => $o === '*' || $o === 'null' || (str_starts_with((string) $o, 'http://') && !preg_match('#^http://localhost(:\d+)?$#', (string) $o)));
        $add($bad === [], 'CORS', 'CORS solo permite orígenes https y los de la app.', 'CORS permite orígenes inseguros: ' . implode(', ', $bad) . '.');
        $localhost = array_filter($origins, static fn ($o) => (bool) preg_match('#^http://localhost(:\d+)?$#', (string) $o));
        $add($localhost === [], 'CORS localhost', 'CORS no incluye http://localhost.', 'CORS incluye ' . implode(', ', $localhost) . ' (solo para desarrollo; la app usa https://localhost y capacitor://localhost).', false);

        // Base de datos
        $db = (array) Config::get('database');
        $add(($db['username'] ?? '') !== 'root', 'Usuario MySQL', 'La API no usa el usuario root de MySQL.', 'DB_USERNAME=root: crea un usuario con permisos solo sobre la base de BARBERAPP.');
        $add(($db['password'] ?? '') !== '', 'Contraseña MySQL', 'El usuario de MySQL tiene contraseña.', 'DB_PASSWORD vacía.');

        // Copias
        $bk = (string) Config::get('backup.key', '');
        $validKey = true;
        try {
            if ($bk !== '') {
                BackupCipher::parseKey($bk);
            }
        } catch (Throwable $e) {
            $validKey = false;
        }
        $add($bk !== '' && $validKey, 'BACKUP_KEY', 'Las copias de seguridad se cifran.', 'BACKUP_KEY vacía o inválida: las copias (con datos personales) quedarían sin cifrar. Ejecuta php bin/console backup:clave.');
        $dir = (string) Config::get('backup.path');
        $public = realpath($basePath . '/public');
        $real = realpath($dir) ?: $dir;
        $add($public === false || !str_starts_with($real . '/', $public . '/'), 'BACKUP_PATH', 'Las copias se guardan fuera de public/.', 'BACKUP_PATH está dentro de public/.');

        // Pagos
        if ((string) Config::get('billing.driver', 'none') === 'mercadopago') {
            $add((string) Config::get('billing.mercadopago.webhook_secret', '') !== '', 'Webhook de pagos', 'MP_WEBHOOK_SECRET configurado.', 'MP_WEBHOOK_SECRET vacío: los webhooks de pago no se pueden verificar.');
        }

        // Datos
        try {
            $demo = (int) Database::connection()->query('SELECT COUNT(*) FROM tenants WHERE es_demo = 1')->fetchColumn();
            $add($demo === 0, 'Datos de demostración', 'No hay barberías de demostración.', "Hay {$demo} barbería(s) de demostración (usuarios *@barberapp.test con contraseña conocida).");
            $legal = LegalInfo::public();
            $add($legal['configuracion_completa'], 'Datos legales', 'La política de privacidad tiene los datos del responsable.',
                'Faltan datos del responsable en la política de privacidad (' . implode(', ', $legal['datos_faltantes']) . '): complétalos en Plataforma → Configuración.');
        } catch (Throwable $e) {
            $r[] = ['nivel' => 'aviso', 'tema' => 'Base de datos', 'mensaje' => 'No se pudo revisar la base: ' . $e->getMessage()];
        }

        $add(Config::get('security.trusted_proxies', []) !== [] || !$prod, 'Proxies de confianza', 'TRUSTED_PROXIES configurado (o entorno local).',
            'TRUSTED_PROXIES vacío: si hay un balanceador o Cloudflare delante, configúralo para registrar la IP real y detectar HTTPS.', false);
        return $r;
    }
}
