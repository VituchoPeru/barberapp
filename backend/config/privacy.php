<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * Protección de datos personales (Fase 13). Ley N.º 29733 y su reglamento vigente (D. S. 016-2024-JUS).
 * Los datos del responsable (razón social, RUC, domicilio, correo de privacidad, código de
 * inscripción del banco de datos) se editan en el panel de la plataforma → Configuración.
 */
return [
    // Versión vigente de cada texto legal (fecha de publicación). Al cambiarla, la app pide
    // aceptar el texto nuevo a quienes aceptaron uno anterior.
    'terminos_version' => (string) Env::get('LEGAL_TERMINOS_VERSION', '2026-10-22'),
    'privacidad_version' => (string) Env::get('LEGAL_PRIVACIDAD_VERSION', '2026-10-22'),

    // Plazos de respuesta en días hábiles contados desde el día siguiente (D. S. 016-2024-JUS,
    // art. 69): acceso 20; rectificación, cancelación y oposición 10. Ampliables una vez (art. 71).
    'plazos_dias_habiles' => ['acceso' => 20, 'rectificacion' => 10, 'cancelacion' => 10, 'oposicion' => 10],

    // Retención (php bin/console privacidad:retencion, cron diario). 0 = no borrar.
    'retencion' => [
        // Bitácora de auditoría (incluye IP y navegador): se conserva como registro de seguridad.
        'auditoria_dias' => (int) Env::get('RETENTION_AUDIT_DAYS', 365),
        // Webhooks de la pasarela ya procesados.
        'webhooks_dias' => (int) Env::get('RETENTION_WEBHOOK_DAYS', 180),
        // Cuentas que nunca confirmaron su correo ni usaron la plataforma (sin citas ni equipo).
        'cuentas_sin_verificar_dias' => (int) Env::get('RETENTION_UNVERIFIED_DAYS', 90),
        // Solicitudes de derechos ya respondidas (prueba de cumplimiento).
        'solicitudes_dias' => (int) Env::get('RETENTION_REQUESTS_DAYS', 1825),
        // Envíos de avisos (notificacion_envios) se borran con notificaciones:purge (NOTIFY_RETENTION_DAYS).
    ],

    // Límites de las acciones de privacidad: [máximo, ventana en segundos]
    'limits' => [
        'exportar_usuario' => [5, 3600],
        'reautenticacion_usuario' => [5, 900],   // contraseña incorrecta al eliminar cuenta o cambiar datos
        'solicitud_usuario' => [5, 86400],
        'cambio_email_usuario' => [3, 3600],
    ],
];
