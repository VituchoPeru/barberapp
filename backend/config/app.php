<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name' => Env::get('APP_NAME', 'BARBERAPP'),
    'version' => '1.0.0-rc.1',
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => (bool) Env::get('APP_DEBUG', false),
    'url' => Env::get('APP_URL', 'http://localhost'),
    'frontend_url' => Env::get('FRONTEND_URL', 'http://localhost'),
    // Fase 15: dominios propios del panel de barberías y del panel de la plataforma (mismo frontend,
    // otra página de inicio). Vacíos = se entra por FRONTEND_URL. Ver docs/DESPLIEGUE.md.
    'admin_url' => (string) Env::get('ADMIN_URL', ''),
    'platform_url' => (string) Env::get('PLATFORM_URL', ''),
    // Base del enlace público de reservas: {PUBLIC_BOOKING_URL}/b/{slug}. En producción: https://app.barberapp.pe
    'public_booking_url' => Env::get('PUBLIC_BOOKING_URL') ?: Env::get('FRONTEND_URL', 'http://localhost'),
    'timezone' => 'UTC',
    'default_tenant_timezone' => Env::get('DEFAULT_TENANT_TIMEZONE', 'America/Lima'),
    'force_https' => (bool) Env::get('FORCE_HTTPS', false),
    'log_level' => Env::get('LOG_LEVEL', 'info'),
    // LOG_PATH vacío = backend/storage/logs. En un VPS puede ser /var/log/barberapp (ver docs/DESPLIEGUE.md).
    'log_path' => (string) (Env::get('LOG_PATH') ?: dirname(__DIR__) . '/storage/logs'),
    'max_body_bytes' => (int) Env::get('MAX_BODY_BYTES', 1048576),
];
