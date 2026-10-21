<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * Correo saliente.
 *   MAIL_DRIVER=log   → guarda cada correo en storage/mail/*.eml (desarrollo / XAMPP)
 *   MAIL_DRIVER=smtp  → envía por SMTP (STARTTLS o SSL) con AUTH LOGIN
 *   MAIL_DRIVER=array → en memoria (pruebas automáticas)
 */
return [
    'driver' => (string) Env::get('MAIL_DRIVER', 'log'),
    'from_address' => (string) Env::get('MAIL_FROM_ADDRESS', 'no-reply@barberapp.pe'),
    'from_name' => (string) Env::get('MAIL_FROM_NAME', 'BARBERAPP'),
    'log_path' => dirname(__DIR__) . '/storage/mail',
    'smtp' => [
        'host' => (string) Env::get('MAIL_HOST', ''),
        'port' => (int) Env::get('MAIL_PORT', 587),
        'encryption' => (string) Env::get('MAIL_ENCRYPTION', 'tls'), // tls (STARTTLS) | ssl | none
        'username' => (string) Env::get('MAIL_USERNAME', ''),
        'password' => (string) Env::get('MAIL_PASSWORD', ''),
        'timeout' => (int) Env::get('MAIL_TIMEOUT', 10),
    ],
];
