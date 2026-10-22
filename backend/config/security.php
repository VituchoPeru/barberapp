<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * Endurecimiento HTTP (Fase 13).
 */
$list = static fn (string $v): array => array_values(array_filter(array_map('trim', explode(',', $v))));

return [
    // Proxies o balanceadores de confianza (IP o CIDR, separados por coma). Solo de ellos se
    // aceptan X-Forwarded-For y X-Forwarded-Proto. Vacío = no hay proxy (se usa REMOTE_ADDR).
    'trusted_proxies' => $list((string) Env::get('TRUSTED_PROXIES', '')),

    // Límite general de peticiones que modifican datos (POST/PUT/PATCH/DELETE) por IP:
    // [máximo, ventana en segundos]. 0 desactiva. Los webhooks de pago quedan fuera.
    'write_limit' => [(int) Env::get('RATE_LIMIT_WRITES', 120), 60],

    // La Content-Security-Policy del frontend web está en frontend/www/.htaccess (ver docs/SEGURIDAD.md).
];
