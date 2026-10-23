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

    // Copias FUERA del servidor (Fase 15): almacenamiento compatible con S3 (Amazon S3, Cloudflare R2,
    // Backblaze B2, DigitalOcean Spaces, Wasabi, MinIO…).  php bin/console backup:enviar (cron diario)
    // Se suben las copias ya cifradas y las fotos de las barberías. Vacío BACKUP_S3_BUCKET = desactivado.
    'remote' => [
        'endpoint' => rtrim((string) Env::get('BACKUP_S3_ENDPOINT', ''), '/'),
        'region' => (string) (Env::get('BACKUP_S3_REGION') ?: 'us-east-1'),
        'bucket' => (string) Env::get('BACKUP_S3_BUCKET', ''),
        'access_key' => (string) Env::get('BACKUP_S3_KEY', ''),
        'secret_key' => (string) Env::get('BACKUP_S3_SECRET', ''),
        // Prefijo dentro del bucket (separa entornos que comparten bucket).
        'prefix' => trim((string) (Env::get('BACKUP_S3_PREFIX') ?: 'barberapp/' . Env::get('APP_ENV', 'production')), '/'),
        // true = https://endpoint/bucket/clave (MinIO y algunos proveedores); false = https://bucket.endpoint/clave
        'path_style' => (bool) Env::get('BACKUP_S3_PATH_STYLE', false),
        // Copias que se conservan en el bucket. 0 = no borrar nunca desde el servidor (recomendado con
        // una regla de ciclo de vida del bucket y una clave sin permiso de borrado, ver docs/DESPLIEGUE.md).
        'keep' => (int) Env::get('BACKUP_REMOTE_KEEP', 0),
        // Subir también las fotos de las barberías (MEDIA_PATH), de forma incremental.
        'media' => (bool) Env::get('BACKUP_S3_MEDIA', true),
        'timeout' => (int) Env::get('BACKUP_S3_TIMEOUT', 120),
    ],
];
