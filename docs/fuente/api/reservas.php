<?php

declare(strict_types=1);

use App\Repositories\MarketplaceRepository;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingService;

/* Fichas OpenAPI: marketplace público y reservas del cliente. */

$servicioIds = [
    'regla' => 'required|array|min:1|max:' . AvailabilityService::MAX_SERVICIOS,
    'descripcion' => 'Servicios de la cita, atendidos seguidos por el mismo barbero. En la consulta: "servicio_ids=3,5".',
    'items' => 'integer|min:1',
];
$fechas = [
    'fecha' => ['regla' => 'nullable|date', 'descripcion' => 'Día concreto: devuelve las horas libres de ese día.'],
    'desde' => ['regla' => 'nullable|date', 'descripcion' => 'Sin "fecha": resumen por día desde esta fecha (hoy por defecto).'],
    'hasta' => ['regla' => 'nullable|date', 'descripcion' => 'Fin del resumen (desde + 13 días por defecto; máximo ' . AvailabilityService::MAX_DIAS_CONSULTA . ' días).'],
];
$disponibilidad = ['descripcion' => 'Con "fecha": horarios[] con hora local, inicio_utc y barberos libres. Sin ella: dias[] con cantidad de horarios y primera hora.', 'datos' => [
    'sucursal_id' => 'required|integer',
    'zona_horaria' => 'required|string',
    'hoy' => 'required|date',
    'reservable_hasta' => 'required|date',
    'intervalo_minutos' => 'required|integer',
    'duracion_minutos' => ['regla' => 'required|object', 'campos' => ['min' => 'required|integer', 'max' => 'required|integer']],
    'precio' => ['regla' => 'required|object', 'campos' => ['min' => 'required|decimal', 'max' => 'required|decimal']],
    'barberos' => ['regla' => 'required|array', 'items' => ['id' => 'required|integer', 'nombre' => 'required|string', 'duracion_minutos' => 'required|integer', 'precio' => 'required|decimal']],
    'horarios' => ['regla' => 'nullable|array', 'items' => ['hora' => 'required|time', 'inicio_utc' => 'required|datetime', 'barberos' => ['regla' => 'required|array', 'items' => 'integer']]],
    'dias' => ['regla' => 'nullable|array', 'items' => ['fecha' => 'required|date', 'reservable' => 'required|boolean', 'disponibles' => 'required|integer', 'primera_hora' => 'nullable|time']],
]];
$reserva = ['descripcion' => 'La reserva presentada para el cliente.', 'datos' => [
    'id' => 'required|integer',
    'codigo' => ['regla' => 'required|string', 'descripcion' => 'Código corto para el comprobante.'],
    'estado' => 'required|in:pendiente,confirmada,completada,cancelada,no_asistio',
    'barberia' => 'required|object',
    'sucursal' => 'required|object',
    'barbero' => 'required|object',
    'barbero_asignado_auto' => ['regla' => 'required|boolean', 'descripcion' => 'true si se eligió "cualquier barbero".'],
    'servicios' => ['regla' => 'required|array', 'items' => ['id' => 'required|integer', 'nombre' => 'required|string', 'duracion_minutos' => 'required|integer', 'precio' => 'required|decimal', 'hora_inicio' => 'required|time', 'hora_fin' => 'required|time']],
    'fecha' => 'required|date',
    'hora_inicio' => 'required|time',
    'hora_fin' => 'required|time',
    'zona_horaria' => 'required|string',
    'inicio_utc' => 'required|datetime',
    'fin_utc' => 'required|datetime',
    'precio_total' => ['regla' => 'required|decimal', 'descripcion' => 'Importe previsto (no es un cobro).'],
    'moneda' => 'required|string',
    'asistencia_confirmada' => 'required|boolean',
    'reprogramaciones' => 'required|integer',
    'acciones' => ['regla' => 'required|object', 'descripcion' => 'Qué puede hacer el cliente ahora (cancelar, reprogramar, confirmar) según la política.'],
    'politica' => 'required|object',
]];
$propia = 'La cita debe ser de la cuenta autenticada; si es de otra persona responde 404 y queda en la auditoría.';

