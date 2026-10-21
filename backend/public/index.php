<?php

declare(strict_types=1);

/**
 * Punto de entrada único de la API. El DocumentRoot del servidor debe apuntar a backend/public,
 * de modo que .env, app/ y database/ queden fuera del directorio público.
 */

use App\Core\ExceptionHandler;
use App\Core\Request;

/** @var \App\Core\Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

ExceptionHandler::registerGlobalHandlers();

$request = Request::fromGlobals();
$response = $app->handle($request);
$response->send($request->method() !== 'HEAD');
