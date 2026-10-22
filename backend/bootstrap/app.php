<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Config;
use App\Core\Env;
use App\Core\Router;
use App\Middleware\CorsMiddleware;
use App\Middleware\HttpsMiddleware;
use App\Middleware\JsonBodyMiddleware;
use App\Middleware\MaintenanceMiddleware;
use App\Middleware\RequestIdMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Middleware\WriteRateLimitMiddleware;

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('BARBERAPP requiere PHP 8.0 o superior.');
}

require __DIR__ . '/autoload.php';

$basePath = dirname(__DIR__);

Env::load($basePath . '/.env');
Config::loadDirectory($basePath . '/config');

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
ini_set('display_errors', Config::get('app.debug') ? '1' : '0');
error_reporting(E_ALL);

$router = new Router();
(require $basePath . '/routes/api.php')($router);

return new Application($router, [
    RequestIdMiddleware::class,
    SecurityHeadersMiddleware::class,
    CorsMiddleware::class,
    HttpsMiddleware::class,
    MaintenanceMiddleware::class,
    JsonBodyMiddleware::class,
    WriteRateLimitMiddleware::class,
]);
