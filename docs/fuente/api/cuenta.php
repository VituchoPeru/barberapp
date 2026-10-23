<?php

declare(strict_types=1);

use App\Services\Notifications\Preferences;
use App\Services\Privacy\DataRequests;

/* Fichas OpenAPI: cuenta del usuario autenticado, avisos y favoritos. */

$canales = ['regla' => 'nullable|object', 'descripcion' => 'Canales de la categoría, p. ej. {"push": true, "email": false}.', 'campos' => [
    'push' => 'nullable|boolean',
    'email' => 'nullable|boolean',
]];

return [
    'me' => [
        'resumen' => 'Mi perfil, rol de plataforma, sesión y barbería seleccionada',
        'respuesta' => ['datos' => [
            'usuario' => 'required|object',
            'es_cliente' => 'required|boolean',
            'plataforma' => ['regla' => 'nullable|object', 'descripcion' => 'Solo el equipo de BARBERAPP: rol y permisos plataforma.*.'],
            'sesion' => ['regla' => 'required|object', 'campos' => ['id' => 'required|integer', 'canal' => 'required|in:web,movil', 'expira_en' => 'required|datetime']],
            'contexto' => ['regla' => 'nullable|object', 'descripcion' => 'Barbería de trabajo de la sesión con mis roles y permisos.'],
        ]],
    ],
    'me.memberships' => [
        'resumen' => 'Barberías donde trabajo (roles, permisos e invitaciones pendientes)',
    ],
    'me.memberships.accept' => [
        'resumen' => 'Aceptar una invitación para trabajar en una barbería',
        'respuesta' => ['datos' => ['membresia_id' => 'required|integer', 'estado' => 'required|in:activo', 'rol' => 'required|string']],
    ],
    'me.memberships.reject' => [
        'resumen' => 'Rechazar una invitación',
        'respuesta' => ['datos' => ['membresia_id' => 'required|integer', 'estado' => 'required|in:revocado', 'rol' => 'required|string']],
    ],
    'me.context' => [
        'resumen' => 'Seleccionar la barbería de trabajo de la sesión',
        'descripcion' => 'Las rutas /panel y /barbero usan esta barbería cuando no llega X-Tenant. Con tenant null se quita la selección.',
        'cuerpo' => ['tenant' => ['regla' => 'nullable|slug|max:80', 'descripcion' => 'Slug de una barbería donde tengo membresía activa.']],
        'errores' => ['not_found'],
        'respuesta' => ['datos' => ['contexto' => 'nullable|object']],
    ],
    'me.sessions' => [
        'resumen' => 'Mis sesiones abiertas (dispositivos)',
        'descripcion' => 'La sesión actual viene con "actual": true.',
    ],
    'me.sessions.revoke' => [
        'resumen' => 'Cerrar la sesión de otro dispositivo',
        'sin_contenido' => true,
        'errores' => ['use_logout'],
    ],
    'me.profile.update' => [
        'resumen' => 'Corregir mis nombres, apellidos y teléfono (derecho de rectificación)',
        'cuerpo' => [
            'nombres' => 'required|string|min:2|max:100',
            'apellidos' => 'nullable|string|max:100',
            'telefono' => 'nullable|regex:/^\+?[0-9]{6,15}$/',
        ],
        'respuesta' => 'Perfil actualizado.',
    ],
    'me.password' => [
        'resumen' => 'Cambiar mi contraseña',
        'descripcion' => 'Pide la contraseña actual y cierra las demás sesiones.',
        'cuerpo' => ['password_actual' => 'required|string|max:200', 'password_nueva' => 'required|string|max:200'],
        'errores' => ['invalid_credentials', 'too_many_attempts'],
        'respuesta' => ['datos' => ['sesiones_cerradas' => 'required|integer', 'mensaje' => 'required|string']],
    ],
    'me.email' => [
        'resumen' => 'Cambiar mi correo',
        'descripcion' => 'Envía un enlace al correo nuevo; el cambio se aplica al confirmarlo (POST /auth/confirm-email).',
        'cuerpo' => ['email' => 'required|string|max:190', 'password' => 'required|string|max:200'],
        'errores' => ['invalid_credentials', 'email_taken', 'too_many_attempts'],
        'respuesta' => ['datos' => ['mensaje' => 'required|string']],
    ],
    'me.sessions.close_others' => [
        'resumen' => 'Cerrar todas mis sesiones salvo la actual',
        'respuesta' => ['datos' => ['sesiones_cerradas' => 'required|integer']],
    ],
    'me.legal.accept' => [
        'resumen' => 'Aceptar la versión vigente de términos y política de privacidad',
        'cuerpo' => ['acepta' => ['regla' => 'required|boolean', 'descripcion' => 'Debe ser true.']],
        'respuesta' => 'Perfil con las versiones aceptadas.',
    ],
    'me.data.export' => [
        'resumen' => 'Descargar todos mis datos (derecho de acceso)',
        'descripcion' => 'Pide la contraseña. Devuelve un archivo JSON con perfil, sesiones, reservas, favoritos, avisos y solicitudes, y registra la solicitud de acceso atendida.',
        'cuerpo' => ['password' => 'required|string|max:200'],
        'produce' => ['application/json' => 'Archivo barberapp-mis-datos-AAAAMMDD.json (Content-Disposition: attachment).'],
        'errores' => ['invalid_credentials', 'too_many_attempts'],
        'respuesta' => 'Archivo con mis datos.',
    ],
    'me.deletion.preview' => [
        'resumen' => 'Qué pasará al eliminar mi cuenta y si algo lo impide',
        'descripcion' => 'Lista citas futuras que se cancelarán, datos que se conservan por obligación legal y bloqueos (p. ej. ser el único propietario de una barbería activa).',
    ],
    'me.delete' => [
        'resumen' => 'Eliminar mi cuenta',
        'descripcion' => 'Irreversible: anonimiza los datos personales, cancela citas futuras, cierra sesiones y envía la constancia por correo. Pide la contraseña y escribir ELIMINAR.',
        'cuerpo' => ['password' => 'required|string|max:200', 'confirmacion' => ['regla' => 'required|string|max:20', 'descripcion' => 'Texto ELIMINAR.']],
        'errores' => ['invalid_credentials', 'account_deletion_blocked', 'account_already_deleted', 'too_many_attempts'],
        'respuesta' => ['datos' => ['mensaje' => 'required|string']],
    ],
    'me.data_requests' => [
        'resumen' => 'Mis solicitudes de derechos sobre datos personales (ARCO)',
    ],
    'me.data_requests.store' => [
        'resumen' => 'Presentar una solicitud de acceso, rectificación, cancelación u oposición',
        'descripcion' => 'BARBERAPP responde dentro de los plazos de la Ley 29733 (ver docs/SEGURIDAD.md). Máximo 5 solicitudes abiertas.',
        'cuerpo' => [
            'tipo' => 'required|in:' . implode(',', DataRequests::TIPOS),
            'detalle' => ['regla' => 'required|string|min:10|max:2000', 'descripcion' => 'Qué se pide y por qué.'],
        ],
        'estado' => 201,
        'errores' => ['too_many_open_requests'],
    ],

    // ---------------------------------------------------------------- favoritos
    'me.favorites' => [
        'resumen' => 'Mis barberías favoritas',
        'respuesta' => 'Tarjetas con el mismo formato que la búsqueda del marketplace.',
    ],
    'me.favorites.add' => [
        'resumen' => 'Guardar una barbería en favoritos',
        'descripcion' => 'Idempotente. Máximo 200 favoritas.',
        'errores' => ['favorites_limit'],
        'respuesta' => ['datos' => ['slug' => 'required|string', 'favorito' => 'required|boolean']],
    ],
    'me.favorites.remove' => [
        'resumen' => 'Quitar una barbería de favoritos',
        'descripcion' => 'Idempotente; funciona aunque la barbería ya no esté activa.',
        'sin_contenido' => true,
    ],

    // ---------------------------------------------------------------- avisos
    'me.notifications' => [
        'resumen' => 'Bandeja de avisos',
        'consulta' => ['solo_no_leidas' => ['regla' => 'nullable|in:0,1', 'descripcion' => '1 = solo los no leídos.']],
        'paginado' => [20, 50],
        'respuesta' => ['descripcion' => 'Lista de avisos; meta trae total y no_leidas.', 'datos' => [
            'id' => 'required|integer', 'tipo' => 'required|string', 'titulo' => 'required|string', 'cuerpo' => 'required|string',
            'fecha' => 'required|datetime', 'leida' => 'required|boolean', 'reserva_id' => 'nullable|integer',
            'accion' => ['regla' => 'nullable|in:confirmar_asistencia', 'descripcion' => 'Botón que la app puede ofrecer.'],
        ]],
    ],
    'me.notifications.summary' => [
        'resumen' => 'Cantidad de avisos no leídos (insignia de la campana)',
        'respuesta' => ['datos' => ['no_leidas' => 'required|integer']],
    ],
    'me.notifications.read_all' => [
        'resumen' => 'Marcar todos los avisos como leídos',
        'respuesta' => ['datos' => ['no_leidas' => 'required|integer']],
    ],
    'me.notifications.read' => [
        'resumen' => 'Marcar un aviso como leído',
        'respuesta' => ['datos' => ['no_leidas' => 'required|integer']],
    ],
    'me.notification_prefs' => [
        'resumen' => 'Qué avisos recibo por push y por correo',
        'descripcion' => 'Categorías: ' . implode(', ', array_keys(Preferences::CATEGORIAS)) . ' (agenda solo para el personal y sin correo). Los avisos siempre quedan en la bandeja.',
    ],
    'me.notification_prefs.update' => [
        'resumen' => 'Cambiar mis preferencias de avisos',
        'descripcion' => 'Envía solo las categorías y canales que cambian.',
        'cuerpo' => [
            'recordatorios' => $canales,
            'cambios' => $canales,
            'agenda' => ['regla' => 'nullable|object', 'descripcion' => 'Solo personal; solo push.', 'campos' => ['push' => 'nullable|boolean']],
        ],
    ],
    'me.devices' => [
        'resumen' => 'Teléfonos con avisos push activados',
    ],
    'me.devices.register' => [
        'resumen' => 'Registrar el token FCM de la app',
        'descripcion' => 'Lo llama la app (Capacitor) al obtener permiso de notificaciones. Si el token ya existe se actualiza.',
        'cuerpo' => [
            'token' => 'required|string|min:20|max:1024',
            'plataforma' => 'required|in:android,ios,web',
            'app_version' => 'nullable|string|max:20',
            'modelo' => 'nullable|string|max:80',
        ],
        'estado' => 201,
        'respuesta' => ['datos' => ['id' => 'required|integer', 'dispositivos' => 'required|array']],
    ],
    'me.devices.unregister' => [
        'resumen' => 'Desactivar un token push (al cerrar sesión en la app)',
        'descripcion' => 'Idempotente: no revela si el token existía.',
        'cuerpo' => ['token' => 'required|string|max:1024'],
        'sin_contenido' => true,
    ],
    'me.devices.deactivate' => [
        'resumen' => 'Desactivar un dispositivo desde Mi cuenta',
        'respuesta' => 'Lista de dispositivos actualizada.',
    ],
];
