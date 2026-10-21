<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\FavoritesController;
use App\Controllers\HealthController;
use App\Controllers\MarketplaceController;
use App\Controllers\MeController;
use App\Controllers\PublicProfileController;
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
        }, [new AuthMiddleware(), new TenantContextMiddleware(true, true)]);
    });
};
