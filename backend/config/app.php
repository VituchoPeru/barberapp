<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name' => Env::get('APP_NAME', 'BARBERAPP'),
    'version' => '0.6.0',
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => (bool) Env::get('APP_DEBUG', false),
    'url' => Env::get('APP_URL', 'http://localhost'),
    'frontend_url' => Env::get('FRONTEND_URL', 'http://localhost'),
    // Base del enlace público de reservas: {PUBLIC_BOOKING_URL}/b/{slug}. En producción: https://app.barberapp.pe
    'public_booking_url' => Env::get('PUBLIC_BOOKING_URL') ?: Env::get('FRONTEND_URL', 'http://localhost'),
    'timezone' => 'UTC',
    'default_tenant_timezone' => Env::get('DEFAULT_TENANT_TIMEZONE', 'America/Lima'),
    'force_https' => (bool) Env::get('FORCE_HTTPS', false),
    'log_level' => Env::get('LOG_LEVEL', 'info'),
    'log_path' => dirname(__DIR__) . '/storage/logs',
    'max_body_bytes' => (int) Env::get('MAX_BODY_BYTES', 1048576),
];