return [
    // ---------------------------------------------------------------- marketplace
    'marketplace.filters' => [
        'resumen' => 'Opciones reales para los filtros del buscador',
        'descripcion' => 'Distritos con barberías publicadas, tipos de servicio y rango de precios (calculados de los datos, nunca inventados).',
    ],
    'marketplace.search' => [
        'resumen' => 'Buscar barberías publicadas',
        'descripcion' => 'Solo barberías activas, publicadas, con sucursal y servicio activos y sin restricción por suscripción. La distancia se calcula solo si se envían lat y lng (ubicación que la persona autorizó).',
        'consulta' => [
            'q' => ['regla' => 'nullable|string|max:80', 'descripcion' => 'Nombre de la barbería o del servicio.'],
            'distrito' => 'nullable|string|max:80',
            'tipo' => ['regla' => 'nullable|regex:/^[a-z0-9_]{1,40}$/', 'descripcion' => 'Código de tipo de servicio (ver /marketplace/filtros).'],
            'precio_min' => 'nullable|decimal|min:0|max:100000',
            'precio_max' => 'nullable|decimal|min:0|max:100000',
            'lat' => ['regla' => 'nullable|regex:/^-?\d{1,2}(\.\d{1,8})?$/', 'descripcion' => 'Latitud (junto con lng).'],
            'lng' => 'nullable|regex:/^-?\d{1,3}(\.\d{1,8})?$/',
            'radio_km' => 'nullable|decimal|min:0.5|max:50',
            'orden' => ['regla' => 'nullable|in:' . implode(',', MarketplaceRepository::ORDENES), 'descripcion' => '"distancia" exige lat y lng.'],
        ],
        'paginado' => [12, 48],
        'respuesta' => 'Tarjetas de barberías; meta trae page, per_page, total, pages y con_ubicacion. Con sesión, cada tarjeta indica si es favorita.',
    ],
    'marketplace.show' => [
        'resumen' => 'Perfil público de una barbería',
        'descripcion' => 'Servicios activos con precio y duración, equipo, sucursales con horario y ubicación, fotos, contacto y redes. Con sesión indica si es favorita.',
    ],
    'marketplace.qr' => [
        'resumen' => 'Código QR del enlace directo /b/{slug}',
        'descripcion' => 'El QR apunta a {PUBLIC_BOOKING_URL}/b/{slug}?o=qr para distinguir en las reservas a quienes llegaron escaneándolo.',
        'consulta' => [
            'formato' => 'nullable|in:svg,png',
            'tam' => ['regla' => 'nullable|integer|min:4|max:20', 'descripcion' => 'Solo PNG: píxeles por módulo (12 por defecto).'],
            'descargar' => ['regla' => 'nullable|boolean', 'descripcion' => '1 = Content-Disposition: attachment.'],
        ],
        'produce' => ['image/svg+xml' => 'QR en SVG (por defecto).', 'image/png' => 'QR en PNG.'],
        'respuesta' => 'Imagen del código QR.',
    ],

    // ---------------------------------------------------------------- reservas del cliente
    'bookings.availability' => [
        'resumen' => 'Horarios disponibles de una barbería',
        'descripcion' => 'Pública. Considera jornada y descansos del barbero, bloqueos, vacaciones, reservas activas, duración total, intervalo, margen, anticipación mínima, horizonte y zona horaria de la sucursal. Sin barbero_id usa a todos los que realizan los servicios (si la barbería lo permite).',
        'consulta' => [
            'slug' => 'required|slug|max:80',
            'sucursal_id' => 'required|integer|min:1',
            'servicio_ids' => $servicioIds,
            'barbero_id' => ['regla' => 'nullable|integer|min:1', 'descripcion' => 'Omitir = cualquier barbero disponible.'],
        ] + $fechas,
        'errores' => ['no_barber_for_services'],
        'respuesta' => $disponibilidad,
    ],
    'bookings.store' => [
        'resumen' => 'Reservar una cita',
        'descripcion' => 'Bloquea la agenda del barbero con SELECT … FOR UPDATE y vuelve a comprobar el horario dentro de la transacción: dos personas no pueden tomar el mismo intervalo (la segunda recibe 409 slot_unavailable con alternativas). Con "cualquier barbero" se asigna el que tiene menos citas ese día.',
        'cuerpo' => [
            'slug' => 'required|slug|max:80',
            'sucursal_id' => 'required|integer|min:1',
            'servicio_ids' => $servicioIds,
            'barbero_id' => ['regla' => 'nullable|integer|min:1', 'descripcion' => 'Omitir = cualquier barbero disponible.'],
            'fecha' => ['regla' => 'required|date', 'descripcion' => 'Fecha local de la sucursal.'],
            'hora' => 'required|time',
            'notas' => 'nullable|string|max:500',
            'canal' => ['regla' => 'nullable|in:' . implode(',', BookingService::CANALES), 'descripcion' => 'Cómo llegó el cliente (para reportes).'],
        ],
        'idempotente' => true,
        'estado' => 201,
        'errores' => ['slot_unavailable', 'too_soon', 'too_far', 'active_bookings_limit', 'client_blocked', 'client_overlap', 'no_barber_for_services', 'tenant_unavailable', 'idempotency_in_progress', 'idempotency_key_reused', 'idempotency_key_invalid'],
        'respuesta' => $reserva,
    ],
    'bookings.mine' => [
        'resumen' => 'Mis citas en todas las barberías',
        'descripcion' => 'Con el correo verificado vincula también las citas que la barbería registró en mostrador con ese correo.',
        'consulta' => ['tipo' => ['regla' => 'nullable|in:proximas,pasadas', 'descripcion' => 'proximas por defecto.']],
        'paginado' => [20, 50],
        'respuesta' => 'Lista de reservas con el mismo formato que el detalle (sin historial).',
    ],
    'bookings.mine.show' => [
        'resumen' => 'Detalle de mi cita con su historial',
        'descripcion' => $propia,
        'respuesta' => $reserva,
    ],
    'bookings.mine.availability' => [
        'resumen' => 'Horarios para reprogramar mi cita',
        'descripcion' => 'Igual que /disponibilidad, con los mismos servicios y sin contar la propia cita como ocupada. ' . $propia,
        'consulta' => $fechas,
        'errores' => ['tenant_unavailable'],
        'respuesta' => $disponibilidad,
    ],
    'bookings.mine.cancel' => [
        'resumen' => 'Cancelar mi cita',
        'descripcion' => 'Respeta el plazo de cancelación de la barbería. El horario queda libre al confirmar la transacción y se anulan los recordatorios pendientes. ' . $propia,
        'cuerpo' => ['motivo' => 'nullable|string|max:255'],
        'errores' => ['cancellation_window_closed', 'booking_started', 'booking_not_active'],
        'respuesta' => $reserva,
    ],
    'bookings.mine.reschedule' => [
        'resumen' => 'Reprogramar mi cita',
        'descripcion' => 'Valida el nuevo horario con el mismo bloqueo que una reserva nueva y mantiene ocupado el anterior hasta confirmar el cambio. Queda registrado como evento en el historial. ' . $propia,
        'cuerpo' => [
            'fecha' => 'required|date',
            'hora' => 'required|time',
            'barbero_id' => ['regla' => 'nullable|integer|min:1', 'descripcion' => 'Cambiar de barbero (si la barbería lo permite).'],
        ],
        'idempotente' => true,
        'errores' => ['slot_unavailable', 'same_slot', 'reschedule_window_closed', 'reschedule_limit', 'too_soon', 'too_far', 'idempotency_in_progress', 'idempotency_key_reused'],
        'respuesta' => $reserva,
    ],
    'bookings.mine.confirm' => [
        'resumen' => 'Confirmar que asistiré',
        'descripcion' => 'Idempotente. ' . $propia,
        'errores' => ['booking_not_active'],
        'respuesta' => $reserva,
    ],
];
