<?php

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AgendaBlocksController;
use App\Controllers\AttendanceController;
use App\Controllers\AuthController;
use App\Controllers\BarberPanelController;
use App\Controllers\BarbersController;
use App\Controllers\BookingPolicyController;
use App\Controllers\BookingsController;
use App\Controllers\BranchesController;
use App\Controllers\BusinessController;
use App\Controllers\FavoritesController;
use App\Controllers\HealthController;
use App\Controllers\LegalController;
use App\Controllers\MarketplaceController;
use App\Controllers\MeController;
use App\Controllers\NotificationsController;
use App\Controllers\PanelAgendaController;
use App\Controllers\PanelBillingController;
use App\Controllers\PanelClientsController;
use App\Controllers\PanelNotificationsController;
use App\Controllers\PanelReportsController;
use App\Controllers\PaymentWebhookController;
use App\Controllers\PlatformBillingController;
use App\Controllers\PlatformController;
use App\Controllers\PlatformPrivacyController;
use App\Controllers\PlatformTenantsController;
use App\Controllers\PlatformUsersController;
use App\Controllers\PublicProfileController;
use App\Controllers\ServicesController;
use App\Controllers\StaffController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;
use App\Middleware\PlatformMiddleware;
use App\Middleware\TenantContextMiddleware;
use App\Middleware\VerifiedEmailMiddleware;

/*
 * Rutas de la API REST v1.
 * Cada fase agrega su grupo: auth, me, marketplace, reservas, panel, barbero y plataforma (Fase 9).
 */
