<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * Operación en producción (Fase 15): mantenimiento, latidos de las tareas programadas y monitoreo.
 *   php bin/console monitor:revisar [--alertar]   (cron cada 5 minutos)
 *   php bin/console mantenimiento:activar|desactivar
 * Ver docs/DESPLIEGUE.md.
 */
$storage = dirname(__DIR__) . '/storage';

return [
    // Archivo que pone la API en mantenimiento (503 con Retry-After). Lo crea el despliegue
    // mientras aplica migraciones; vive en storage/, que se comparte entre versiones.
    'maintenance_file' => $storage . '/cache/mantenimiento.json',

    // Carpeta de los latidos: cada tarea programada deja la hora de su última ejecución correcta.
    'heartbeat_path' => $storage . '/cache/latidos',

    // Antigüedad máxima (minutos) del último latido de cada tarea antes de avisar.
    // 0 = no se vigila. La tarea de copias fuera del servidor solo se vigila si está configurada.
    'heartbeats' => [
        'notificaciones:procesar' => (int) Env::get('MONITOR_MAX_WORKER_MIN', 5),
        'suscripciones:vencer' => 130,
        'backup:crear' => 26 * 60,
        'backup:verificar' => 8 * 24 * 60,
        'backup:enviar' => 26 * 60,
        'privacidad:retencion' => 26 * 60,
        'auth:purge' => 26 * 60,
    ],

    // Espacio libre mínimo en el disco de storage/ y de las copias (porcentaje).
    'min_free_disk_percent' => (int) Env::get('MONITOR_MIN_DISCO_LIBRE', 10),
    // Errores registrados en la última hora a partir de los cuales se avisa.
    'max_errors_per_hour' => (int) Env::get('MONITOR_MAX_ERRORES_HORA', 20),
    // Días que deben quedar a los certificados HTTPS de APP_URL y FRONTEND_URL (0 = no revisar).
    'min_cert_days' => (int) Env::get('MONITOR_MIN_DIAS_CERTIFICADO', 14),

    // Correo que recibe las alertas de monitor:revisar --alertar (vacío = solo el código de salida).
    'alert_email' => (string) Env::get('MONITOR_EMAIL', ''),
    // Mientras un problema siga, se repite la alerta cada estas horas.
    'alert_repeat_hours' => (int) Env::get('MONITOR_REPETIR_HORAS', 6),
    'alert_state_file' => $storage . '/cache/monitor-estado.json',

    // Token para GET /api/v1/health/operacion (monitores externos). Vacío = la ruta responde 404.
    'token' => (string) Env::get('MONITOR_TOKEN', ''),

    // Días que se conservan los registros storage/logs/app-*.log (php bin/console logs:purge).
    'log_retention_days' => (int) Env::get('LOG_RETENTION_DAYS', 30),
];
