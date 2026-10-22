<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * Avisos y recordatorios (Fase 8).
 *   PUSH_DRIVER=log   → escribe cada push en storage/push/*.log (desarrollo / XAMPP)
 *   PUSH_DRIVER=fcm   → Firebase Cloud Messaging (API HTTP v1) con cuenta de servicio
 *   PUSH_DRIVER=none  → push desactivado (los avisos push quedan como "omitida")
 *   PUSH_DRIVER=array → en memoria (pruebas automáticas)
 * El worker se ejecuta con cron cada minuto: php bin/console notificaciones:procesar
 */
$base = dirname(__DIR__);

return [
    'push' => [
        'driver' => (string) Env::get('PUSH_DRIVER', 'log'),
        'log_path' => $base . '/storage/push',
        'fcm' => [
            // Ruta ABSOLUTA del JSON de la cuenta de servicio, FUERA de public/ y del repositorio.
            'credentials' => (string) Env::get('FCM_CREDENTIALS', ''),
            'project_id' => (string) Env::get('FCM_PROJECT_ID', ''),
            'endpoint' => (string) Env::get('FCM_ENDPOINT', 'https://fcm.googleapis.com'),
            'timeout' => (int) Env::get('FCM_TIMEOUT', 10),
            'cache_path' => $base . '/storage/cache/fcm-access-token.json',
        ],
    ],
    'worker' => [
        // Máximo de avisos por ejecución y tiempo máximo (el cron corre cada minuto).
        'batch' => (int) Env::get('NOTIFY_BATCH', 200),
        'max_seconds' => (int) Env::get('NOTIFY_MAX_SECONDS', 50),
        // Un aviso "procesando" por más de estos minutos se considera de un worker caído y vuelve a la cola.
        'stale_minutes' => 10,
        // Espera antes de cada reintento (segundos): 1 min, 5 min, 15 min, 1 h, 3 h.
        'backoff' => [60, 300, 900, 3600, 10800],
        'max_attempts' => (int) Env::get('NOTIFY_MAX_ATTEMPTS', 5),
        // El planificador crea recordatorios cuya hora de envío pasó hace como máximo estos minutos.
        'grace_minutes' => 30,
        'lookahead_minutes' => 15,
    ],
    // notificaciones:purge borra avisos ya resueltos y su registro de envíos más antiguos que esto.
    'retention_days' => (int) Env::get('NOTIFY_RETENTION_DAYS', 180),
];
