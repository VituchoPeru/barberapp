<?php

declare(strict_types=1);

use App\Controllers\AgendaBlocksController;
use App\Controllers\AuthController;
use App\Controllers\BarbersController;
use App\Controllers\BranchesController;
use App\Controllers\FavoritesController;
use App\Controllers\HealthController;
use App\Controllers\MarketplaceController;
use App\Controllers\MeController;
use App\Controllers\PublicProfileController;
use App\Controllers\ServicesController;
use App\Controllers\StaffController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;
use App\Middleware\TenantContextMiddleware;
use App\Middleware\VerifiedEmailMiddleware;

/*
 * Rutas de la API REST v1.
 * Las fases siguientes agregan sus grupos aquí (reservas, panel, plataforma).
 */
return static function (Router $router): void {
    $router->group('/api/v1', static function (Router $r): void {
        $r->get('/health', [HealthController::class, 'show'], [], 'health');
        $r->get('/health/live', [HealthController::class, 'live'], [], 'health.live');

        // ---------------- Autenticación (públicas, con limitación de intentos)
        $r->group('/auth', static function (Router $r): void {
            $r->post('/register', [AuthController::class, 'register'], [], 'auth.register');
            $r->post('/login', [AuthController::class, 'login'], [], 'auth.login');
            $r->post('/refresh', [AuthController::class, 'refresh'], [], 'auth.refresh');
            $r->post('/forgot-password', [AuthController::class, 'forgotPassword'], [], 'auth.forgot');
            $r->post('/reset-password', [AuthController::class, 'resetPassword'], [], 'auth.reset');
            $r->post('/verify-email', [AuthController::class, 'verifyEmail'], [], 'auth.verify');

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

            // Favoritos (Fase 3)
            $r->get('/favoritos', [FavoritesController::class, 'index'], [new PermissionMiddleware('cliente.favoritos')], 'me.favorites');
            $r->put('/favoritos/{slug:[a-z0-9-]{1,80}}', [FavoritesController::class, 'add'], [new PermissionMiddleware('cliente.favoritos')], 'me.favorites.add');
            $r->delete('/favoritos/{slug:[a-z0-9-]{1,80}}', [FavoritesController::class, 'remove'], [new PermissionMiddleware('cliente.favoritos')], 'me.favorites.remove');
        }, [new AuthMiddleware()]);

        // ---------------- Marketplace público (Fase 3). Sin sesión obligatoria; con sesión marca favoritos.
        $r->group('/marketplace', static function (Router $r): void {
            $r->get('/filtros', [MarketplaceController::class, 'filters'], [], 'marketplace.filters');
            $r->get('/barberias', [MarketplaceController::class, 'index'], [], 'marketplace.search');
            $r->get('/barberias/{slug:[a-z0-9-]{1,80}}', [MarketplaceController::class, 'show'], [], 'marketplace.show');
            $r->get('/barberias/{slug:[a-z0-9-]{1,80}}/qr', [MarketplaceController::class, 'qr'], [], 'marketplace.qr');
        }, [new AuthMiddleware(true, true)]);

        // ---------------- Panel de barbería: requiere sesión + membresía activa en la barbería
        $r->group('/panel', static function (Router $r): void {
            $r->get('/contexto', [StaffController::class, 'context'], [], 'panel.context');
            $r->get('/personal', [StaffController::class, 'index'], [new PermissionMiddleware('personal.gestionar')], 'panel.staff');
            $r->post('/personal', [StaffController::class, 'invite'], [new PermissionMiddleware('personal.gestionar'), new VerifiedEmailMiddleware()], 'panel.staff.invite');
            $r->delete('/personal/{id:\d+}', [StaffController::class, 'revoke'], [new PermissionMiddleware('personal.gestionar')], 'panel.staff.revoke');

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

            $bloqueos = new PermissionMiddleware('bloqueos.gestionar');
            $r->get('/bloqueos', [AgendaBlocksController::class, 'index'], [$bloqueos], 'panel.blocks');
            $r->post('/bloqueos', [AgendaBlocksController::class, 'store'], [$bloqueos], 'panel.blocks.store');
            $r->post('/bloqueos/{id:\d+}/aprobar', [AgendaBlocksController::class, 'approve'], [$bloqueos], 'panel.blocks.approve');
            $r->post('/bloqueos/{id:\d+}/rechazar', [AgendaBlocksController::class, 'reject'], [$bloqueos], 'panel.blocks.reject');
            $r->delete('/bloqueos/{id:\d+}', [AgendaBlocksController::class, 'destroy'], [$bloqueos], 'panel.blocks.destroy');
        }, [new AuthMiddleware(), new TenantContextMiddleware(true, true)]);
    });
};
