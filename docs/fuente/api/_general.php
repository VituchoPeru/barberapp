<?php

declare(strict_types=1);

/*
 * Datos generales de la especificación OpenAPI y etiquetas (grupos) de operaciones.
 * Cada etiqueta agrupa las rutas cuyo nombre empieza con alguno de sus prefijos
 * (gana el prefijo más largo). Ver docs/API.md.
 */
return [
    'info' => [
        'title' => 'API de BARBERAPP',
        'version' => '1.0.0',
        'summary' => 'API REST JSON de la plataforma de reservas para barberías.',
        'description' => "API REST versionada que usan la web, la PWA y las apps de Android e iOS.\n\n"
            . "- Todas las respuestas usan el mismo sobre: `{\"ok\": true, \"data\": …, \"meta\": {\"request_id\": …}}` o `{\"ok\": false, \"error\": {\"code\", \"message\", \"details\"}}`.\n"
            . "- Web: sesión con cookie HttpOnly y cabecera `X-CSRF-Token` en escrituras. App: `Authorization: Bearer` (15 min) y `POST /auth/refresh`.\n"
            . "- Las rutas del panel trabajan sobre una barbería (`X-Tenant` o la seleccionada en la sesión) y exigen el permiso indicado en cada operación.\n"
            . "- Las fechas de eventos van en UTC (ISO 8601 con `Z`); fechas y horas de agenda, en la hora local de la sucursal.\n"
            . "- Los importes son DECIMAL con 2 decimales en soles (PEN) salvo que se indique la moneda.\n\n"
            . 'Guía de uso, códigos de error y ejemplos: docs/API.md. Este archivo se genera con `php bin/console docs:generar`.',
        'contact' => ['name' => 'BARBERAPP'],
        'license' => ['name' => 'Propietaria (uso interno)', 'identifier' => 'LicenseRef-BARBERAPP'],
    ],
    'servidores' => [
        ['url' => 'https://api.barberapp.pe/api/v1', 'description' => 'Producción (dominio referencial, ver docs/DESPLIEGUE.md)'],
        ['url' => 'http://localhost/barberapp/backend/public/api/v1', 'description' => 'Local con XAMPP'],
        ['url' => 'http://127.0.0.1:8080/api/v1', 'description' => 'Local con php -S (ver README)'],
    ],
    'etiquetas' => [
        ['nombre' => 'Salud y sistema', 'rutas' => ['health', 'legal', 'webhooks'], 'descripcion' => 'Verificación de salud, textos legales y webhooks de la pasarela de pago.'],
        ['nombre' => 'Autenticación', 'rutas' => ['auth'], 'descripcion' => 'Registro, inicio y cierre de sesión, renovación de tokens, verificación de correo y recuperación de contraseña.'],
        ['nombre' => 'Mi cuenta', 'rutas' => ['me'], 'descripcion' => 'Perfil, barberías donde trabaja, sesiones, seguridad y derechos sobre los datos personales (Ley 29733).'],
        ['nombre' => 'Mis avisos', 'rutas' => ['me.notifications', 'me.notification_prefs', 'me.devices'], 'descripcion' => 'Bandeja de avisos, preferencias por canal y dispositivos para notificaciones push.'],
        ['nombre' => 'Favoritos', 'rutas' => ['me.favorites'], 'descripcion' => 'Barberías favoritas del cliente.'],
        ['nombre' => 'Marketplace', 'rutas' => ['marketplace'], 'descripcion' => 'Búsqueda pública de barberías, perfil público y código QR del enlace directo.'],
        ['nombre' => 'Reservas del cliente', 'rutas' => ['bookings', 'attendance'], 'descripcion' => 'Disponibilidad, reservar, mis citas, cancelar, reprogramar y confirmar asistencia (también desde el enlace del recordatorio).'],
        ['nombre' => 'Panel · Equipo y negocio', 'rutas' => ['panel.context', 'panel.staff', 'panel.team_settings', 'panel.business'], 'descripcion' => 'Contexto de la barbería, personal, ajustes del equipo y datos comerciales.'],
        ['nombre' => 'Panel · Perfil público', 'rutas' => ['panel.public_profile', 'panel.photos'], 'descripcion' => 'Datos del marketplace, fotos, logo y portada.'],
        ['nombre' => 'Panel · Catálogo', 'rutas' => ['panel.branches', 'panel.services', 'panel.categories', 'panel.barbers'], 'descripcion' => 'Sucursales, servicios, categorías, barberos, sus servicios, jornadas y fotos.'],
        ['nombre' => 'Panel · Reglas y bloqueos', 'rutas' => ['panel.booking_policy', 'panel.blocks'], 'descripcion' => 'Reglas de reserva por barbería o sucursal; vacaciones, ausencias, feriados y bloqueos de agenda.'],
        ['nombre' => 'Panel · Agenda', 'rutas' => ['panel.agenda', 'panel.availability', 'panel.bookings'], 'descripcion' => 'Agenda diaria y semanal, alta manual, estados de la cita, reprogramación, notas y cobros.'],
        ['nombre' => 'Panel · Clientes', 'rutas' => ['panel.clients'], 'descripcion' => 'Clientes vinculados a la barbería, fichas de mostrador e historial.'],
        ['nombre' => 'Panel · Reportes', 'rutas' => ['panel.dashboard', 'panel.reports'], 'descripcion' => 'Indicadores del día, reportes por periodo y exportación CSV, Excel o PDF.'],
        ['nombre' => 'Panel · Avisos', 'rutas' => ['panel.notifications'], 'descripcion' => 'Reglas de recordatorios y métricas de envío.'],
        ['nombre' => 'Panel · Suscripción', 'rutas' => ['panel.subscription'], 'descripcion' => 'Plan, uso, pago en línea y cambios de plan de la barbería.'],
        ['nombre' => 'Panel del barbero', 'rutas' => ['barber'], 'descripcion' => 'Agenda propia del barbero vinculado a la cuenta, atención, horario y ausencias.'],
        ['nombre' => 'Plataforma · General', 'rutas' => ['platform.context', 'platform.dashboard', 'platform.reports', 'platform.system', 'platform.settings', 'platform.audit'], 'descripcion' => 'Panel del superadministrador: indicadores globales, estado del sistema, configuración y auditoría.'],
        ['nombre' => 'Plataforma · Barberías', 'rutas' => ['platform.tenants'], 'descripcion' => 'Aprobar, rechazar, suspender y reactivar barberías.'],
        ['nombre' => 'Plataforma · Planes y cobros', 'rutas' => ['platform.plans', 'platform.subscriptions', 'platform.payments', 'platform.orders'], 'descripcion' => 'Planes SaaS, suscripciones, pagos manuales y órdenes de pago en línea.'],
        ['nombre' => 'Plataforma · Usuarios y avisos', 'rutas' => ['platform.users', 'platform.templates', 'platform.data_requests'], 'descripcion' => 'Equipo de plataforma, bloqueo de cuentas, plantillas de avisos y solicitudes de derechos ARCO.'],
    ],
];
