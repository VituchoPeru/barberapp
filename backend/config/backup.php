<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * Copias de seguridad (Fase 13).
 *   php bin/console backup:crear       (cron diario)
 *   php bin/console backup:verificar   (cron semanal: restaura la última copia en una base temporal y compara)
 */
return [
    // Carpeta de las copias (FUERA de public/). Vacío = backend/storage/backups.
    'path' => (string) (Env::get('BACKUP_PATH') ?: dirname(__DIR__) . '/storage/backups'),
    // Clave de cifrado AES-256-GCM (base64:…, 32 bytes): php bin/console backup:clave
    // Obligatoria en producción. Guárdela también FUERA del servidor: sin ella no se puede restaurar.
    'key' => (string) Env::get('BACKUP_KEY', ''),
    // Cuántas copias se conservan (las más antiguas se borran al crear una nueva).
    'keep' => (int) Env::get('BACKUP_KEEP', 14),
    // Base temporal para la prueba de restauración (se crea y se borra; el usuario de MySQL necesita CREATE y DROP).
    'verify_database' => (string) (Env::get('BACKUP_VERIFY_DATABASE') ?: (Env::get('DB_DATABASE', 'barberapp') . '_restore_check')),
];
