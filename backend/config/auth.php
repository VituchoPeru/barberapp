<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * Autenticación (Fase 2).
 *  - Web: cookie de sesión HttpOnly + Secure + SameSite y token CSRF en cabecera X-CSRF-Token.
 *  - Móvil (Capacitor): access token firmado (HS256) de corta duración + refresh token rotativo.
 */
return [
    // Clave secreta para firmar access tokens y derivar tokens CSRF (php bin/console key:generate).
    'key' => (string) Env::get('APP_KEY', ''),

    'access_ttl' => (int) Env::get('AUTH_ACCESS_TTL', 900),                 // 15 minutos
    'refresh_ttl_days' => (int) Env::get('AUTH_REFRESH_TTL_DAYS', 30),
    'mobile_session_days' => (int) Env::get('AUTH_MOBILE_SESSION_DAYS', 90), // vencimiento absoluto

    'web_session_hours' => (int) Env::get('SESSION_LIFETIME_HOURS', 168),    // 7 días, vencimiento absoluto
    'web_idle_minutes' => (int) Env::get('SESSION_IDLE_MINUTES', 120),       // cierre por inactividad

    'cookie' => [
        'name' => (string) Env::get('SESSION_COOKIE_NAME', 'barberapp_session'),
        'secure' => (bool) Env::get('SESSION_SECURE_COOKIE', true),
        'samesite' => (string) Env::get('SESSION_SAMESITE', 'Lax'),
        'path' => (string) Env::get('SESSION_COOKIE_PATH', '/'),
        'domain' => (string) Env::get('SESSION_COOKIE_DOMAIN', ''),
    ],

    'verify_email_ttl_hours' => (int) Env::get('AUTH_VERIFY_EMAIL_TTL_HOURS', 48),
    'reset_password_ttl_minutes' => (int) Env::get('AUTH_RESET_TTL_MINUTES', 60),

    // Limitación de intentos: [máximo, ventana en segundos]
    'limits' => [
        'login_cuenta' => [5, 900],      // 5 fallos por correo + IP cada 15 min
        'login_ip' => [20, 900],         // 20 fallos por IP cada 15 min
        'registro_ip' => [10, 3600],
        'recuperacion_email' => [3, 3600],
        'recuperacion_ip' => [10, 3600],
        'restablecer_ip' => [10, 900],
        'verificacion_usuario' => [3, 3600],
        'refresh_ip' => [60, 60],
        'asistencia_ip' => [30, 900],    // enlaces "Confirmar asistencia" inválidos por IP cada 15 min (Fase 8)
    ],

    'password_min' => 8,
    // bcrypt solo considera los primeros 72 bytes.
    'password_max_bytes' => 72,

    // Slugs que no puede usar una barbería (rutas y subdominios reservados).
    'reserved_slugs' => ['admin', 'api', 'app', 'b', 'platform', 'plataforma', 'www', 'barberapp', 'soporte', 'ayuda', 'login', 'registro'],
];