return static function (Router $router): void {
    $router->group('/api/v1', static function (Router $r): void {
        $r->get('/health', [HealthController::class, 'show'], [], 'health');
        $r->get('/health/live', [HealthController::class, 'live'], [], 'health.live');

        // ---------------- Textos legales y responsable del tratamiento (Fase 13): público
        $r->get('/legal', [LegalController::class, 'show'], [], 'legal');

        // ---------------- Webhooks de la pasarela de pago (Fase 10): públicos, autenticados por firma
        $r->post('/webhooks/pagos/{proveedor:[a-z]{3,20}}', [PaymentWebhookController::class, 'handle'], [], 'webhooks.payments');

        // ---------------- Autenticación (públicas, con limitación de intentos)
        $r->group('/auth', static function (Router $r): void {
            $r->post('/register', [AuthController::class, 'register'], [], 'auth.register');
            $r->post('/login', [AuthController::class, 'login'], [], 'auth.login');
            $r->post('/refresh', [AuthController::class, 'refresh'], [], 'auth.refresh');
            $r->post('/forgot-password', [AuthController::class, 'forgotPassword'], [], 'auth.forgot');
            $r->post('/reset-password', [AuthController::class, 'resetPassword'], [], 'auth.reset');
            $r->post('/verify-email', [AuthController::class, 'verifyEmail'], [], 'auth.verify');
            $r->post('/confirm-email', [AccountController::class, 'confirmEmail'], [], 'auth.confirm_email');

            $r->post('/logout', [AuthController::class, 'logout'], [new AuthMiddleware()], 'auth.logout');
            $r->get('/csrf', [AuthController::class, 'csrf'], [new AuthMiddleware()], 'auth.csrf');
            $r->post('/verify-email/resend', [AuthController::class, 'resendVerification'], [new AuthMiddleware()], 'auth.verify.resend');
        });

        // ---------------- Cuenta del usuario autenticado
        $r->group('/me', static function (Router $r): void {
            $r->get('', [MeController::class, 'show'], [], 'me');
            $r->get('/memberships', [MeController::class, 'memberships'], [], 'me.memberships');
            $r->post('/memberships/{id:\d+}/aceptar', [MeController::class, 'acceptInvitation'], [], 'me.memberships.accept');
            $r->post('/memberships/{id:\d+}/rechazar', [MeController::class, 'rejectInvitation'], [], 'me.memberships.reject');
            $r->post('/context', [MeController::class, 'setContext'], [], 'me.context');
            $r->get('/sessions', [MeController::class, 'sessions'], [], 'me.sessions');
            $r->delete('/sessions/{id:\d+}', [MeController::class, 'revokeSession'], [], 'me.sessions.revoke');

            // Datos de la cuenta y derechos sobre datos personales (Fase 13)
            $r->put('/perfil', [AccountController::class, 'updateProfile'], [], 'me.profile.update');
            $r->post('/password', [AccountController::class, 'changePassword'], [], 'me.password');
            $r->post('/email', [AccountController::class, 'changeEmail'], [], 'me.email');
            $r->post('/sessions/cerrar-otras', [AccountController::class, 'closeOtherSessions'], [], 'me.sessions.close_others');
            $r->post('/legal/aceptar', [AccountController::class, 'acceptLegal'], [], 'me.legal.accept');
            $r->post('/datos/exportar', [AccountController::class, 'export'], [], 'me.data.export');
            $r->get('/eliminacion', [AccountController::class, 'deletionPreview'], [], 'me.deletion.preview');
            $r->post('/eliminar', [AccountController::class, 'delete'], [], 'me.delete');
            $r->get('/solicitudes-datos', [AccountController::class, 'requests'], [], 'me.data_requests');
            $r->post('/solicitudes-datos', [AccountController::class, 'storeRequest'], [], 'me.data_requests.store');

            // Favoritos (Fase 3)
            $r->get('/favoritos', [FavoritesController::class, 'index'], [new PermissionMiddleware('cliente.favoritos')], 'me.favorites');
            $r->put('/favoritos/{slug:[a-z0-9-]{1,80}}', [FavoritesController::class, 'add'], [new PermissionMiddleware('cliente.favoritos')], 'me.favorites.add');
            $r->delete('/favoritos/{slug:[a-z0-9-]{1,80}}', [FavoritesController::class, 'remove'], [new PermissionMiddleware('cliente.favoritos')], 'me.favorites.remove');

            // Avisos, preferencias y dispositivos push (Fase 8). Toda cuenta (cliente o personal).
            $r->get('/notificaciones', [NotificationsController::class, 'index'], [], 'me.notifications');
            $r->get('/notificaciones/resumen', [NotificationsController::class, 'summary'], [], 'me.notifications.summary');
            $r->post('/notificaciones/leer-todas', [NotificationsController::class, 'readAll'], [], 'me.notifications.read_all');
            $r->post('/notificaciones/{id:\d+}/leer', [NotificationsController::class, 'read'], [], 'me.notifications.read');
            $r->get('/preferencias-notificacion', [NotificationsController::class, 'preferences'], [], 'me.notification_prefs');
            $r->put('/preferencias-notificacion', [NotificationsController::class, 'updatePreferences'], [], 'me.notification_prefs.update');
            $r->get('/dispositivos', [NotificationsController::class, 'devices'], [], 'me.devices');
            $r->post('/dispositivos', [NotificationsController::class, 'registerDevice'], [], 'me.devices.register');
            $r->delete('/dispositivos', [NotificationsController::class, 'unregisterDevice'], [], 'me.devices.unregister');
            $r->delete('/dispositivos/{id:\d+}', [NotificationsController::class, 'deactivateDevice'], [], 'me.devices.deactivate');
        }, [new AuthMiddleware()]);

        // ---------------- Confirmar asistencia desde el recordatorio (Fase 8): enlace firmado, sin sesión.
        $r->post('/asistencia/consultar', [AttendanceController::class, 'show'], [], 'attendance.show');
        $r->post('/asistencia/confirmar', [AttendanceController::class, 'confirm'], [], 'attendance.confirm');

        // ---------------- Marketplace público (Fase 3). Sin sesión obligatoria; con sesión marca favoritos.
        $r->group('/marketplace', static function (Router $r): void {
            $r->get('/filtros', [MarketplaceController::class, 'filters'], [], 'marketplace.filters');
            $r->get('/barberias', [MarketplaceController::class, 'index'], [], 'marketplace.search');
            $r->get('/barberias/{slug:[a-z0-9-]{1,80}}', [MarketplaceController::class, 'show'], [], 'marketplace.show');
            $r->get('/barberias/{slug:[a-z0-9-]{1,80}}/qr', [MarketplaceController::class, 'qr'], [], 'marketplace.qr');
        }, [new AuthMiddleware(true, true)]);

        // ---------------- Motor de reservas (Fase 5)
        // Disponibilidad pública: sin sesión obligatoria (el cliente ve horarios antes de iniciar sesión).
        $r->get('/disponibilidad', [BookingsController::class, 'availability'], [new AuthMiddleware(true, true)], 'bookings.availability');
        $r->post('/reservas', [BookingsController::class, 'store'], [new AuthMiddleware(), new PermissionMiddleware('cliente.reservar'), new VerifiedEmailMiddleware()], 'bookings.store');
        $r->group('/mis-reservas', static function (Router $r): void {
            $gestionar = new PermissionMiddleware('cliente.reservas.gestionar');
            $r->get('', [BookingsController::class, 'index'], [], 'bookings.mine');
            $r->get('/{id:\d+}', [BookingsController::class, 'show'], [], 'bookings.mine.show');
            $r->get('/{id:\d+}/disponibilidad', [BookingsController::class, 'rescheduleAvailability'], [$gestionar], 'bookings.mine.availability');
            $r->post('/{id:\d+}/cancelar', [BookingsController::class, 'cancel'], [$gestionar], 'bookings.mine.cancel');
            $r->post('/{id:\d+}/reprogramar', [BookingsController::class, 'reschedule'], [$gestionar], 'bookings.mine.reschedule');
            $r->post('/{id:\d+}/confirmar-asistencia', [BookingsController::class, 'confirmAttendance'], [$gestionar], 'bookings.mine.confirm');
        }, [new AuthMiddleware()]);

        // ---------------- Panel de barbería: requiere sesión + membresía activa en la barbería
        $r->group('/panel', static function (Router $r): void {
            $r->get('/contexto', [StaffController::class, 'context'], [], 'panel.context');
            $r->get('/personal', [StaffController::class, 'index'], [new PermissionMiddleware('personal.gestionar')], 'panel.staff');
            $r->post('/personal', [StaffController::class, 'invite'], [new PermissionMiddleware('personal.gestionar'), new VerifiedEmailMiddleware()], 'panel.staff.invite');
            $r->delete('/personal/{id:\d+}', [StaffController::class, 'revoke'], [new PermissionMiddleware('personal.gestionar')], 'panel.staff.revoke');
            // Ajustes del equipo (Fase 7): aprobación de ausencias y jornada editable por el barbero
            $r->get('/equipo/ajustes', [StaffController::class, 'teamSettings'], [new PermissionMiddleware('personal.gestionar')], 'panel.team_settings');
            $r->put('/equipo/ajustes', [StaffController::class, 'updateTeamSettings'], [new PermissionMiddleware('personal.gestionar')], 'panel.team_settings.update');

            // Perfil público en el marketplace, fotos, enlace y QR (Fase 3)
            $r->get('/perfil-publico', [PublicProfileController::class, 'show'], [new PermissionMiddleware('negocio.perfil.gestionar')], 'panel.public_profile');
            $r->put('/perfil-publico', [PublicProfileController::class, 'update'], [new PermissionMiddleware('negocio.perfil.gestionar')], 'panel.public_profile.update');
            $r->post('/fotos', [PublicProfileController::class, 'uploadPhoto'], [new PermissionMiddleware('negocio.perfil.gestionar')], 'panel.photos.upload');
            $r->delete('/fotos/{id:\d+}', [PublicProfileController::class, 'deletePhoto'], [new PermissionMiddleware('negocio.perfil.gestionar')], 'panel.photos.delete');

            // ---------- Servicios, barberos, horarios y sucursales (Fase 4)
            $catalogo = PermissionMiddleware::any('sucursales.gestionar', 'servicios.gestionar', 'barberos.gestionar', 'horarios.gestionar', 'bloqueos.gestionar');
            $sucursales = new PermissionMiddleware('sucursales.gestionar');
            $r->get('/sucursales', [BranchesController::class, 'index'], [$catalogo], 'panel.branches');
            $r->post('/sucursales', [BranchesController::class, 'store'], [$sucursales], 'panel.branches.store');
            $r->get('/sucursales/{id:\d+}', [BranchesController::class, 'show'], [$catalogo], 'panel.branches.show');
            $r->put('/sucursales/{id:\d+}', [BranchesController::class, 'update'], [$sucursales], 'panel.branches.update');
            $r->delete('/sucursales/{id:\d+}', [BranchesController::class, 'destroy'], [$sucursales], 'panel.branches.destroy');
            $r->put('/sucursales/{id:\d+}/horario', [BranchesController::class, 'hours'], [$sucursales], 'panel.branches.hours');

            $servicios = new PermissionMiddleware('servicios.gestionar');
            $r->get('/servicios', [ServicesController::class, 'index'], [$servicios], 'panel.services');
            $r->post('/servicios', [ServicesController::class, 'store'], [$servicios], 'panel.services.store');
            $r->get('/servicios/{id:\d+}', [ServicesController::class, 'show'], [$servicios], 'panel.services.show');
            $r->put('/servicios/{id:\d+}', [ServicesController::class, 'update'], [$servicios], 'panel.services.update');
            $r->delete('/servicios/{id:\d+}', [ServicesController::class, 'destroy'], [$servicios], 'panel.services.destroy');
            $r->get('/categorias', [ServicesController::class, 'categories'], [$servicios], 'panel.categories');
            $r->post('/categorias', [ServicesController::class, 'storeCategory'], [$servicios], 'panel.categories.store');
            $r->put('/categorias/{id:\d+}', [ServicesController::class, 'updateCategory'], [$servicios], 'panel.categories.update');
            $r->delete('/categorias/{id:\d+}', [ServicesController::class, 'destroyCategory'], [$servicios], 'panel.categories.destroy');

            $verBarberos = PermissionMiddleware::any('barberos.gestionar', 'horarios.gestionar', 'bloqueos.gestionar');
            $barberos = new PermissionMiddleware('barberos.gestionar');
            $horarios = new PermissionMiddleware('horarios.gestionar');
            $r->get('/barberos', [BarbersController::class, 'index'], [$verBarberos], 'panel.barbers');
            $r->post('/barberos', [BarbersController::class, 'store'], [$barberos], 'panel.barbers.store');
            $r->get('/barberos/{id:\d+}', [BarbersController::class, 'show'], [$verBarberos], 'panel.barbers.show');
            $r->put('/barberos/{id:\d+}', [BarbersController::class, 'update'], [$barberos], 'panel.barbers.update');
            $r->delete('/barberos/{id:\d+}', [BarbersController::class, 'destroy'], [$barberos], 'panel.barbers.destroy');
            $r->put('/barberos/{id:\d+}/servicios', [BarbersController::class, 'services'], [$barberos], 'panel.barbers.services');
            $r->put('/barberos/{id:\d+}/horario', [BarbersController::class, 'schedule'], [$horarios], 'panel.barbers.schedule');
            $r->get('/barberos/{id:\d+}/jornada', [BarbersController::class, 'workingTime'], [$horarios], 'panel.barbers.working_time');
            $r->post('/barberos/{id:\d+}/foto', [BarbersController::class, 'uploadPhoto'], [$barberos], 'panel.barbers.photo');
            $r->delete('/barberos/{id:\d+}/foto', [BarbersController::class, 'deletePhoto'], [$barberos], 'panel.barbers.photo.delete');

            // ---------- Reglas de reserva (Fase 5)
            $politicas = new PermissionMiddleware('politicas.gestionar');
            $r->get('/politicas-reserva', [BookingPolicyController::class, 'index'], [$politicas], 'panel.booking_policy');
            $r->put('/politicas-reserva', [BookingPolicyController::class, 'update'], [$politicas], 'panel.booking_policy.update');
            $r->delete('/politicas-reserva/{sucursal_id:\d+}', [BookingPolicyController::class, 'destroy'], [$politicas], 'panel.booking_policy.destroy');

            $bloqueos = new PermissionMiddleware('bloqueos.gestionar');
            $r->get('/bloqueos', [AgendaBlocksController::class, 'index'], [$bloqueos], 'panel.blocks');
            $r->post('/bloqueos', [AgendaBlocksController::class, 'store'], [$bloqueos], 'panel.blocks.store');
            $r->post('/bloqueos/{id:\d+}/aprobar', [AgendaBlocksController::class, 'approve'], [$bloqueos], 'panel.blocks.approve');
            $r->post('/bloqueos/{id:\d+}/rechazar', [AgendaBlocksController::class, 'reject'], [$bloqueos], 'panel.blocks.reject');
            $r->delete('/bloqueos/{id:\d+}', [AgendaBlocksController::class, 'destroy'], [$bloqueos], 'panel.blocks.destroy');

            // ---------- Panel administrativo (Fase 6): dashboard, agenda, clientes, reportes y configuración
            $verAgenda = PermissionMiddleware::any('reservas.ver_todas', 'reservas.ver_propias');
            $r->get('/dashboard', [PanelReportsController::class, 'dashboard'], [PermissionMiddleware::any('reservas.ver_todas', 'reportes.operativos')], 'panel.dashboard');
            $r->get('/reportes', [PanelReportsController::class, 'report'], [new PermissionMiddleware('reportes.operativos')], 'panel.reports');
            $r->get('/reportes/exportar', [PanelReportsController::class, 'export'], [new PermissionMiddleware('reportes.operativos')], 'panel.reports.export');

            $r->get('/agenda', [PanelAgendaController::class, 'agenda'], [$verAgenda], 'panel.agenda');
            $r->get('/agenda/catalogo', [PanelAgendaController::class, 'catalog'], [PermissionMiddleware::any('reservas.crear', 'reservas.reprogramar')], 'panel.agenda.catalog');
            $r->get('/disponibilidad', [PanelAgendaController::class, 'availability'], [PermissionMiddleware::any('reservas.crear', 'reservas.reprogramar')], 'panel.availability');
            $r->post('/reservas', [PanelAgendaController::class, 'store'], [new PermissionMiddleware('reservas.crear')], 'panel.bookings.store');
            $r->get('/reservas/{id:\d+}', [PanelAgendaController::class, 'show'], [$verAgenda], 'panel.bookings.show');
            $asistencia = new PermissionMiddleware('reservas.asistencia');
            $r->post('/reservas/{id:\d+}/confirmar', [PanelAgendaController::class, 'confirm'], [$verAgenda, PermissionMiddleware::any('reservas.crear', 'reservas.asistencia')], 'panel.bookings.confirm');
            $r->post('/reservas/{id:\d+}/iniciar', [PanelAgendaController::class, 'start'], [$verAgenda, $asistencia], 'panel.bookings.start');
            $r->post('/reservas/{id:\d+}/completar', [PanelAgendaController::class, 'complete'], [$verAgenda, $asistencia], 'panel.bookings.complete');
            $r->post('/reservas/{id:\d+}/no-asistio', [PanelAgendaController::class, 'noShow'], [$verAgenda, $asistencia], 'panel.bookings.no_show');
            $r->post('/reservas/{id:\d+}/cancelar', [PanelAgendaController::class, 'cancel'], [$verAgenda, new PermissionMiddleware('reservas.cancelar')], 'panel.bookings.cancel');
            $r->get('/reservas/{id:\d+}/disponibilidad', [PanelAgendaController::class, 'rescheduleAvailability'], [$verAgenda, new PermissionMiddleware('reservas.reprogramar')], 'panel.bookings.availability');
            $r->post('/reservas/{id:\d+}/reprogramar', [PanelAgendaController::class, 'reschedule'], [$verAgenda, new PermissionMiddleware('reservas.reprogramar')], 'panel.bookings.reschedule');
            $r->put('/reservas/{id:\d+}/notas', [PanelAgendaController::class, 'notes'], [$verAgenda, PermissionMiddleware::any('reservas.crear', 'reservas.asistencia')], 'panel.bookings.notes');
            $cobrar = new PermissionMiddleware('reservas.cobrar');
            $r->post('/reservas/{id:\d+}/pagos', [PanelAgendaController::class, 'pay'], [$verAgenda, $cobrar], 'panel.bookings.pay');
            $r->post('/reservas/{id:\d+}/pagos/{pago:\d+}/anular', [PanelAgendaController::class, 'voidPayment'], [$verAgenda, $cobrar], 'panel.bookings.pay.void');

            $clientes = new PermissionMiddleware('clientes.ver');
            $r->get('/clientes', [PanelClientsController::class, 'index'], [$clientes], 'panel.clients');
            $r->post('/clientes', [PanelClientsController::class, 'store'], [new PermissionMiddleware('reservas.crear')], 'panel.clients.store');
            $r->get('/clientes/{id:\d+}', [PanelClientsController::class, 'show'], [$clientes], 'panel.clients.show');
            $r->get('/clientes/{id:\d+}/reservas', [PanelClientsController::class, 'bookings'], [$clientes], 'panel.clients.bookings');
            $r->put('/clientes/{id:\d+}', [PanelClientsController::class, 'update'], [$clientes, new PermissionMiddleware('clientes.notas')], 'panel.clients.update');

            // ---------- Avisos y recordatorios (Fase 8)
            $verAvisos = PermissionMiddleware::any('notificaciones.gestionar', 'reportes.operativos');
            $r->get('/notificaciones/reglas', [PanelNotificationsController::class, 'rules'], [$verAvisos], 'panel.notifications.rules');
            $r->put('/notificaciones/reglas', [PanelNotificationsController::class, 'updateRules'], [new PermissionMiddleware('notificaciones.gestionar')], 'panel.notifications.rules.update');
            $r->get('/notificaciones/metricas', [PanelNotificationsController::class, 'metrics'], [$verAvisos], 'panel.notifications.metrics');

            // ---------- Suscripción y pagos de la barbería (Fase 10)
            $verSus = new PermissionMiddleware('negocio.suscripcion.ver');
            $gesSus = new PermissionMiddleware('negocio.suscripcion.gestionar');
            $orden = '/suscripcion/ordenes/{codigo:[a-f0-9]{24}}';
            $r->get('/suscripcion', [PanelBillingController::class, 'show'], [$verSus], 'panel.subscription');
            $r->post('/suscripcion/cotizar', [PanelBillingController::class, 'quote'], [$gesSus], 'panel.subscription.quote');
            $r->post('/suscripcion/pagar', [PanelBillingController::class, 'pay'], [$gesSus, new VerifiedEmailMiddleware()], 'panel.subscription.pay');
            $r->post('/suscripcion/cambiar-plan', [PanelBillingController::class, 'changePlan'], [$gesSus], 'panel.subscription.change_plan');
            $r->delete('/suscripcion/cambio-programado', [PanelBillingController::class, 'cancelScheduled'], [$gesSus], 'panel.subscription.cancel_scheduled');
            $r->get($orden, [PanelBillingController::class, 'order'], [$verSus], 'panel.subscription.order');
            $r->post($orden . '/simular', [PanelBillingController::class, 'simulate'], [$gesSus], 'panel.subscription.simulate');

            $negocio = new PermissionMiddleware('negocio.perfil.gestionar');
            $r->get('/negocio', [BusinessController::class, 'show'], [$negocio], 'panel.business');
            $r->put('/negocio', [BusinessController::class, 'update'], [$negocio], 'panel.business.update');
        }, [new AuthMiddleware(), new TenantContextMiddleware(true, true)]);

        // ---------------- Panel del barbero (Fase 7): solo la agenda del barbero vinculado a la cuenta.
        // Sin ficha de barbero vinculada responde 403 no_barber_profile.
        $r->group('/barbero', static function (Router $r): void {
            $ver = PermissionMiddleware::any('reservas.ver_propias', 'reservas.ver_todas');
            $asistencia = new PermissionMiddleware('reservas.asistencia');
            $r->get('/hoy', [BarberPanelController::class, 'day'], [$ver], 'barber.day');
            $r->get('/proximas', [BarberPanelController::class, 'upcoming'], [$ver], 'barber.upcoming');
            $r->get('/historial', [BarberPanelController::class, 'history'], [$ver], 'barber.history');
            $r->get('/citas/{id:\d+}', [BarberPanelController::class, 'show'], [$ver], 'barber.bookings.show');
            $r->post('/citas/{id:\d+}/confirmar', [BarberPanelController::class, 'confirm'], [$ver, PermissionMiddleware::any('reservas.crear', 'reservas.asistencia')], 'barber.bookings.confirm');
            $r->post('/citas/{id:\d+}/iniciar', [BarberPanelController::class, 'start'], [$ver, $asistencia], 'barber.bookings.start');
            $r->post('/citas/{id:\d+}/completar', [BarberPanelController::class, 'complete'], [$ver, $asistencia], 'barber.bookings.complete');
            $r->post('/citas/{id:\d+}/no-asistio', [BarberPanelController::class, 'noShow'], [$ver, $asistencia], 'barber.bookings.no_show');
            $r->put('/citas/{id:\d+}/notas', [BarberPanelController::class, 'notes'], [$ver, PermissionMiddleware::any('reservas.crear', 'reservas.asistencia')], 'barber.bookings.notes');

            $horario = PermissionMiddleware::any('reservas.ver_propias', 'reservas.ver_todas', 'horarios.propios', 'horarios.gestionar');
            $r->get('/horario', [BarberPanelController::class, 'schedule'], [$horario], 'barber.schedule');
            $r->put('/horario', [BarberPanelController::class, 'updateSchedule'], [PermissionMiddleware::any('horarios.propios', 'horarios.gestionar')], 'barber.schedule.update');

            $ausencias = PermissionMiddleware::any('bloqueos.solicitar', 'bloqueos.gestionar');
            $r->get('/ausencias', [BarberPanelController::class, 'blocks'], [$horario], 'barber.blocks');
            $r->post('/ausencias', [BarberPanelController::class, 'requestBlock'], [$ausencias], 'barber.blocks.request');
            $r->delete('/ausencias/{id:\d+}', [BarberPanelController::class, 'withdrawBlock'], [$ausencias], 'barber.blocks.withdraw');
        }, [new AuthMiddleware(), new TenantContextMiddleware(true, true)]);

        // ---------------- Panel del superadministrador SaaS (Fase 9): solo el equipo de BARBERAPP.
        // Nunca expone clientes ni reservas individuales de las barberías: solo cifras agregadas.
        $r->group('/plataforma', static function (Router $r): void {
            $reportes = new PermissionMiddleware('plataforma.reportes.ver');
            $r->get('/contexto', [PlatformController::class, 'context'], [], 'platform.context');
            $r->get('/dashboard', [PlatformController::class, 'dashboard'], [$reportes], 'platform.dashboard');
            $r->get('/reportes', [PlatformController::class, 'report'], [$reportes], 'platform.reports');
            $r->get('/sistema', [PlatformController::class, 'system'], [$reportes], 'platform.system');

            $tenants = new PermissionMiddleware('plataforma.tenants.gestionar');
            $r->get('/barberias', [PlatformTenantsController::class, 'index'], [$tenants], 'platform.tenants');
            $r->get('/barberias/{id:\d+}', [PlatformTenantsController::class, 'show'], [$tenants], 'platform.tenants.show');
            $r->put('/barberias/{id:\d+}', [PlatformTenantsController::class, 'update'], [$tenants], 'platform.tenants.update');
            $r->post('/barberias/{id:\d+}/aprobar', [PlatformTenantsController::class, 'approve'], [$tenants], 'platform.tenants.approve');
            $r->post('/barberias/{id:\d+}/rechazar', [PlatformTenantsController::class, 'reject'], [$tenants], 'platform.tenants.reject');
            $r->post('/barberias/{id:\d+}/suspender', [PlatformTenantsController::class, 'suspend'], [$tenants], 'platform.tenants.suspend');
            $r->post('/barberias/{id:\d+}/reactivar', [PlatformTenantsController::class, 'reactivate'], [$tenants], 'platform.tenants.reactivate');

            $planes = new PermissionMiddleware('plataforma.planes.gestionar');
            $verPlanes = PermissionMiddleware::any('plataforma.planes.gestionar', 'plataforma.suscripciones.gestionar');
            $r->get('/planes', [PlatformBillingController::class, 'plans'], [$verPlanes], 'platform.plans');
            $r->post('/planes', [PlatformBillingController::class, 'storePlan'], [$planes], 'platform.plans.store');
            $r->put('/planes/{id:\d+}', [PlatformBillingController::class, 'updatePlan'], [$planes], 'platform.plans.update');

            $subs = new PermissionMiddleware('plataforma.suscripciones.gestionar');
            $r->get('/suscripciones', [PlatformBillingController::class, 'subscriptions'], [$subs], 'platform.subscriptions');
            $r->post('/suscripciones', [PlatformBillingController::class, 'storeSubscription'], [$subs], 'platform.subscriptions.store');
            $r->get('/suscripciones/{id:\d+}', [PlatformBillingController::class, 'showSubscription'], [$subs], 'platform.subscriptions.show');
            $r->post('/suscripciones/{id:\d+}/cambiar-plan', [PlatformBillingController::class, 'changePlan'], [$subs], 'platform.subscriptions.change_plan');
            $r->post('/suscripciones/{id:\d+}/renovar', [PlatformBillingController::class, 'renew'], [$subs], 'platform.subscriptions.renew');
            $r->post('/suscripciones/{id:\d+}/suspender', [PlatformBillingController::class, 'suspend'], [$subs], 'platform.subscriptions.suspend');
            $r->post('/suscripciones/{id:\d+}/reactivar', [PlatformBillingController::class, 'reactivate'], [$subs], 'platform.subscriptions.reactivate');
            $r->post('/suscripciones/{id:\d+}/cancelar', [PlatformBillingController::class, 'cancel'], [$subs], 'platform.subscriptions.cancel');

            $pagos = new PermissionMiddleware('plataforma.pagos.gestionar');
            $r->get('/pagos', [PlatformBillingController::class, 'payments'], [$pagos], 'platform.payments');
            $r->get('/ordenes', [PlatformBillingController::class, 'orders'], [$pagos], 'platform.orders');
            $r->post('/suscripciones/{id:\d+}/pagos', [PlatformBillingController::class, 'storePayment'], [$pagos], 'platform.payments.store');
            $r->post('/pagos/{id:\d+}/confirmar', [PlatformBillingController::class, 'confirmPayment'], [$pagos], 'platform.payments.confirm');
            $r->post('/pagos/{id:\d+}/rechazar', [PlatformBillingController::class, 'rejectPayment'], [$pagos], 'platform.payments.reject');
            $r->post('/pagos/{id:\d+}/reembolsar', [PlatformBillingController::class, 'refundPayment'], [$pagos], 'platform.payments.refund');

            $usuarios = new PermissionMiddleware('plataforma.usuarios.gestionar');
            $r->get('/usuarios', [PlatformUsersController::class, 'team'], [$usuarios], 'platform.users');
            $r->get('/usuarios/buscar', [PlatformUsersController::class, 'search'], [$usuarios], 'platform.users.search');
            $r->get('/usuarios/{id:\d+}', [PlatformUsersController::class, 'show'], [$usuarios], 'platform.users.show');
            $r->post('/usuarios/{id:\d+}/bloquear', [PlatformUsersController::class, 'block'], [$usuarios], 'platform.users.block');
            $r->post('/usuarios/{id:\d+}/desbloquear', [PlatformUsersController::class, 'unblock'], [$usuarios], 'platform.users.unblock');
            $r->put('/usuarios/{id:\d+}/rol', [PlatformUsersController::class, 'role'], [$usuarios], 'platform.users.role');

            $plantillas = new PermissionMiddleware('plataforma.plantillas.gestionar');
            $tpl = '/plantillas/{codigo:[a-z_]{3,60}}/{canal:push|email}';
            $r->get('/plantillas', [PlatformController::class, 'templates'], [$plantillas], 'platform.templates');
            $r->put($tpl, [PlatformController::class, 'saveTemplate'], [$plantillas], 'platform.templates.save');
            $r->delete($tpl, [PlatformController::class, 'restoreTemplate'], [$plantillas], 'platform.templates.restore');
            $r->post($tpl . '/vista-previa', [PlatformController::class, 'previewTemplate'], [$plantillas], 'platform.templates.preview');

            $auditoria = new PermissionMiddleware('plataforma.auditoria.ver');
            $r->get('/auditoria', [PlatformController::class, 'audit'], [$auditoria], 'platform.audit');
            $r->get('/auditoria/acciones', [PlatformController::class, 'auditActions'], [$auditoria], 'platform.audit.actions');

            $config = new PermissionMiddleware('plataforma.configuracion.gestionar');
            $r->get('/configuracion', [PlatformController::class, 'settings'], [$config], 'platform.settings');
            $r->put('/configuracion', [PlatformController::class, 'updateSettings'], [$config], 'platform.settings.update');

            // Solicitudes de derechos sobre datos personales (Fase 13)
            $privacidad = new PermissionMiddleware('plataforma.privacidad.gestionar');
            $r->get('/solicitudes-datos', [PlatformPrivacyController::class, 'index'], [$privacidad], 'platform.data_requests');
            $r->post('/solicitudes-datos/{id:\d+}/responder', [PlatformPrivacyController::class, 'respond'], [$privacidad], 'platform.data_requests.respond');
        }, [new AuthMiddleware(), new PlatformMiddleware()]);
    });
};
