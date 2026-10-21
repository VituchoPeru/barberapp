<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * Archivos subidos por las barberías (logo, portada y galería).
 * Las imágenes se validan, se recodifican (se descartan metadatos EXIF y contenido incrustado)
 * y se guardan con nombre aleatorio. MEDIA_PATH debe ser servido como estático por el servidor
 * web SIN ejecución de PHP (ver backend/public/media/.htaccess).
 */
return [
    'path' => Env::get('MEDIA_PATH') ?: dirname(__DIR__) . '/public/media',
    'url' => rtrim((string) (Env::get('MEDIA_URL') ?: rtrim((string) Env::get('APP_URL', 'http://localhost'), '/') . '/media'), '/'),
    'max_upload_bytes' => (int) Env::get('MEDIA_MAX_UPLOAD_BYTES', 5 * 1024 * 1024),
    // Dimensiones máximas aceptadas al subir (evita "bombas" de descompresión).
    'max_input_pixels' => 40_000_000,
    'max_input_side' => 8000,
    // Lado mayor tras redimensionar.
    'max_side' => ['logo' => 512, 'portada' => 1920, 'galeria' => 1600],
    'max_galeria' => 12,
];
