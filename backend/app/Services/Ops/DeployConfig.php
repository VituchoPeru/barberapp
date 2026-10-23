<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Core\Config;
use RuntimeException;

/**
 * Genera la configuración del servidor a partir de las plantillas de infrastructure/ y de
 * backend/.env (Fase 15): php bin/console despliegue:config --salida=DIR [opciones]
 *
 * Los dominios salen de APP_URL (API), FRONTEND_URL (clientes), ADMIN_URL (panel de barberías)
 * y PLATFORM_URL (plataforma); las rutas, de MEDIA_PATH, LOG_PATH y BACKUP_PATH. Así el servidor
 * web, PHP-FPM, el cron y el worker usan siempre los mismos valores que la aplicación.
 * Una plantilla con un {{MARCADOR}} sin valor no se genera: es un error.
 */
final class DeployConfig
{
    public const DEFAULTS = [
        'servidor' => 'nginx',
        'raiz' => '/var/www/barberapp',
        'usuario' => 'barberapp',
        'usuario-web' => 'www-data',
        'php' => '8.3',
        'php-bin' => '',
        'socket' => '/run/php/barberapp.sock',
        'worker' => 'systemd',
        'cert' => 'barberapp',
        'cert-dir' => '',
        'acme' => '/var/www/letsencrypt',
        'snippets' => '/etc/nginx/snippets',
        'ips-plataforma' => '',
        // auto = escuchar también por IPv6 si el servidor lo tiene; si | no para forzarlo.
        'ipv6' => 'auto',
    ];

    /**
     * @param array<string, string> $opts opciones de la consola (ver DEFAULTS)
     * @return array{vars: array<string, string>, sitios: list<array{clave: string, dominio: string, descripcion: string, inicio: string}>, opts: array<string, string>}
     */
    public static function values(array $opts = []): array
    {
        $o = array_merge(self::DEFAULTS, array_filter($opts, static fn ($v) => $v !== '' && $v !== null));
        if (!in_array($o['servidor'], ['nginx', 'apache'], true)) {
            throw new RuntimeException('--servidor debe ser nginx o apache.');
        }
        if (!in_array($o['worker'], ['systemd', 'cron'], true)) {
            throw new RuntimeException('--worker debe ser systemd o cron.');
        }
        if (!preg_match('/^\d+\.\d+$/', $o['php'])) {
            throw new RuntimeException('--php debe ser una versión como 8.3.');
        }
        foreach (['raiz', 'socket', 'acme', 'snippets'] as $k) {
            if (!preg_match('#^/[A-Za-z0-9._/-]+$#', $o[$k])) {
                throw new RuntimeException("--{$k} debe ser una ruta absoluta sin espacios.");
            }
        }
        foreach (['usuario', 'usuario-web', 'cert'] as $k) {
            if (!preg_match('/^[a-z_][a-z0-9_.-]{0,31}$/', $o[$k])) {
                throw new RuntimeException("--{$k} no es un nombre válido.");
            }
        }
        if (!in_array($o['ipv6'], ['auto', 'si', 'no'], true)) {
            throw new RuntimeException('--ipv6 debe ser auto, si o no.');
        }
        $ipv6 = $o['ipv6'] === 'si' || ($o['ipv6'] === 'auto' && trim((string) @file_get_contents('/proc/net/if_inet6')) !== '');
        $raiz = rtrim($o['raiz'], '/');

        $api = self::origin('APP_URL', (string) Config::get('app.url'), true);
        $app = self::origin('FRONTEND_URL', (string) Config::get('app.frontend_url'), true);
        $sitios = [['clave' => 'app', 'dominio' => $app['host'], 'descripcion' => 'clientes y marketplace', 'inicio' => '']];
        foreach ([['admin', 'ADMIN_URL', 'app.admin_url', 'panel de barberías', '/pages/panel/inicio.html'],
                  ['plataforma', 'PLATFORM_URL', 'app.platform_url', 'panel de la plataforma', '/pages/plataforma/index.html']] as [$clave, $env, $cfg, $desc, $inicio]) {
            $url = (string) Config::get($cfg, '');
            if ($url !== '') {
                $sitios[] = ['clave' => $clave, 'dominio' => self::origin($env, $url, true)['host'], 'descripcion' => $desc, 'inicio' => $inicio];
            }
        }
        $dominios = array_merge([$api['host']], array_column($sitios, 'dominio'));
        if (count(array_unique($dominios)) !== count($dominios)) {
            throw new RuntimeException('APP_URL, FRONTEND_URL, ADMIN_URL y PLATFORM_URL deben usar dominios distintos.');
        }

        $vars = [
            'DOMINIO_API' => $api['host'],
            'URL_API' => $api['origin'],
            'DOMINIOS' => implode(' ', $dominios),
            'RAIZ' => $raiz,
            'ACTUAL' => $raiz . '/current',
            'COMPARTIDO' => $raiz . '/shared',
            'MEDIA_DIR' => rtrim((string) Config::get('media.path'), '/'),
            'LOG_DIR' => rtrim((string) Config::get('app.log_path'), '/'),
            'BACKUP_DIR' => rtrim((string) Config::get('backup.path'), '/'),
            'USUARIO' => $o['usuario'],
            'USUARIO_WEB' => $o['usuario-web'],
            'PHP_VERSION' => $o['php'],
            'PHP_BIN' => $o['php-bin'] !== '' ? $o['php-bin'] : '/usr/bin/php' . $o['php'],
            'PHP_FPM_SOCKET' => $o['socket'],
            'CERT_NOMBRE' => $o['cert'],
            'CERT_DIR' => $o['cert-dir'] !== '' ? rtrim($o['cert-dir'], '/') : '/etc/letsencrypt/live/' . $o['cert'],
            'ACME_DIR' => rtrim($o['acme'], '/'),
            'NGINX_SNIPPETS' => rtrim($o['snippets'], '/'),
            'LISTEN_80_IPV6' => $ipv6 ? '    listen [::]:80;' : '',
            'LISTEN_443_IPV6' => $ipv6 ? '    listen [::]:443 ssl http2;' : '',
        ];
        foreach (['MEDIA_DIR', 'LOG_DIR', 'BACKUP_DIR'] as $k) {
            if (!preg_match('#^/[A-Za-z0-9._/-]+$#', $vars[$k])) {
                throw new RuntimeException("La ruta de {$k} ({$vars[$k]}) debe ser absoluta y sin espacios: revisa MEDIA_PATH, LOG_PATH y BACKUP_PATH en .env.");
            }
            if (str_starts_with($vars[$k] . '/', $raiz . '/releases/')) {
                throw new RuntimeException("{$k} ({$vars[$k]}) está dentro de una versión: se perdería en el siguiente despliegue. Usa {$raiz}/shared/… (ver .env.production.example).");
            }
        }
        return ['vars' => $vars, 'sitios' => $sitios, 'opts' => $o];
    }

