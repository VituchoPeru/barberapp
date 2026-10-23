<?php

declare(strict_types=1);

use App\Services\Notifications\NotificationSettings;
use App\Services\Platform\PlatformSettings;
use App\Services\Platform\PlanService;
use App\Services\Platform\SubscriptionService;
use App\Services\Platform\TenantAdminService;
use App\Services\Privacy\DataRequests;

/*
 * Fichas OpenAPI: panel del superadministrador (/plataforma). Nunca expone clientes ni
 * reservas individuales de las barberías: solo cifras agregadas.
 */

$motivo = ['motivo' => 'required|string|min:5|max:255'];
$plan = fn (string $req) => [
    'nombre' => $req . 'string|max:80',
    'descripcion' => 'nullable|string|max:500',
    'precio_mensual' => $req . 'decimal|min:0|max:99999.99',
    'precio_anual' => ['regla' => 'nullable|decimal|min:0|max:999999.99', 'descripcion' => 'No puede superar 12 meses del precio mensual.'],
    'moneda' => 'nullable|string|in:PEN,USD',
    'dias_prueba' => 'nullable|integer|min:0|max:90',
    'max_sucursales' => ['regla' => 'nullable|integer|min:1|max:1000', 'descripcion' => 'null = sin límite.'],
    'max_barberos' => ['regla' => 'nullable|integer|min:1|max:10000', 'descripcion' => 'null = sin límite.'],
    'caracteristicas' => ['regla' => 'nullable|object', 'campos' => [
        'reportes_avanzados' => 'nullable|boolean',
        'personalizacion' => ['regla' => 'nullable|boolean', 'descripcion' => 'Colores propios de la barbería.'],
        'canales' => ['regla' => 'nullable|array', 'descripcion' => 'Canales de aviso incluidos ("interna" siempre se agrega).', 'items' => 'in:' . implode(',', PlanService::CANALES)],
    ]],
    'publico' => ['regla' => 'nullable|boolean', 'descripcion' => 'Se ofrece a las barberías.'],
    'activo' => 'nullable|boolean',
    'orden' => 'nullable|integer|min:0|max:999',
];
$parametros = [];
foreach (PlatformSettings::CLAVES as $clave => [$tipo, $defecto, $desc]) {
    $parametros[$clave] = ['regla' => 'nullable', 'descripcion' => "{$desc} (tipo {$tipo})."];
}
$avisos = [];
foreach (NotificationSettings::AJUSTES as $clave => [$defecto, $desc]) {
    $avisos[$clave] = ['regla' => is_bool($defecto) ? 'nullable|boolean' : 'nullable|array', 'descripcion' => $desc . '.'];
}
$suscripcion = 'La suscripción con plan, estado, periodo, precio pactado, eventos y pagos.';

