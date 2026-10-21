<?php

declare(strict_types=1);

/*
 * Arranque de pruebas.
 * Las pruebas de integración usan DB_TEST_DATABASE (por defecto barberapp_test), que se
 * BORRA y recrea en cada ejecución con todas las migraciones + catálogo + datos demo.
 * Si MySQL no está disponible, las pruebas de integración se marcan como omitidas.
 */

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\Migrator;
use Database\Seeds\CatalogSeeder;
use Database\Seeds\DemoSeeder;

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';

Env::load($base . '/.env');
Config::loadDirectory($base . '/config');
Config::set('app.env', 'testing');
Config::set('app.log_path', sys_get_temp_dir() . '/barberapp-test-logs');
// Pruebas: correo en memoria y clave de firma propia (nunca la de .env).
Config::set('mail.driver', 'array');
Config::set('auth.key', 'base64:' . base64_encode(str_repeat('k', 32)));
date_default_timezone_set('UTC');

$testDb = (string) (Env::get('DB_TEST_DATABASE') ?: 'barberapp_test');
if (!preg_match('/^[A-Za-z0-9_]+$/', $testDb) || $testDb === Env::get('DB_DATABASE')) {
    fwrite(STDERR, "DB_TEST_DATABASE debe ser un nombre válido y distinto de DB_DATABASE.\n");
    exit(1);
}

define('BARBERAPP_TEST_DB', $testDb);
define('BARBERAPP_BASE', $base);

try {
    $cfg = Config::get('database');
    $server = Database::connect($cfg, false);
    $server->exec("DROP DATABASE IF EXISTS `{$testDb}`");
    $server->exec("CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");

    Config::set('database.database', $testDb);
    $pdo = Database::connect(Config::get('database'));
    Database::setConnection($pdo);

    (new Migrator($pdo, $base . '/database/migrations'))->migrate();
    (new CatalogSeeder($pdo))->run();
    (new DemoSeeder($pdo))->run();
    define('BARBERAPP_DB_AVAILABLE', true);
} catch (Throwable $e) {
    fwrite(STDERR, '[aviso] MySQL no disponible, se omiten pruebas de integración: ' . $e->getMessage() . "\n");
    define('BARBERAPP_DB_AVAILABLE', false);
}
