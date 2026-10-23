<?php

declare(strict_types=1);

/*
 * Fichas OpenAPI: salud, textos legales, webhooks y autenticación.
 * Notación de reglas igual a App\Helpers\Validator (ver App\Services\Docs\RuleSchema).
 */

$sesion = [
    'regla' => 'nullable|object',
    'descripcion' => 'Sesión creada. Con canal "web" llega además la cookie y csrf_token; con "movil", access_token y refresh_token.',
    'campos' => [
        'id' => 'required|integer',
        'canal' => 'required|in:web,movil',
        'expira_en' => 'required|datetime',
    ],
];
$tokens = [
    'sesion' => $sesion,
    'csrf_token' => ['regla' => 'nullable|string', 'descripcion' => 'Solo web: valor para la cabecera X-CSRF-Token.'],
    'access_token' => ['regla' => 'nullable|string', 'descripcion' => 'Solo móvil: token Bearer de 15 minutos.'],
    'token_type' => ['regla' => 'nullable|in:Bearer', 'descripcion' => 'Solo móvil.'],
    'expires_in' => ['regla' => 'nullable|integer', 'descripcion' => 'Solo móvil: segundos de vida del access_token (AUTH_ACCESS_TTL, 900).'],
    'refresh_token' => ['regla' => 'nullable|string', 'descripcion' => 'Solo móvil: se cambia en cada renovación (rotativo).'],
    'refresh_expira_en' => ['regla' => 'nullable|datetime', 'descripcion' => 'Solo móvil.'],
];