    /**
     * Escribe los archivos en $outDir. Devuelve las rutas relativas generadas.
     *
     * @param array<string, string> $opts
     * @return list<string>
     */
    public static function generate(string $infraDir, string $outDir, array $opts = []): array
    {
        $v = self::values($opts);
        $vars = $v['vars'];
        $o = $v['opts'];
        $srv = $o['servidor'];
        $files = [];
        $write = static function (string $rel, string $content) use ($outDir, &$files): void {
            $path = $outDir . '/' . $rel;
            if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
                throw new RuntimeException('No se pudo crear ' . dirname($path));
            }
            file_put_contents($path, $content);
            $files[] = $rel;
        };
        $tpl = static fn (string $rel): string => self::read($infraDir . '/' . $rel);

        $ips = array_values(array_filter(array_map('trim', explode(',', $o['ips-plataforma']))));
        foreach ($ips as $ip) {
            if (!preg_match('#^[0-9a-fA-F:.]+(/\d{1,3})?$#', $ip)) {
                throw new RuntimeException("--ips-plataforma: {$ip} no es una IP o red válida.");
            }
        }

        $write("{$srv}/barberapp-api.conf", self::render($tpl("{$srv}/barberapp-api.conf.tpl"), $vars));
        $write("{$srv}/barberapp-acme.conf", self::render($tpl("{$srv}/barberapp-acme.conf.tpl"), $vars));
        foreach ($v['sitios'] as $s) {
            $restricted = $s['clave'] === 'plataforma' && $ips !== [];
            if ($srv === 'nginx') {
                $inicio = $s['inicio'] === '' ? '        try_files /index.html =404;' : '        return 302 ' . $s['inicio'] . ';';
                $acceso = $restricted
                    ? "    # Solo desde estas direcciones (--ips-plataforma).\n" . implode('', array_map(static fn ($ip) => "    allow {$ip};\n", $ips)) . "    deny all;\n"
                    : '';
            } else {
                $inicio = $s['inicio'] === '' ? '' : '    RewriteRule "^/$" ' . $s['inicio'] . ' [R=302,L]';
                $acceso = $restricted ? '        Require ip ' . implode(' ', $ips) : '        Require all granted';
            }
            $write("{$srv}/barberapp-{$s['clave']}.conf", self::render($tpl("{$srv}/barberapp-web.conf.tpl"), $vars + [
                'DOMINIO' => $s['dominio'], 'DESCRIPCION' => $s['descripcion'], 'INICIO' => $inicio, 'ACCESO_RESTRINGIDO' => $acceso,
            ]));
        }
        if ($srv === 'nginx') {
            $write('nginx/snippets/barberapp-ssl.conf', self::render($tpl('nginx/snippets/barberapp-ssl.conf.tpl'), $vars));
            $write('nginx/snippets/barberapp-cabeceras-web.conf', self::render($tpl('nginx/snippets/barberapp-cabeceras-web.conf.tpl'), $vars));
        }
        $write('php/barberapp-fpm.conf', self::render($tpl('php/barberapp-fpm.conf.tpl'), $vars));
        $write('php/99-barberapp.ini', self::render($tpl('php/99-barberapp.ini.tpl'), $vars));
        if ($o['worker'] === 'systemd') {
            $write('systemd/barberapp-worker.service', self::render($tpl('systemd/barberapp-worker.service.tpl'), $vars));
        }
        $worker = $o['worker'] === 'cron'
            ? "# Worker de avisos y recordatorios cada minuto (sin systemd).\n* * * * *     {$vars['USUARIO']} cd {$vars['ACTUAL']}/backend && {$vars['PHP_BIN']} bin/console notificaciones:procesar >> {$vars['LOG_DIR']}/cron.log 2>&1"
            : '# El worker de avisos corre como servicio (barberapp-worker); si se detiene, monitor:revisar avisa.';
        $write('cron/barberapp', self::render($tpl('cron/barberapp.cron.tpl'), $vars + ['LINEA_WORKER' => $worker]));
        $write('logrotate/barberapp', self::render($tpl('logrotate/barberapp.tpl'), $vars));
        $write('dominios.txt', implode("\n", explode(' ', $vars['DOMINIOS'])) . "\n");
        return $files;
    }

    /** @param array<string, string> $vars */
    public static function render(string $template, array $vars): string
    {
        $out = (string) preg_replace_callback('/\{\{([A-Z][A-Z0-9_]*)\}\}/', static function (array $m) use ($vars): string {
            if (!array_key_exists($m[1], $vars)) {
                throw new RuntimeException("La plantilla usa {{{$m[1]}}} y no tiene valor.");
            }
            return $vars[$m[1]];
        }, $template);
        // Líneas que quedaron vacías por un marcador opcional (INICIO, ACCESO_RESTRINGIDO).
        return (string) preg_replace("/\n[ \t]*\n([ \t]*\n)+/", "\n\n", $out);
    }

    /** @return array{origin: string, host: string} */
    private static function origin(string $name, string $url, bool $httpsRequired): array
    {
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host']) || isset($p['user']) || isset($p['query'])) {
            throw new RuntimeException("{$name} ({$url}) no es una URL válida.");
        }
        if ($httpsRequired && $p['scheme'] !== 'https') {
            throw new RuntimeException("{$name} debe usar https:// en un servidor ({$url}).");
        }
        if (isset($p['path']) && rtrim($p['path'], '/') !== '') {
            throw new RuntimeException("{$name} debe ser solo el dominio, sin ruta ({$url}): cada parte tiene su propio dominio.");
        }
        $host = strtolower($p['host']);
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host)) {
            throw new RuntimeException("{$name}: {$host} no es un dominio válido.");
        }
        return ['origin' => 'https://' . $host . (isset($p['port']) ? ':' . $p['port'] : ''), 'host' => $host];
    }

    private static function read(string $file): string
    {
        $c = @file_get_contents($file);
        if ($c === false) {
            throw new RuntimeException("No existe la plantilla {$file}.");
        }
        return $c;
    }
}
