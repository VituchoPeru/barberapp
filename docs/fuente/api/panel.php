<?php

declare(strict_types=1);

use App\Repositories\AgendaBlockRepository;
use App\Repositories\MarketplaceRepository;
use App\Services\Booking\AvailabilityService;
use App\Services\Media\ImageStore;
use App\Services\Notifications\NotificationSettings;
use App\Services\Panel\AgendaService;
use App\Services\Schedule\WeeklySchedule;
use App\Services\StaffService;

/* Fichas OpenAPI: panel de la barbería (/panel). */

$parcial = 'Edición parcial: envía solo los campos que cambian.';
$servicioIds = ['regla' => 'required|array|min:1|max:' . AvailabilityService::MAX_SERVICIOS, 'descripcion' => 'Servicios de la cita, seguidos y con el mismo barbero. En la consulta: "servicio_ids=3,5".', 'items' => 'integer|min:1'];
$fechas = [
    'fecha' => ['regla' => 'nullable|date', 'descripcion' => 'Día concreto: horas libres de ese día.'],
    'desde' => ['regla' => 'nullable|date', 'descripcion' => 'Sin "fecha": resumen por día (hoy por defecto).'],
    'hasta' => ['regla' => 'nullable|date', 'descripcion' => 'Máximo ' . AvailabilityService::MAX_DIAS_CONSULTA . ' días.'],
];
$sucursal = fn (string $req) => [
    'nombre' => $req . 'string|min:2|max:120',
    'direccion' => $req . 'string|min:3|max:255',
    'referencia' => 'nullable|string|max:255',
    'distrito' => $req . 'string|min:2|max:80',
    'provincia' => ['regla' => 'nullable|string|min:2|max:80', 'descripcion' => 'Lima por defecto.'],
    'departamento' => ['regla' => 'nullable|string|min:2|max:80', 'descripcion' => 'Lima por defecto.'],
    'ubigeo' => 'nullable|regex:/^\d{6}$/',
    'telefono' => 'nullable|regex:/^\+?[0-9]{6,15}$/',
    'email' => 'nullable|email',
    'zona_horaria' => ['regla' => 'nullable|string|max:64', 'descripcion' => 'Identificador IANA, p. ej. America/Lima (por defecto, la de la barbería).'],
    'latitud' => ['regla' => 'nullable|number|min:-90|max:90', 'descripcion' => 'Junto con longitud; null borra la ubicación.'],
    'longitud' => 'nullable|number|min:-180|max:180',
    'es_principal' => 'nullable|boolean',
    'activo' => 'nullable|boolean',
];
$servicio = fn (string $req) => [
    'nombre' => $req . 'string|min:2|max:120',
    'descripcion' => 'nullable|string|max:1000',
    'duracion_minutos' => ['regla' => $req . 'integer|min:5|max:600', 'descripcion' => 'Múltiplo de 5; nunca cero.'],
    'precio' => ['regla' => $req . 'decimal|min:0|max:99999.99', 'descripcion' => 'No negativo.'],
    'categoria_id' => 'nullable|integer|min:1',
    'tipo' => ['regla' => 'nullable|string|max:40', 'descripcion' => 'Código del tipo de servicio para el filtro del marketplace.'],
    'activo' => ['regla' => 'nullable|boolean', 'descripcion' => 'Los servicios inactivos no se ofrecen.'],
    'orden' => 'nullable|integer|min:0|max:9999',
];
$barbero = fn (string $req) => [
    'nombre_publico' => $req . 'string|min:2|max:100',
    'especialidad' => 'nullable|string|max:120',
    'biografia' => 'nullable|string|max:1000',
    'sucursal_id' => ['regla' => $req . 'integer|min:1', 'descripcion' => 'Sucursal principal.'],
    'usuario_id' => ['regla' => 'nullable|integer|min:1', 'descripcion' => 'Cuenta del barbero (de cuentas_vinculables) para que use el panel del barbero.'],
    'activo' => 'nullable|boolean',
    'acepta_reservas_online' => 'nullable|boolean',
    'orden' => 'nullable|integer|min:0|max:9999',
];
$jornada = [
    'tramos' => ['regla' => 'required|array|max:' . WeeklySchedule::MAX_TRAMOS, 'descripcion' => 'Jornada semanal (puede estar vacía). Los tramos de un mismo día no se cruzan ni caen en dos sucursales a la vez.', 'items' => [
        'dia_semana' => ['regla' => 'required|integer|min:1|max:7', 'descripcion' => '1 = lunes … 7 = domingo.'],
        'hora_inicio' => 'required|time',
        'hora_fin' => 'required|time',
        'sucursal_id' => 'required|integer|min:1',
        'vigente_desde' => 'nullable|date',
        'vigente_hasta' => 'nullable|date',
    ]],
    'descansos' => ['regla' => 'nullable|array|max:' . WeeklySchedule::MAX_DESCANSOS, 'descripcion' => 'Descansos fijos dentro de la jornada (refrigerio).', 'items' => [
        'dia_semana' => 'required|integer|min:1|max:7',
        'hora_inicio' => 'required|time',
        'hora_fin' => 'required|time',
        'motivo' => 'nullable|string|max:80',
    ]],
];
$bloqueo = [
    'tipo' => 'required|in:' . implode(',', AgendaBlockRepository::TIPOS),
    'barbero_id' => ['regla' => 'nullable|integer|min:1', 'descripcion' => 'Sin barbero_id ni sucursal_id bloquea a toda la barbería (p. ej. feriado).'],
    'sucursal_id' => 'nullable|integer|min:1',
    'dia_completo' => ['regla' => 'nullable|boolean', 'descripcion' => 'true = usar fecha_inicio y fecha_fin; false = inicio y fin con hora.'],
    'fecha_inicio' => 'nullable|date',
    'fecha_fin' => 'nullable|date',
    'inicio' => ['regla' => 'nullable|regex:/^\d{4}-\d{2}-\d{2}[T ][0-2]\d:[0-5]\d$/', 'descripcion' => 'Fecha y hora local (AAAA-MM-DD HH:MM).'],
    'fin' => 'nullable|regex:/^\d{4}-\d{2}-\d{2}[T ][0-2]\d:[0-5]\d$/',
    'motivo' => 'nullable|string|max:255',
];
$cliente = [
    'nombres' => 'required|string|min:2|max:100',
    'apellidos' => 'nullable|string|max:100',
    'telefono' => ['regla' => 'nullable|regex:/^\+?[0-9]{6,15}$/', 'descripcion' => 'Un celular de 9 dígitos que empieza con 9 se guarda con +51.'],
    'email' => 'nullable|email',
];
$reserva = 'La reserva vista por el personal: datos del cliente, servicios, estado, historial de eventos, cobros y acciones permitidas según sus permisos.';
$transicion = fn (string $que) => [
    'resumen' => $que,
    'descripcion' => 'Las transiciones válidas las define BookingRules (docs/RESERVAS.md). Sin reservas.ver_todas solo se pueden tocar las citas propias del barbero.',
    'errores' => ['booking_not_active', 'too_early', 'attention_started', 'booking_has_payments'],
    'respuesta' => $reserva,
];