return [
    // ---------------------------------------------------------------- salud y sistema
    'health' => [
        'resumen' => 'Estado de la aplicación, la base de datos y las migraciones',
        'descripcion' => 'Responde 503 con status "degraded" si la base no responde o hay migraciones pendientes. Con APP_DEBUG muestra la versión de MySQL.',
        'respuesta' => ['descripcion' => 'Todo en orden (o 503 si algo falla, con el mismo formato).', 'datos' => [
            'status' => 'required|in:ok,degraded',
            'maintenance' => 'required|boolean',
            'version' => 'nullable|string',
            'environment' => 'required|string',
            'time_utc' => 'required|datetime',
            'checks' => 'required|object',
        ]],
    ],
    'health.live' => [
        'resumen' => 'Confirma que PHP responde (sin consultar la base)',
        'descripcion' => 'Para balanceadores y monitores de disponibilidad.',
        'respuesta' => ['datos' => ['status' => 'required|in:ok', 'time_utc' => 'required|datetime']],
    ],
    'health.operation' => [
        'resumen' => 'Revisión operativa completa para monitores externos',
        'descripcion' => 'Ejecuta las mismas comprobaciones que `php bin/console monitor:revisar` (worker, cron, copias, disco, certificados…). Exige `Authorization: Bearer MONITOR_TOKEN`; sin MONITOR_TOKEN configurado responde 404. Devuelve 503 si hay algún problema.',
        'consulta' => ['certificados' => ['regla' => 'nullable|in:0,1', 'descripcion' => '1 = revisa también el vencimiento de los certificados HTTPS.']],
        'errores' => ['unauthenticated'],
        'respuesta' => ['datos' => [
            'status' => 'required|in:ok,error',
            'problemas' => 'required|integer',
            'puntos' => ['regla' => 'required|array', 'items' => ['nivel' => 'required|in:ok,aviso,error', 'tema' => 'required|string', 'mensaje' => 'required|string']],
        ]],
    ],
    'legal' => [
        'resumen' => 'Versión vigente de términos y privacidad y datos del responsable del tratamiento',
        'descripcion' => 'Público. La app compara estas versiones con las aceptadas por la persona (ver POST /me/legal/aceptar).',
    ],
    'webhooks.payments' => [
        'resumen' => 'Notificación de la pasarela de pago (Mercado Pago o simulador)',
        'descripcion' => 'Lo llama la pasarela, no la app. La autenticidad se comprueba con la firma (`x-signature`) y el pago se consulta a la pasarela antes de aplicarlo; los eventos repetidos se ignoran. Responde 200 cuando el evento quedó registrado, 401 con firma inválida y 503 si la pasarela no respondió (para que reintente). Ver docs/MONETIZACION.md.',
        'errores' => ['gateway_error'],
        'respuesta' => ['datos' => ['resultado' => 'required|string']],
    ],

    // ---------------------------------------------------------------- autenticación
    'auth.register' => [
        'resumen' => 'Crear una cuenta de cliente o de propietario de barbería',
        'descripcion' => 'Con "tipo": "propietario" crea también la barbería (queda pendiente de aprobación si la plataforma lo exige). Envía el correo de verificación e inicia la sesión. La contraseña debe tener al menos AUTH_PASSWORD_MIN caracteres (8 por defecto), letras y números, y no puede ser común ni contener el correo.',
        'cuerpo' => [
            'tipo' => 'required|in:cliente,propietario',
            'email' => 'required|email',
            'password' => 'required|string|max:200',
            'nombres' => 'required|string|min:2|max:100',
            'apellidos' => 'nullable|string|max:100',
            'telefono' => 'nullable|regex:/^\+?[0-9]{6,15}$/',
            'acepta_terminos' => ['regla' => 'required|boolean', 'descripcion' => 'Debe ser true: acepta los términos y la política de privacidad vigentes.'],
            'canal' => ['regla' => 'nullable|in:web,movil', 'descripcion' => 'web (por defecto) = cookie; movil = tokens.'],
            'dispositivo' => ['regla' => 'nullable|string|max:120', 'descripcion' => 'Nombre del dispositivo para "Mis sesiones".'],
            'barberia' => ['regla' => 'nullable|object', 'descripcion' => 'Obligatorio si tipo = propietario.', 'campos' => [
                'nombre_comercial' => 'required|string|min:3|max:120',
                'slug' => ['regla' => 'nullable|slug|min:3|max:80', 'descripcion' => 'Enlace directo /b/{slug}; si se omite se genera del nombre.'],
                'ruc' => 'nullable|regex:/^(10|15|17|20)\d{9}$/',
                'telefono' => 'nullable|string|max:20',
            ]],
        ],
        'estado' => 201,
        'errores' => ['email_taken', 'too_many_attempts'],
        'respuesta' => ['descripcion' => 'Cuenta creada y sesión iniciada.', 'datos' => [
            'usuario' => 'required|object',
            'barberia' => ['regla' => 'nullable|object', 'descripcion' => 'Solo propietario: id, slug, nombre_comercial y estado.'],
        ] + $tokens],
    ],
    'auth.login' => [
        'resumen' => 'Iniciar sesión',
        'descripcion' => 'Limitado por correo y por IP (too_many_attempts). Un intento fallido queda en la auditoría.',
        'cuerpo' => [
            'email' => 'required|email',
            'password' => 'required|string|max:200',
            'canal' => ['regla' => 'nullable|in:web,movil', 'descripcion' => 'web (por defecto) = cookie HttpOnly; movil = access_token + refresh_token.'],
            'dispositivo' => 'nullable|string|max:120',
        ],
        'errores' => ['invalid_credentials', 'account_disabled', 'too_many_attempts'],
        'respuesta' => ['datos' => ['usuario' => 'required|object'] + $tokens],
    ],
    'auth.refresh' => [
        'resumen' => 'Renovar el token de acceso de la app',
        'descripcion' => 'Entrega un access_token y un refresh_token nuevos; el anterior deja de servir. Reusar un refresh token ya cambiado cierra la sesión (posible robo).',
        'cuerpo' => ['refresh_token' => 'required|string|max:200'],
        'errores' => ['invalid_refresh_token', 'too_many_attempts'],
        'respuesta' => ['datos' => ['sesion' => $sesion, 'token_type' => 'required|in:Bearer', 'access_token' => 'required|string', 'expires_in' => 'required|integer', 'refresh_token' => 'required|string', 'refresh_expira_en' => 'required|datetime']],
    ],
    'auth.forgot' => [
        'resumen' => 'Pedir el correo para restablecer la contraseña',
        'descripcion' => 'Responde igual exista o no la cuenta (no revela qué correos están registrados).',
        'cuerpo' => ['email' => 'required|email'],
        'estado' => 202,
        'errores' => ['too_many_attempts'],
        'respuesta' => ['datos' => ['mensaje' => 'required|string']],
    ],
    'auth.reset' => [
        'resumen' => 'Restablecer la contraseña con el enlace del correo',
        'descripcion' => 'Cierra todas las sesiones abiertas de la cuenta.',
        'cuerpo' => ['token' => 'required|string|max:200', 'password' => 'required|string|max:200'],
        'errores' => ['invalid_token', 'too_many_attempts'],
        'respuesta' => ['datos' => ['mensaje' => 'required|string']],
    ],
    'auth.verify' => [
        'resumen' => 'Verificar el correo con el enlace recibido',
        'cuerpo' => ['token' => 'required|string|max:200'],
        'errores' => ['invalid_token'],
        'respuesta' => ['datos' => ['usuario' => 'required|object', 'mensaje' => 'required|string']],
    ],
    'auth.confirm_email' => [
        'resumen' => 'Confirmar el cambio de correo desde el correo nuevo',
        'cuerpo' => ['token' => 'required|string|max:200'],
        'errores' => ['invalid_token', 'email_unavailable'],
        'respuesta' => ['datos' => ['usuario' => 'required|object', 'mensaje' => 'required|string']],
    ],
    'auth.logout' => [
        'resumen' => 'Cerrar la sesión actual (o todas)',
        'cuerpo' => ['todas' => ['regla' => 'nullable|boolean', 'descripcion' => 'true = cierra también las sesiones de otros dispositivos.']],
        'respuesta' => ['datos' => ['sesiones_cerradas' => 'required|integer']],
    ],
    'auth.csrf' => [
        'resumen' => 'Obtener el token CSRF de la sesión web',
        'descripcion' => 'Para recuperar el valor de X-CSRF-Token tras recargar la página. Solo sesiones de cookie.',
        'errores' => ['not_a_web_session'],
        'respuesta' => ['datos' => ['csrf_token' => 'required|string']],
    ],
    'auth.verify.resend' => [
        'resumen' => 'Reenviar el correo de verificación',
        'errores' => ['too_many_attempts'],
        'respuesta' => ['datos' => ['enviado' => 'required|boolean', 'mensaje' => 'required|string']],
    ],

    // ---------------------------------------------------------------- confirmar asistencia (enlace firmado)
    'attendance.show' => [
        'resumen' => 'Ver la cita del enlace de un recordatorio',
        'descripcion' => 'Sin sesión: el token firmado identifica una sola cita y su horario. No muestra teléfono, correo ni importes. Un token inválido cuenta para el límite de intentos por IP. POST para que el token no quede en registros de acceso.',
        'cuerpo' => ['token' => 'required|string'],
        'errores' => ['invalid_link', 'booking_changed', 'too_many_attempts'],
        'respuesta' => ['datos' => [
            'reserva_id' => 'required|integer', 'codigo' => 'required|string', 'estado' => 'required|string',
            'barberia' => 'required|string', 'sucursal' => 'required|string', 'barbero' => 'required|string',
            'fecha' => 'required|string', 'hora' => 'required|string',
            'asistencia_confirmada' => 'required|boolean', 'puede_confirmar' => 'required|boolean',
        ]],
    ],
    'attendance.confirm' => [
        'resumen' => 'Confirmar asistencia desde el enlace del recordatorio',
        'descripcion' => 'Idempotente. No confirmar nunca cancela la cita.',
        'cuerpo' => ['token' => 'required|string'],
        'errores' => ['invalid_link', 'booking_changed', 'booking_not_active', 'too_many_attempts'],
        'respuesta' => 'La cita con asistencia_confirmada = true.',
    ],
];
