<?php

declare(strict_types=1);

use App\Repositories\AgendaBlockRepository;
use App\Services\Barber\BarberPanelService;
use App\Services\Schedule\AgendaBlockService;
use App\Services\Schedule\WeeklySchedule;

/* Fichas OpenAPI: panel del barbero (/barbero). Todo se limita a la ficha vinculada a la cuenta. */

$propia = 'Solo citas del barbero vinculado a la cuenta; otra cita responde 404. Sin ficha vinculada: 403 no_barber_profile.';
$cita = 'La cita con cliente, servicios, estado, notas y acciones disponibles.';
$transicion = fn (string $que) => [
    'resumen' => $que,
    'descripcion' => $propia,
    'errores' => ['no_barber_profile', 'booking_not_active', 'too_early', 'attention_started', 'booking_has_payments'],
    'respuesta' => $cita,
];

return [
    'barber.day' => [
        'resumen' => 'Mi agenda del día: jornada, cita en atención y siguiente cliente',
        'consulta' => ['fecha' => ['regla' => 'nullable|date', 'descripcion' => 'Hoy por defecto (hora local).']],
        'errores' => ['no_barber_profile'],
    ],
    'barber.upcoming' => [
        'resumen' => 'Mis próximas citas activas',
        'consulta' => ['dias' => ['regla' => 'nullable|integer|min:1|max:' . BarberPanelService::MAX_DIAS_PROXIMAS, 'descripcion' => '14 por defecto.']],
        'errores' => ['no_barber_profile'],
    ],
    'barber.history' => [
        'resumen' => 'Mis citas pasadas',
        'descripcion' => 'No incluye importes cobrados ni datos de otros barberos.',
        'consulta' => [
            'desde' => ['regla' => 'nullable|date', 'descripcion' => 'hasta − 29 días por defecto.'],
            'hasta' => 'nullable|date',
            'estado' => 'nullable|in:completada,no_asistio,cancelada,pendiente,confirmada',
        ],
        'paginado' => [20, 50],
        'errores' => ['no_barber_profile'],
    ],
    'barber.bookings.show' => [
        'resumen' => 'Detalle de una cita y visitas anteriores del cliente conmigo',
        'descripcion' => $propia,
        'errores' => ['no_barber_profile'],
        'respuesta' => $cita,
    ],
    'barber.bookings.confirm' => $transicion('Confirmar la cita'),
    'barber.bookings.start' => $transicion('Iniciar la atención'),
    'barber.bookings.complete' => $transicion('Marcar como atendido'),
    'barber.bookings.no_show' => $transicion('Marcar inasistencia'),
    'barber.bookings.notes' => [
        'resumen' => 'Guardar notas internas de la cita',
        'descripcion' => $propia,
        'cuerpo' => ['notas_internas' => 'nullable|string|max:1000'],
        'errores' => ['no_barber_profile'],
        'respuesta' => $cita,
    ],
    'barber.schedule' => [
        'resumen' => 'Mi jornada semanal, descansos y jornada efectiva por fecha',
        'consulta' => [
            'desde' => ['regla' => 'nullable|date', 'descripcion' => 'Hoy por defecto.'],
            'dias' => ['regla' => 'nullable|integer|min:1|max:' . BarberPanelService::MAX_DIAS_HORARIO, 'descripcion' => '14 por defecto.'],
        ],
        'errores' => ['no_barber_profile'],
    ],
    'barber.schedule.update' => [
        'resumen' => 'Cambiar mi jornada semanal',
        'descripcion' => 'Solo si la barbería activó "el barbero puede cambiar su jornada" (o con horarios.gestionar). Mismo formato que PUT /panel/barberos/{id}/horario.',
        'cuerpo' => [
            'tramos' => ['regla' => 'required|array|max:' . WeeklySchedule::MAX_TRAMOS, 'items' => [
                'dia_semana' => 'required|integer|min:1|max:7',
                'hora_inicio' => 'required|time',
                'hora_fin' => 'required|time',
                'sucursal_id' => 'required|integer|min:1',
                'vigente_desde' => 'nullable|date',
                'vigente_hasta' => 'nullable|date',
            ]],
            'descansos' => ['regla' => 'nullable|array|max:' . WeeklySchedule::MAX_DESCANSOS, 'items' => [
                'dia_semana' => 'required|integer|min:1|max:7',
                'hora_inicio' => 'required|time',
                'hora_fin' => 'required|time',
                'motivo' => 'nullable|string|max:80',
            ]],
        ],
        'errores' => ['no_barber_profile', 'schedule_locked'],
    ],
    'barber.blocks' => [
        'resumen' => 'Mis solicitudes de ausencia y bloqueos',
        'consulta' => ['estado' => 'nullable|in:' . implode(',', AgendaBlockRepository::ESTADOS)],
        'errores' => ['no_barber_profile'],
    ],
    'barber.blocks.request' => [
        'resumen' => 'Pedir una ausencia o bloqueo de mi agenda',
        'descripcion' => 'Si la barbería exige aprobación queda "solicitado" (el horario se sigue ofreciendo hasta que el encargado la apruebe); si no, queda aprobado al instante.',
        'cuerpo' => [
            'tipo' => 'required|in:' . implode(',', AgendaBlockService::TIPOS_BARBERO),
            'sucursal_id' => 'nullable|integer|min:1',
            'dia_completo' => 'nullable|boolean',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
            'inicio' => ['regla' => 'nullable|regex:/^\d{4}-\d{2}-\d{2}[T ][0-2]\d:[0-5]\d$/', 'descripcion' => 'Fecha y hora local (AAAA-MM-DD HH:MM).'],
            'fin' => 'nullable|regex:/^\d{4}-\d{2}-\d{2}[T ][0-2]\d:[0-5]\d$/',
            'motivo' => 'nullable|string|max:255',
        ],
        'estado' => 201,
        'errores' => ['no_barber_profile', 'block_overlaps', 'block_conflicts_bookings'],
    ],
    'barber.blocks.withdraw' => [
        'resumen' => 'Retirar una solicitud pendiente',
        'sin_contenido' => true,
        'errores' => ['no_barber_profile', 'block_not_pending'],
    ],
];
