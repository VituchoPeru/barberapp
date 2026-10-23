<?php

declare(strict_types=1);

/**
 * Router del servidor embebido de PHP para las pruebas E2E (Fase 14). Sirve el repositorio como
 * en XAMPP: la web en /frontend/www y la API en /backend/public/api/v1, con las mismas cabeceras
 * de seguridad (CSP incluida) que frontend/www/.htaccess. Solo para pruebas locales y CI:
 *
 *   php -S 127.0.0.1:8090 -t . frontend/e2e/router.php      (desde la raíz del repositorio)
 */

$root = dirname(__DIR__, 2);
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// API: un solo punto de entrada, como el DocumentRoot de backend/public.
if (str_starts_with($path, '/backend/public/api/')) {
    $_SERVER['SCRIPT_NAME'] = '/backend/public/index.php';
    require $root . '/backend/public/index.php';
    return true;
}

// Enlace directo de cada barbería (RewriteRule de .htaccess).
if (preg_match('#^/frontend/www/b/([a-z0-9]+(?:-[a-z0-9]+)*)/?$#i', $path, $m)) {
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    header('Location: /frontend/www/pages/barberia.html?slug=' . strtolower($m[1]) . ($qs !== '' ? '&' . $qs : ''), true, 302);
    return true;
}

// Fuera de la web y de las fotos subidas no se sirve nada (ni .env, ni código del backend).
$publico = str_starts_with($path, '/frontend/www/') || str_starts_with($path, '/backend/public/media/');
$file = realpath($root . $path);
if (!$publico || str_contains($path, '..') || $file === false || !str_starts_with($file, $root)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No encontrado';
    return true;
}
if (is_dir($file)) {
    $file = rtrim($file, '/') . '/index.html';
    if (!is_file($file)) {
        http_response_code(404);
        return true;
    }
}

if (str_starts_with($path, '/frontend/www/')) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https: http://localhost:* http://127.0.0.1:*; font-src 'self'; connect-src 'self' https: http://localhost:* http://127.0.0.1:*; worker-src 'self'; manifest-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-src 'none'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
}
$types = [
    'html' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8',
    'css' => 'text/css; charset=utf-8', 'json' => 'application/json', 'webmanifest' => 'application/manifest+json',
    'svg' => 'image/svg+xml', 'png' => 'image/png', 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'ico' => 'image/x-icon',
    'woff2' => 'font/woff2', 'txt' => 'text/plain; charset=utf-8',
];
header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
if (basename($file) === 'sw.js') {
    header('Service-Worker-Allowed: /frontend/www/');
}
readfile($file);
return true;