return [
    // ---------------------------------------------------------------- equipo y negocio
    'panel.context' => [
        'resumen' => 'Barbería de trabajo, mis roles, permisos, plan y ficha de barbero',
        'descripcion' => 'Cualquier integrante. Si la barbería está pendiente, suspendida o rechazada, aviso_estado explica por qué y a quién escribir; suscripcion trae plan, vencimiento y restricción.',
        'respuesta' => ['datos' => [
            'tenant' => 'required|object',
            'aviso_estado' => 'nullable|object',
            'suscripcion' => 'required|object',
            'roles' => ['regla' => 'required|array', 'items' => 'string'],
            'permisos' => ['regla' => 'required|array', 'items' => 'string'],
            'barbero' => ['regla' => 'nullable|object', 'descripcion' => 'Ficha de barbero vinculada a mi cuenta (habilita /barbero).'],
        ]],
    ],
    'panel.staff' => ['resumen' => 'Equipo de la barbería (propietarios, administradores y barberos)'],
    'panel.staff.invite' => [
        'resumen' => 'Invitar a un administrador o barbero',
        'descripcion' => 'Si el correo no tiene cuenta se crea una invitación con enlace para registrarse. La persona acepta desde POST /me/memberships/{id}/aceptar.',
        'cuerpo' => [
            'email' => 'required|email',
            'rol' => 'required|in:' . implode(',', StaffService::ROLES_INVITABLES),
            'nombres' => 'nullable|string|min:2|max:100',
        ],
        'estado' => 201,
        'errores' => ['already_member', 'plan_limit'],
    ],
    'panel.staff.revoke' => [
        'resumen' => 'Quitar el acceso de una persona del equipo',
        'sin_contenido' => true,
        'errores' => ['last_owner', 'cannot_revoke_self'],
    ],
    'panel.team_settings' => [
        'resumen' => 'Ajustes del equipo',
        'descripcion' => 'ausencias_requieren_aprobacion (true por defecto) y barbero_edita_horario (false por defecto).',
    ],
    'panel.team_settings.update' => [
        'resumen' => 'Cambiar los ajustes del equipo',
        'cuerpo' => [
            'ausencias_requieren_aprobacion' => ['regla' => 'nullable|boolean', 'descripcion' => 'Las ausencias que piden los barberos esperan aprobación.'],
            'barbero_edita_horario' => ['regla' => 'nullable|boolean', 'descripcion' => 'El barbero puede cambiar su propia jornada.'],
        ],
    ],
    'panel.business' => [
        'resumen' => 'Nombre comercial, colores, enlace y zona horaria',
        'descripcion' => 'personalizacion indica si el plan permite colores propios.',
    ],
    'panel.business.update' => [
        'resumen' => 'Cambiar el nombre comercial o los colores',
        'descripcion' => 'Los colores propios solo se aplican en planes con personalización.',
        'cuerpo' => [
            'nombre_comercial' => 'nullable|string|min:2|max:120',
            'color_primario' => ['regla' => 'nullable|regex:/^#[0-9A-F]{6}$/', 'descripcion' => 'Hexadecimal, p. ej. #C9A45C.'],
            'color_secundario' => 'nullable|regex:/^#[0-9A-F]{6}$/',
        ],
        'errores' => ['plan_feature'],
    ],

    // ---------------------------------------------------------------- perfil público
    'panel.public_profile' => [
        'resumen' => 'Perfil del marketplace, fotos, enlace directo y requisitos para publicarse',
        'respuesta' => ['datos' => [
            'slug' => 'required|string',
            'publicado_marketplace' => 'required|boolean',
            'visible_en_marketplace' => ['regla' => 'required|boolean', 'descripcion' => 'Publicada, activa y con sucursal y servicio activos.'],
            'lista_para_publicar' => 'required|boolean',
            'requisitos' => 'required|object',
            'enlace' => ['regla' => 'required|string', 'descripcion' => 'Enlace directo {PUBLIC_BOOKING_URL}/b/{slug}.'],
            'fotos' => 'required|array',
        ]],
    ],
    'panel.public_profile.update' => [
        'resumen' => 'Cambiar descripción, contacto, redes y publicación en el marketplace',
        'descripcion' => 'Las redes aceptan "@usuario" o la URL del perfil (se guarda solo el usuario). Para publicar, la barbería debe tener los requisitos completos. ' . $parcial,
        'cuerpo' => [
            'descripcion' => 'nullable|string|max:2000',
            'telefono' => 'nullable|regex:/^\+?[0-9]{6,15}$/',
            'whatsapp' => ['regla' => 'nullable|regex:/^\+?[0-9]{6,15}$/', 'descripcion' => 'Un celular de 9 dígitos se guarda con +51.'],
            'instagram' => 'nullable|regex:/^[A-Za-z0-9._]{1,30}$/',
            'facebook' => 'nullable|regex:/^[A-Za-z0-9.\-]{1,100}$/',
            'tiktok' => 'nullable|regex:/^[A-Za-z0-9._]{1,30}$/',
            'sitio_web' => 'nullable|string|max:255|regex:#^https?://[^\s"\'<>]+\.[^\s"\'<>]+$#i',
            'publicado_marketplace' => 'nullable|boolean',
        ],
        'errores' => ['profile_incomplete'],
    ],
    'panel.photos.upload' => [
        'resumen' => 'Subir logo, portada o foto de galería',
        'descripcion' => 'Cuerpo binario (no multipart). Se valida el tipo real, se quitan los metadatos y se convierte a WebP. Logo y portada reemplazan a la anterior.',
        'consulta' => [
            'tipo' => 'required|in:' . implode(',', ImageStore::TIPOS),
            'alt' => ['regla' => 'nullable|string|max:160', 'descripcion' => 'Texto alternativo.'],
        ],
        'cuerpo_imagen' => true,
        'estado' => 201,
        'errores' => ['unsupported_media_type', 'image_too_large', 'gallery_full', 'images_unavailable'],
        'respuesta' => ['datos' => ['id' => 'required|integer', 'tipo' => 'required|string', 'url' => 'required|string', 'ancho' => 'required|integer', 'alto' => 'required|integer']],
    ],
    'panel.photos.delete' => ['resumen' => 'Eliminar una foto', 'sin_contenido' => true],

    // ---------------------------------------------------------------- sucursales
    'panel.branches' => [
        'resumen' => 'Sucursales con su horario comercial',
        'descripcion' => 'meta.zonas_horarias lista las zonas de América.',
    ],
    'panel.branches.store' => [
        'resumen' => 'Registrar una sucursal',
        'descripcion' => 'La primera sucursal activa queda como principal.',
        'cuerpo' => $sucursal('required|'),
        'estado' => 201,
        'errores' => ['plan_limit'],
    ],
    'panel.branches.show' => ['resumen' => 'Detalle de una sucursal'],
    'panel.branches.update' => [
        'resumen' => 'Editar, activar, desactivar o marcar como principal',
        'descripcion' => 'No se puede desactivar con barberos activos o reservas futuras. ' . $parcial,
        'cuerpo' => $sucursal(''),
        'errores' => ['branch_has_barbers', 'branch_has_bookings', 'plan_limit'],
    ],
    'panel.branches.destroy' => [
        'resumen' => 'Eliminar una sucursal sin barberos ni reservas',
        'sin_contenido' => true,
        'errores' => ['branch_has_barbers', 'branch_has_bookings', 'branch_in_schedules'],
    ],
    'panel.branches.hours' => [
        'resumen' => 'Reemplazar el horario comercial de la sucursal',
        'descripcion' => 'meta.advertencias.jornadas_fuera_de_horario avisa de barberos con jornada fuera del nuevo horario (no se cambian solas).',
        'cuerpo' => ['horario' => ['regla' => 'required|array|max:21', 'descripcion' => 'Hasta 3 tramos por día; lista vacía = sin horario.', 'items' => [
            'dia_semana' => ['regla' => 'required|integer|min:1|max:7', 'descripcion' => '1 = lunes … 7 = domingo.'],
            'hora_apertura' => 'required|time',
            'hora_cierre' => 'required|time',
        ]]],
    ],

    // ---------------------------------------------------------------- servicios y categorías
    'panel.services' => [
        'resumen' => 'Todos los servicios (activos e inactivos)',
        'descripcion' => 'meta trae categorías, tipos de servicio y reglas (duración mínima, máxima y paso; precio máximo).',
    ],
    'panel.services.store' => ['resumen' => 'Crear un servicio', 'cuerpo' => $servicio('required|'), 'estado' => 201],
    'panel.services.show' => ['resumen' => 'Detalle de un servicio'],
    'panel.services.update' => [
        'resumen' => 'Editar un servicio',
        'descripcion' => 'meta.reservas_futuras indica cuántas citas ya tomadas conservan el precio y la duración anteriores. ' . $parcial,
        'cuerpo' => $servicio(''),
    ],
    'panel.services.destroy' => [
        'resumen' => 'Eliminar un servicio (lógico)',
        'descripcion' => 'Las reservas conservan su copia del nombre, la duración y el precio.',
    ],
    'panel.categories' => ['resumen' => 'Categorías de servicios'],
    'panel.categories.store' => [
        'resumen' => 'Crear una categoría',
        'cuerpo' => ['nombre' => 'required|string|min:2|max:80', 'orden' => 'nullable|integer|min:0|max:9999', 'activo' => 'nullable|boolean'],
        'estado' => 201,
    ],
    'panel.categories.update' => [
        'resumen' => 'Editar una categoría',
        'cuerpo' => ['nombre' => 'nullable|string|min:2|max:80', 'orden' => 'nullable|integer|min:0|max:9999', 'activo' => 'nullable|boolean'],
    ],
    'panel.categories.destroy' => [
        'resumen' => 'Eliminar una categoría vacía',
        'sin_contenido' => true,
        'errores' => ['category_in_use'],
    ],

    // ---------------------------------------------------------------- barberos
    'panel.barbers' => [
        'resumen' => 'Barberos y cuentas que se pueden vincular',
        'descripcion' => 'meta.cuentas_vinculables: integrantes con rol barbero aún sin ficha.',
    ],
    'panel.barbers.store' => ['resumen' => 'Registrar un barbero', 'cuerpo' => $barbero('required|'), 'estado' => 201, 'errores' => ['plan_limit']],
    'panel.barbers.show' => ['resumen' => 'Ficha del barbero con servicios, jornada y descansos'],
    'panel.barbers.update' => [
        'resumen' => 'Editar, activar o desactivar un barbero',
        'descripcion' => 'No se puede desactivar con reservas futuras. ' . $parcial,
        'cuerpo' => $barbero(''),
        'errores' => ['barber_has_bookings', 'plan_limit'],
    ],
    'panel.barbers.destroy' => ['resumen' => 'Eliminar un barbero sin reservas futuras', 'sin_contenido' => true, 'errores' => ['barber_has_bookings']],
    'panel.barbers.services' => [
        'resumen' => 'Reemplazar los servicios que realiza el barbero',
        'descripcion' => 'Opcionalmente con duración o precio propios del barbero.',
        'cuerpo' => ['servicios' => ['regla' => 'required|array|max:200', 'descripcion' => 'Lista completa (puede estar vacía).', 'items' => [
            'servicio_id' => 'required|integer|min:1',
            'duracion_minutos' => ['regla' => 'nullable|integer|min:5|max:600', 'descripcion' => 'Si el barbero tarda distinto al estándar.'],
            'precio' => 'nullable|decimal|min:0',
        ]]],
    ],
    'panel.barbers.schedule' => [
        'resumen' => 'Reemplazar la jornada semanal y los descansos',
        'descripcion' => 'Las reservas ya tomadas fuera de la nueva jornada se respetan y se listan en meta.advertencias.reservas_fuera_de_jornada.',
        'cuerpo' => $jornada,
    ],
    'panel.barbers.working_time' => [
        'resumen' => 'Jornada efectiva por fechas (con bloqueos y vacaciones aplicados)',
        'consulta' => ['desde' => ['regla' => 'nullable|date', 'descripcion' => 'Hoy por defecto.'], 'hasta' => ['regla' => 'nullable|date', 'descripcion' => 'desde + 6 días por defecto.']],
    ],
    'panel.barbers.photo' => [
        'resumen' => 'Subir la foto del barbero',
        'cuerpo_imagen' => true,
        'errores' => ['unsupported_media_type', 'image_too_large', 'images_unavailable'],
    ],
    'panel.barbers.photo.delete' => ['resumen' => 'Quitar la foto del barbero'],

    // ---------------------------------------------------------------- reglas de reserva y bloqueos
    'panel.booking_policy' => [
        'resumen' => 'Reglas de reserva: la general y las propias de cada sucursal',
    ],
    'panel.booking_policy.update' => [
        'resumen' => 'Guardar las reglas de reserva (general o de una sucursal)',
        'cuerpo' => [
            'sucursal_id' => ['regla' => 'nullable|integer|min:1', 'descripcion' => 'null = regla general de la barbería.'],
            'intervalo_minutos' => ['regla' => 'required|integer|min:5|max:120', 'descripcion' => 'Cada cuántos minutos se ofrece una hora (múltiplo de 5).'],
            'margen_entre_citas_minutos' => 'required|integer|min:0|max:120',
            'anticipacion_minima_minutos' => 'required|integer|min:0|max:10080',
            'horizonte_maximo_dias' => 'required|integer|min:1|max:365',
            'cancelacion_limite_minutos' => ['regla' => 'required|integer|min:0|max:10080', 'descripcion' => 'Hasta cuántos minutos antes el cliente puede cancelar en línea.'],
            'reprogramacion_limite_minutos' => 'required|integer|min:0|max:10080',
            'max_reprogramaciones' => 'required|integer|min:0|max:10',
            'max_reservas_activas_cliente' => 'required|integer|min:1|max:20',
            'confirmacion_automatica' => ['regla' => 'required|boolean', 'descripcion' => 'true = la reserva nace confirmada; false = pendiente.'],
            'permite_cualquier_barbero' => 'required|boolean',
            'texto_politica_cancelacion' => 'nullable|string|max:1000',
        ],
    ],
    'panel.booking_policy.destroy' => [
        'resumen' => 'Quitar la regla propia de una sucursal (vuelve a la general)',
        'sin_contenido' => true,
    ],
    'panel.blocks' => [
        'resumen' => 'Vacaciones, ausencias, feriados y bloqueos',
        'consulta' => [
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date',
            'barbero_id' => 'nullable|integer|min:1',
            'sucursal_id' => 'nullable|integer|min:1',
            'estado' => 'nullable|in:' . implode(',', AgendaBlockRepository::ESTADOS),
            'tipo' => 'nullable|in:' . implode(',', AgendaBlockRepository::TIPOS),
        ],
    ],
    'panel.blocks.store' => [
        'resumen' => 'Registrar un bloqueo (queda aprobado)',
        'descripcion' => 'Si se cruza con citas activas responde 409 con la lista; las citas no se cancelan solas.',
        'cuerpo' => $bloqueo,
        'estado' => 201,
        'errores' => ['block_conflicts_bookings', 'block_overlaps'],
    ],
    'panel.blocks.approve' => [
        'resumen' => 'Aprobar la ausencia que pidió un barbero',
        'cuerpo' => ['respuesta' => ['regla' => 'nullable|string|max:255', 'descripcion' => 'El barbero la ve en su panel.']],
        'errores' => ['block_not_pending', 'block_conflicts_bookings'],
    ],
    'panel.blocks.reject' => [
        'resumen' => 'Rechazar la ausencia que pidió un barbero',
        'cuerpo' => ['respuesta' => ['regla' => 'nullable|string|max:255', 'descripcion' => 'El barbero la ve en su panel.']],
        'errores' => ['block_not_pending'],
    ],
    'panel.blocks.destroy' => [
        'resumen' => 'Eliminar un bloqueo (el intervalo vuelve a ofrecerse)',
        'sin_contenido' => true,
    ],

    // ---------------------------------------------------------------- dashboard y reportes
    'panel.dashboard' => [
        'resumen' => 'Indicadores del día',
        'descripcion' => 'Reservas de hoy, pendientes, confirmadas, cancelaciones, atendidos, inasistencias, ocupación por barbero y, con reportes.financieros, ingresos registrados (cobros reales, no importes previstos).',
        'consulta' => ['fecha' => ['regla' => 'nullable|date', 'descripcion' => 'Hoy por defecto (hora local).'], 'sucursal_id' => 'nullable|integer|min:1'],
    ],
    'panel.reports' => [
        'resumen' => 'Reporte de un periodo',
        'descripcion' => 'Totales, tasa de confirmación, cancelaciones, inasistencias, ocupación, servicios populares, demanda por día y hora, productividad por barbero, clientes recurrentes, evolución mensual y (con reportes.financieros) previsto frente a cobrado. Ver docs/REPORTES.md.',
        'consulta' => [
            'desde' => ['regla' => 'nullable|date', 'descripcion' => 'hasta − 29 días por defecto.'],
            'hasta' => ['regla' => 'nullable|date', 'descripcion' => 'Hoy por defecto.'],
            'sucursal_id' => 'nullable|integer|min:1',
            'barbero_id' => 'nullable|integer|min:1',
        ],
    ],
    'panel.reports.export' => [
        'resumen' => 'Exportar el reporte o el detalle de reservas',
        'descripcion' => 'Queda registrado en la auditoría. El detalle incluye nombres de clientes solo con clientes.ver e importes cobrados solo con reportes.financieros. Máximo 20 000 filas.',
        'consulta' => [
            'tipo' => ['regla' => 'nullable|in:resumen,reservas', 'descripcion' => 'resumen (por defecto) o reservas una por una.'],
            'formato' => 'nullable|in:csv,xlsx,pdf',
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date',
            'sucursal_id' => 'nullable|integer|min:1',
            'barbero_id' => 'nullable|integer|min:1',
        ],
        'produce' => [
            'text/csv' => 'CSV UTF-8 con BOM (Excel lo abre con tildes).',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'Libro de Excel.',
            'application/pdf' => 'PDF generado en el servidor.',
        ],
        'respuesta' => 'Archivo para descargar (Content-Disposition: attachment).',
    ],

    // ---------------------------------------------------------------- agenda
    'panel.agenda' => [
        'resumen' => 'Agenda del día o de la semana',
        'descripcion' => 'Sin reservas.ver_todas (barbero) solo muestra sus propias citas.',
        'consulta' => [
            'fecha' => 'nullable|date',
            'vista' => ['regla' => 'nullable|in:dia,semana', 'descripcion' => 'dia por defecto.'],
            'desde' => ['regla' => 'nullable|date', 'descripcion' => 'Rango explícito (en vez de fecha + vista).'],
            'hasta' => 'nullable|date',
            'sucursal_id' => ['regla' => 'nullable|integer|min:1', 'descripcion' => 'La primera sucursal activa por defecto.'],
            'barbero_id' => 'nullable|integer|min:1',
        ],
    ],
    'panel.agenda.catalog' => ['resumen' => 'Servicios y barberos activos para el alta manual'],
    'panel.availability' => [
        'resumen' => 'Horarios libres para una cita registrada por el personal',
        'descripcion' => 'Como /disponibilidad, pero incluye barberos que no aceptan reservas en línea, sin anticipación mínima y con horizonte de hasta 365 días.',
        'consulta' => [
            'sucursal_id' => 'required|integer|min:1',
            'servicio_ids' => $servicioIds,
            'barbero_id' => 'nullable|integer|min:1',
        ] + $fechas,
        'errores' => ['no_barber_for_services'],
    ],
    'panel.bookings.store' => [
        'resumen' => 'Registrar una cita desde el panel (mostrador o teléfono)',
        'descripcion' => 'Indica cliente_id (cliente existente) o cliente (nuevo cliente de mostrador; si coincide teléfono o correo se reutiliza). Usa el mismo motor y bloqueo que la reserva en línea.',
        'cuerpo' => [
            'cliente_id' => 'nullable|integer|min:1',
            'cliente' => ['regla' => 'nullable|object', 'descripcion' => 'Cliente nuevo (si no se envía cliente_id).', 'campos' => $cliente],
            'sucursal_id' => 'required|integer|min:1',
            'servicio_ids' => $servicioIds,
            'barbero_id' => ['regla' => 'nullable|integer|min:1', 'descripcion' => 'Omitir = cualquier barbero disponible.'],
            'fecha' => 'required|date',
            'hora' => 'required|time',
            'notas' => ['regla' => 'nullable|string|max:500', 'descripcion' => 'Visible para el cliente.'],
            'notas_internas' => ['regla' => 'nullable|string|max:1000', 'descripcion' => 'Solo para el personal.'],
            'estado' => ['regla' => 'nullable|in:pendiente,confirmada', 'descripcion' => 'confirmada por defecto.'],
        ],
        'idempotente' => true,
        'estado' => 201,
        'errores' => ['slot_unavailable', 'client_overlap', 'no_barber_for_services', 'idempotency_in_progress', 'idempotency_key_reused'],
        'respuesta' => $reserva,
    ],
    'panel.bookings.show' => ['resumen' => 'Detalle de la cita con historial y cobros', 'respuesta' => $reserva],
    'panel.bookings.confirm' => $transicion('Confirmar la cita'),
    'panel.bookings.start' => $transicion('Iniciar la atención'),
    'panel.bookings.complete' => $transicion('Marcar como atendida'),
    'panel.bookings.no_show' => $transicion('Marcar inasistencia'),
    'panel.bookings.cancel' => [
        'resumen' => 'Cancelar la cita',
        'descripcion' => 'El personal no está sujeto al plazo del cliente. Libera el horario, anula los recordatorios y avisa al cliente si la regla está activa.',
        'cuerpo' => ['motivo' => 'required|string|min:3|max:255'],
        'errores' => ['booking_not_active'],
        'respuesta' => $reserva,
    ],
    'panel.bookings.availability' => [
        'resumen' => 'Horarios para reprogramar la cita',
        'consulta' => ['barbero_id' => ['regla' => 'nullable|integer|min:1', 'descripcion' => 'Otro barbero.']] + $fechas,
    ],
    'panel.bookings.reschedule' => [
        'resumen' => 'Reprogramar la cita',
        'cuerpo' => ['fecha' => 'required|date', 'hora' => 'required|time', 'barbero_id' => 'nullable|integer|min:1'],
        'idempotente' => true,
        'errores' => ['slot_unavailable', 'same_slot', 'booking_not_active', 'idempotency_in_progress', 'idempotency_key_reused'],
        'respuesta' => $reserva,
    ],
    'panel.bookings.notes' => [
        'resumen' => 'Guardar las notas internas de la cita',
        'cuerpo' => ['notas_internas' => 'nullable|string|max:1000'],
        'respuesta' => $reserva,
    ],
    'panel.bookings.pay' => [
        'resumen' => 'Registrar un cobro de la cita',
        'descripcion' => 'Ingreso real cobrado en la barbería (no pasa por BARBERAPP). Un cobro que supera el total pide confirmar_excedente.',
        'cuerpo' => [
            'monto' => 'required|decimal|min:0.01|max:99999.99',
            'metodo' => 'required|in:' . implode(',', AgendaService::METODOS_PAGO),
            'referencia' => ['regla' => 'nullable|string|max:100', 'descripcion' => 'N.º de operación (Yape, Plin, tarjeta…).'],
            'confirmar_excedente' => 'nullable|boolean',
        ],
        'estado' => 201,
        'errores' => ['booking_not_payable', 'payment_exceeds_total', 'duplicate_reference'],
        'respuesta' => $reserva,
    ],
    'panel.bookings.pay.void' => [
        'resumen' => 'Anular un cobro registrado',
        'cuerpo' => ['motivo' => 'required|string|min:3|max:255'],
        'respuesta' => $reserva,
    ],

    // ---------------------------------------------------------------- clientes
    'panel.clients' => [
        'resumen' => 'Clientes vinculados a la barbería',
        'consulta' => [
            'q' => ['regla' => 'nullable|string|max:100', 'descripcion' => 'Nombre, teléfono o correo.'],
            'orden' => 'nullable|in:recientes,nombre,reservas',
        ],
        'paginado' => [20, 50],
    ],
    'panel.clients.store' => [
        'resumen' => 'Registrar un cliente de mostrador',
        'descripcion' => 'Si ya existe uno con el mismo teléfono o correo responde 200 con ya_existia = true.',
        'cuerpo' => $cliente,
        'estado' => 201,
    ],
    'panel.clients.show' => [
        'resumen' => 'Ficha del cliente con resumen de visitas',
        'descripcion' => 'resumen.total_cobrado solo con reportes.financieros o reservas.cobrar.',
    ],
    'panel.clients.bookings' => [
        'resumen' => 'Citas del cliente en esta barbería',
        'consulta' => ['tipo' => 'nullable|in:proximas,pasadas'],
        'paginado' => [10, 50],
    ],
    'panel.clients.update' => [
        'resumen' => 'Observaciones, bloqueo y datos de un cliente de mostrador',
        'descripcion' => 'Los datos de contacto solo se editan si el cliente no tiene cuenta propia. Un cliente bloqueado no puede reservar en línea en esta barbería.',
        'cuerpo' => [
            'notas_internas' => 'nullable|string|max:2000',
            'bloqueado' => 'nullable|boolean',
            'nombres' => 'nullable|string|min:2|max:100',
            'apellidos' => 'nullable|string|max:100',
            'telefono' => 'nullable|regex:/^\+?[0-9]{6,15}$/',
            'email' => 'nullable|email',
        ],
    ],

    // ---------------------------------------------------------------- avisos
    'panel.notifications.rules' => [
        'resumen' => 'Reglas de recordatorios y avisos de la barbería',
        'descripcion' => 'canales_plan indica qué canales incluye el plan: un canal fuera del plan no se envía aunque esté encendido.',
    ],
    'panel.notifications.rules.update' => [
        'resumen' => 'Cambiar las reglas de recordatorios y avisos',
        'cuerpo' => [
            'recordatorios_horas' => ['regla' => 'nullable|array|max:' . NotificationSettings::MAX_RECORDATORIOS, 'descripcion' => 'Horas antes de la cita (24 y 2 por defecto).', 'items' => 'integer|min:' . NotificationSettings::HORAS_MIN . '|max:' . NotificationSettings::HORAS_MAX],
            'pedir_confirmacion' => 'nullable|boolean',
            'aviso_confirmacion' => 'nullable|boolean',
            'aviso_cancelacion' => 'nullable|boolean',
            'aviso_modificacion' => 'nullable|boolean',
            'canal_push' => 'nullable|boolean',
            'canal_email' => 'nullable|boolean',
        ],
    ],
    'panel.notifications.metrics' => [
        'resumen' => 'Métricas de avisos: programados, enviados, fallos, confirmaciones e inasistencia',
        'consulta' => ['desde' => 'nullable|date', 'hasta' => 'nullable|date'],
    ],

    // ---------------------------------------------------------------- suscripción
    'panel.subscription' => [
        'resumen' => 'Plan actual, uso, planes disponibles, pagos y órdenes',
        'descripcion' => 'Con negocio.suscripcion.gestionar incluye además las acciones disponibles (pagar, cambiar de plan).',
    ],
    'panel.subscription.quote' => [
        'resumen' => 'Calcular el importe de un plan antes de pagar',
        'cuerpo' => ['plan_id' => 'required|integer|min:1', 'ciclo' => 'nullable|in:mensual,anual', 'periodos' => 'nullable|integer|min:1|max:12'],
    ],
    'panel.subscription.pay' => [
        'resumen' => 'Crear una orden de pago en línea y obtener la URL de pago',
        'descripcion' => 'Con Mercado Pago (o el simulador local con PAYMENT_DRIVER=sandbox). La suscripción se activa cuando llega el webhook confirmado, no al volver de la pasarela.',
        'cuerpo' => ['plan_id' => 'required|integer|min:1', 'ciclo' => 'required|in:mensual,anual', 'periodos' => 'nullable|integer|min:1|max:12'],
        'estado' => 201,
        'errores' => ['payments_disabled', 'gateway_error', 'tenant_pending', 'subscription_suspended'],
    ],
    'panel.subscription.change_plan' => [
        'resumen' => 'Cambiar de plan',
        'descripcion' => 'En prueba gratuita el cambio es inmediato; con un periodo pagado, bajar de plan o cambiar el ciclo se programa para el fin del periodo y subir se paga.',
        'cuerpo' => ['plan_id' => 'required|integer|min:1', 'ciclo' => 'required|in:mensual,anual'],
        'errores' => ['no_changes', 'payment_required', 'schedule_change', 'plan_limit_exceeded', 'no_subscription'],
    ],
    'panel.subscription.cancel_scheduled' => [
        'resumen' => 'Anular el cambio de plan programado',
        'errores' => ['no_scheduled_change'],
    ],
    'panel.subscription.order' => [
        'resumen' => 'Estado de una orden de pago (al volver de la pasarela)',
        'descripcion' => 'Si llega payment_id consulta el pago a la pasarela por si el webhook aún no llegó.',
        'consulta' => ['payment_id' => 'nullable|string|max:40', 'collection_id' => 'nullable|string|max:40'],
    ],
    'panel.subscription.simulate' => [
        'resumen' => 'Simulador local de pago (solo PAYMENT_DRIVER=sandbox)',
        'descripcion' => 'Genera el webhook firmado y lo procesa por el camino normal. En producción responde 404.',
        'cuerpo' => ['aprobar' => ['regla' => 'nullable|boolean', 'descripcion' => 'true por defecto; false simula un pago rechazado.']],
        'errores' => ['order_closed'],
    ],
];