return [
    'platform.context' => [
        'resumen' => 'Mi rol y permisos de plataforma',
        'respuesta' => ['datos' => ['usuario' => 'required|object', 'rol' => 'required|object', 'permisos' => ['regla' => 'required|array', 'items' => 'string'], 'plataforma' => 'required|object']],
    ],
    'platform.dashboard' => [
        'resumen' => 'Indicadores globales del SaaS',
        'descripcion' => 'Barberías registradas, activas y suspendidas; suscripciones activas y vencidas; MRR, churn y nuevas altas calculados de los datos reales; reservas agregadas y consumo por plan. Ver docs/SUPERADMIN.md.',
        'consulta' => ['excluir_demo' => ['regla' => 'nullable|in:0,1', 'descripcion' => '1 = sin barberías de demostración.']],
    ],
    'platform.reports' => [
        'resumen' => 'Serie mensual de indicadores (o CSV)',
        'consulta' => [
            'meses' => 'nullable|integer|min:1|max:24',
            'excluir_demo' => 'nullable|in:0,1',
            'formato' => ['regla' => 'nullable|in:csv', 'descripcion' => 'csv = descarga (queda en la auditoría).'],
        ],
        'produce' => ['application/json' => 'Sobre JSON con meses[] (sin formato).', 'text/csv' => 'Con formato=csv.'],
        'respuesta' => 'Reporte global por mes.',
    ],
    'platform.system' => [
        'resumen' => 'Estado del sistema: cola de avisos, envíos fallidos y errores de integraciones',
    ],
    'platform.tenants' => [
        'resumen' => 'Barberías registradas',
        'consulta' => [
            'q' => ['regla' => 'nullable|string|max:80', 'descripcion' => 'Nombre, slug, RUC o correo del propietario.'],
            'estado' => 'nullable|in:' . implode(',', TenantAdminService::ESTADOS),
            'plan' => ['regla' => 'nullable|string|max:40', 'descripcion' => 'Código del plan.'],
            'demo' => 'nullable|in:0,1',
        ],
        'paginado' => [20, 100],
    ],
    'platform.tenants.show' => ['resumen' => 'Ficha de una barbería: propietario, suscripción, uso y eventos'],
    'platform.tenants.update' => [
        'resumen' => 'Marcar como demostración o guardar una nota interna',
        'cuerpo' => ['es_demo' => 'nullable|boolean', 'nota_interna' => 'nullable|string|max:500'],
    ],
    'platform.tenants.approve' => [
        'resumen' => 'Aprobar una barbería pendiente',
        'descripcion' => 'Con la prueba automática activada, empieza la prueba gratuita. Avisa al propietario.',
        'errores' => ['invalid_tenant_state'],
    ],
    'platform.tenants.reject' => ['resumen' => 'Rechazar una barbería pendiente', 'cuerpo' => $motivo, 'errores' => ['invalid_tenant_state']],
    'platform.tenants.suspend' => [
        'resumen' => 'Suspender una barbería',
        'descripcion' => 'Sale del marketplace y no recibe reservas nuevas; las citas existentes se conservan.',
        'cuerpo' => $motivo,
        'errores' => ['invalid_tenant_state'],
    ],
    'platform.tenants.reactivate' => ['resumen' => 'Reactivar una barbería suspendida', 'errores' => ['invalid_tenant_state', 'tenant_cancelled']],

    'platform.plans' => ['resumen' => 'Planes SaaS con su uso'],
    'platform.plans.store' => [
        'resumen' => 'Crear un plan',
        'cuerpo' => ['codigo' => 'required|slug|min:3|max:40'] + $plan('required|'),
        'estado' => 201,
    ],
    'platform.plans.update' => [
        'resumen' => 'Modificar un plan (precios, límites, funciones)',
        'descripcion' => 'Cambiar el precio no cambia el precio pactado de las suscripciones existentes. Debe quedar al menos un plan activo.',
        'cuerpo' => $plan(''),
        'errores' => ['last_active_plan'],
    ],

    'platform.subscriptions' => [
        'resumen' => 'Suscripciones',
        'consulta' => [
            'estado' => ['regla' => 'nullable|in:' . implode(',', SubscriptionService::ESTADOS), 'descripcion' => 'prueba y activa = vigentes; vencida incluye las que pasaron su fin de periodo.'],
            'plan' => 'nullable|string|max:40',
            'q' => 'nullable|string|max:80',
            'vence_en_dias' => ['regla' => 'nullable|integer|min:1|max:365', 'descripcion' => 'Vigentes que vencen dentro de N días.'],
            'tenant_id' => 'nullable|integer|min:1',
            'historial' => ['regla' => 'nullable|in:0,1', 'descripcion' => '1 = incluye suscripciones anteriores (no solo la vigente).'],
        ],
        'paginado' => [25, 100],
    ],
    'platform.subscriptions.store' => [
        'resumen' => 'Activar una suscripción manualmente',
        'cuerpo' => [
            'tenant_id' => 'required|integer|min:1',
            'plan_id' => 'required|integer|min:1',
            'estado' => 'required|in:prueba,activa',
            'ciclo' => 'nullable|in:mensual,anual',
            'precio_pactado' => ['regla' => 'nullable|decimal|min:0|max:999999.99', 'descripcion' => 'Precio del plan por defecto.'],
            'dias_prueba' => 'nullable|integer|min:1|max:90',
            'periodos' => 'nullable|integer|min:1|max:24',
            'notas' => 'nullable|string|max:500',
        ],
        'estado' => 201,
        'errores' => ['subscription_exists', 'tenant_cancelled'],
        'respuesta' => $suscripcion,
    ],
    'platform.subscriptions.show' => ['resumen' => 'Detalle de una suscripción', 'respuesta' => $suscripcion],
    'platform.subscriptions.change_plan' => [
        'resumen' => 'Cambiar el plan de una suscripción',
        'cuerpo' => ['plan_id' => 'required|integer|min:1', 'ciclo' => 'nullable|in:mensual,anual', 'precio_pactado' => 'nullable|decimal|min:0|max:999999.99', 'notas' => 'nullable|string|max:500'],
        'errores' => ['invalid_subscription_state', 'plan_limit_exceeded'],
        'respuesta' => $suscripcion,
    ],
    'platform.subscriptions.renew' => [
        'resumen' => 'Renovar por N periodos',
        'cuerpo' => ['periodos' => 'required|integer|min:1|max:24', 'notas' => 'nullable|string|max:500'],
        'errores' => ['invalid_subscription_state'],
        'respuesta' => $suscripcion,
    ],
    'platform.subscriptions.suspend' => ['resumen' => 'Suspender la suscripción', 'cuerpo' => $motivo, 'errores' => ['invalid_subscription_state'], 'respuesta' => $suscripcion],
    'platform.subscriptions.reactivate' => [
        'resumen' => 'Reactivar una suscripción suspendida (con el tiempo que le quedaba)',
        'cuerpo' => ['notas' => 'nullable|string|max:500'],
        'errores' => ['invalid_subscription_state', 'period_ended'],
        'respuesta' => $suscripcion,
    ],
    'platform.subscriptions.cancel' => ['resumen' => 'Cancelar la suscripción', 'cuerpo' => $motivo, 'errores' => ['invalid_subscription_state'], 'respuesta' => $suscripcion],

    'platform.payments' => [
        'resumen' => 'Pagos de suscripciones',
        'consulta' => [
            'estado' => 'nullable|in:pendiente,confirmado,rechazado,reembolsado',
            'metodo' => 'nullable|in:' . implode(',', array_merge(SubscriptionService::METODOS, ['pasarela'])),
            'tenant_id' => 'nullable|integer|min:1',
            'suscripcion_id' => 'nullable|integer|min:1',
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date',
            'q' => 'nullable|string|max:80',
        ],
        'paginado' => [25, 100],
    ],
    'platform.orders' => [
        'resumen' => 'Órdenes de pago en línea',
        'consulta' => [
            'estado' => 'nullable|in:pendiente,pagada,fallida,expirada,anulada',
            'tenant_id' => 'nullable|integer|min:1',
            'por_revisar' => ['regla' => 'nullable|in:0,1', 'descripcion' => '1 = pagos que llegaron pero no se pudieron aplicar.'],
        ],
        'paginado' => [25, 100],
    ],
    'platform.payments.store' => [
        'resumen' => 'Registrar un pago manual (transferencia, Yape, Plin, efectivo)',
        'descripcion' => 'Confirmado, extiende la suscripción los periodos indicados (1 por defecto; 0 = no extiende).',
        'cuerpo' => [
            'monto' => 'required|decimal|min:0.01|max:999999.99',
            'metodo' => 'required|in:' . implode(',', SubscriptionService::METODOS),
            'referencia' => 'nullable|string|max:120',
            'estado' => ['regla' => 'nullable|in:confirmado,pendiente', 'descripcion' => 'confirmado por defecto.'],
            'pagado_en' => 'nullable|date',
            'periodos' => 'nullable|integer|min:0|max:24',
            'notas' => 'nullable|string|max:500',
        ],
        'estado' => 201,
        'errores' => ['duplicate_reference', 'invalid_subscription_state'],
    ],
    'platform.payments.confirm' => [
        'resumen' => 'Confirmar un pago pendiente',
        'cuerpo' => ['periodos' => 'nullable|integer|min:0|max:24', 'pagado_en' => 'nullable|date'],
        'errores' => ['invalid_payment_state'],
    ],
    'platform.payments.reject' => ['resumen' => 'Rechazar un pago pendiente', 'cuerpo' => $motivo, 'errores' => ['invalid_payment_state']],
    'platform.payments.refund' => ['resumen' => 'Registrar el reembolso de un pago confirmado', 'cuerpo' => $motivo, 'errores' => ['invalid_payment_state']],

    'platform.users' => ['resumen' => 'Equipo de BARBERAPP (superadministradores y operadores)'],
    'platform.users.search' => [
        'resumen' => 'Buscar cuentas por correo o nombre',
        'descripcion' => 'Mínimo 3 caracteres; devuelve hasta 20 cuentas. La búsqueda queda en la auditoría.',
        'consulta' => ['q' => 'required|string|min:3|max:120'],
    ],
    'platform.users.show' => [
        'resumen' => 'Ficha de una cuenta (sin reservas ni datos de clientes)',
        'descripcion' => 'Membresías, estado, último acceso y sesiones activas. La consulta queda en la auditoría.',
    ],
    'platform.users.block' => [
        'resumen' => 'Bloquear una cuenta',
        'descripcion' => 'Cierra todas sus sesiones al instante.',
        'cuerpo' => $motivo,
        'errores' => ['already_blocked', 'cannot_block_self', 'last_superadmin'],
    ],
    'platform.users.unblock' => ['resumen' => 'Desbloquear una cuenta', 'errores' => ['not_blocked']],
    'platform.users.role' => [
        'resumen' => 'Dar o quitar un rol de plataforma',
        'cuerpo' => ['rol' => ['regla' => 'nullable|in:superadmin,operador', 'descripcion' => 'null = quitar el rol.']],
        'errores' => ['cannot_change_own_role', 'last_superadmin', 'role_missing'],
    ],

    'platform.templates' => ['resumen' => 'Plantillas de avisos por código y canal'],
    'platform.templates.save' => [
        'resumen' => 'Cambiar el texto de una plantilla',
        'descripcion' => 'Admite variables {{nombre}} del aviso (ver la lista en GET /plataforma/plantillas).',
        'cuerpo' => ['asunto' => 'required|string', 'cuerpo' => 'required|string'],
    ],
    'platform.templates.restore' => ['resumen' => 'Volver al texto por defecto'],
    'platform.templates.preview' => [
        'resumen' => 'Vista previa con datos de ejemplo (no guarda)',
        'cuerpo' => ['asunto' => 'required|string', 'cuerpo' => 'required|string'],
    ],

    'platform.audit' => [
        'resumen' => 'Registro de auditoría',
        'descripcion' => 'Paginado por cursor: meta.siguiente es el valor de antes_de para la página siguiente.',
        'consulta' => [
            'accion' => ['regla' => 'nullable|regex:/^[a-z0-9_.]{2,100}$/', 'descripcion' => 'Prefijo de la acción, p. ej. auth.'],
            'resultado' => 'nullable|in:exito,denegado,error',
            'tenant' => ['regla' => 'nullable|string|max:80', 'descripcion' => 'Slug de la barbería.'],
            'usuario' => ['regla' => 'nullable|string|max:190', 'descripcion' => 'Correo de la cuenta.'],
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date',
            'antes_de' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:10|max:100',
        ],
    ],
    'platform.audit.actions' => ['resumen' => 'Acciones registradas (para el filtro)'],
    'platform.settings' => ['resumen' => 'Parámetros generales y avisos por defecto'],
    'platform.settings.update' => [
        'resumen' => 'Cambiar parámetros generales y avisos por defecto',
        'descripcion' => 'Envía solo lo que cambia. Los avisos son los valores globales; cada barbería puede cambiarlos en su panel.',
        'cuerpo' => [
            'parametros' => ['regla' => 'nullable|object', 'campos' => $parametros],
            'avisos' => ['regla' => 'nullable|object', 'campos' => $avisos],
        ],
    ],

    'platform.data_requests' => [
        'resumen' => 'Solicitudes de derechos sobre datos personales',
        'descripcion' => 'Con el plazo de respuesta de cada una y los datos del responsable. La consulta queda en la auditoría.',
        'consulta' => ['estado' => ['regla' => 'nullable|in:abiertas,' . implode(',', DataRequests::ESTADOS), 'descripcion' => 'abiertas = recibida o en_proceso.']],
    ],
    'platform.data_requests.respond' => [
        'resumen' => 'Responder una solicitud',
        'descripcion' => 'La persona recibe la respuesta por correo y en su bandeja.',
        'cuerpo' => [
            'estado' => 'required|in:en_proceso,atendida,rechazada',
            'respuesta' => ['regla' => 'nullable|string|max:2000', 'descripcion' => 'Obligatoria (10 a 2000 caracteres) al atender o rechazar.'],
        ],
        'errores' => ['request_closed'],
    ],
];
