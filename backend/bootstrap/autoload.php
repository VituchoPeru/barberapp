<?php

declare(strict_types=1);

/*
 * Usa el autoloader de Composer si existe; si no (instalación XAMPP sin Composer),
 * registra un autoloader PSR-4 mínimo para el espacio de nombres App\.
 */
$composer = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
    return;
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'App\\' => dirname(__DIR__) . '/app/',
        'Database\\Seeds\\' => dirname(__DIR__) . '/database/seeds/',
    ];
    foreach ($prefixes as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
