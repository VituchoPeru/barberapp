<?php

declare(strict_types=1);

use App\Controllers\HealthController;
use App\Core\Router;

/*
 * Rutas de la API REST v1.
 * Las fases siguientes agregan sus grupos aquí (auth, marketplace, reservas, panel, plataforma).
 */
return static function (Router $router): void {
    $router->group('/api/v1', static function (Router $r): void {
        $r->get('/health', [HealthController::class, 'show'], [], 'health');
        $r->get('/health/live', [HealthController::class, 'live'], [], 'health.live');
    });
};
