<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\SessionRepository;
use App\Repositories\UserRepository;
use App\Services\Audit;
use App\Services\Auth\RateLimiter;
use App\Services\Auth\SessionService;
use App\Services\Privacy\AccountDeletion;
use App\Services\Privacy\AccountService;
use App\Services\Privacy\DataExport;
use App\Services\Privacy\DataRequests;
use App\Services\Privacy\LegalInfo;
use App\Helpers\Clock;

/**
 * Derechos sobre los datos y seguridad de la propia cuenta (Fase 13). Todas con sesión.
 *
 * PUT  /api/v1/me/perfil                  Rectificar nombre, apellidos y teléfono
 * POST /api/v1/me/password                Cambiar contraseña (cierra las demás sesiones)
 * POST /api/v1/me/email                   Cambiar correo (se confirma desde el correo nuevo)
 * POST /api/v1/me/sessions/cerrar-otras   Cerrar todas las sesiones salvo esta
 * POST /api/v1/me/legal/aceptar           Aceptar la versión vigente de términos y privacidad
 * POST /api/v1/me/datos/exportar          Descargar mis datos (JSON), con contraseña
 * GET  /api/v1/me/eliminacion             Qué pasará al eliminar la cuenta y si algo lo impide
 * POST /api/v1/me/eliminar                Eliminar la cuenta (contraseña + "ELIMINAR")
 * GET|POST /api/v1/me/solicitudes-datos   Solicitudes de derechos (ARCO)
 */
final class AccountController extends BaseController
{
    public function updateProfile(Request $request): Response
    {
        return $this->ok((new AccountService())->updateProfile((array) $request->attribute('auth_user'), $request->json(), $request));
    }

    public function changePassword(Request $request): Response
    {
        $data = $this->validate($request, ['password_actual' => 'required|string|max:200', 'password_nueva' => 'required|string|max:200']);
        $user = (array) $request->attribute('auth_user');
        $session = (array) $request->attribute('auth_session');
        $cerradas = (new AccountService())->changePassword((int) $user['id'], (string) $data['password_actual'], (string) $data['password_nueva'], (int) $session['id'], $request);
        return $this->ok(['sesiones_cerradas' => $cerradas, 'mensaje' => 'Contraseña actualizada. Cerramos tus otras sesiones.']);
    }

    public function changeEmail(Request $request): Response
    {
        $data = $this->validate($request, ['email' => 'required|string|max:190', 'password' => 'required|string|max:200']);
        $user = (array) $request->attribute('auth_user');
        $masked = (new AccountService())->requestEmailChange((int) $user['id'], (string) $data['password'], (string) $data['email'], $request);
        return $this->ok(['mensaje' => "Te enviamos un enlace a {$masked}. Tu correo cambiará cuando lo confirmes."]);
    }

    public function confirmEmail(Request $request): Response
    {
        $data = $this->validate($request, ['token' => 'required|string|max:200']);
        $perfil = (new AccountService())->confirmEmailChange((string) $data['token'], $request);
        return $this->ok(['usuario' => $perfil, 'mensaje' => 'Listo: tu cuenta ahora usa el correo nuevo.']);
    }

    public function closeOtherSessions(Request $request): Response
    {
        $user = (array) $request->attribute('auth_user');
        $session = (array) $request->attribute('auth_session');
        $n = (new SessionRepository())->revokeAllForUser((int) $user['id'], 'usuario_otras', (int) $session['id']);
        Audit::record('auth.sesiones_otras_cerradas', 'exito', $request, (int) $user['id'], null, 'sesiones', (int) $session['id'], ['cerradas' => $n]);
        return $this->ok(['sesiones_cerradas' => $n]);
    }

    public function acceptLegal(Request $request): Response
    {
        $user = (array) $request->attribute('auth_user');
        $data = $this->validate($request, ['acepta' => 'required|boolean']);
        if ($data['acepta'] !== true) {
            throw new ValidationException(['acepta' => ['Para seguir usando BARBERAPP debes aceptar los términos y la política de privacidad.']]);
        }
        $users = new UserRepository();
        $users->acceptLegal((int) $user['id']);
        Audit::record('privacidad.textos_aceptados', 'exito', $request, (int) $user['id'], null, 'usuarios', (int) $user['id'], [
            'terminos' => LegalInfo::terminosVersion(), 'privacidad' => LegalInfo::privacidadVersion(),
        ]);
        return $this->ok(UserRepository::publicProfile((array) $users->findById((int) $user['id'])));
    }

    public function export(Request $request): Response
    {
        $data = $this->validate($request, ['password' => 'required|string|max:200']);
        $userId = (int) ((array) $request->attribute('auth_user'))['id'];
        (new RateLimiter())->consume('exportar_usuario', (string) $userId);
        $user = (new AccountService())->reauthenticate($userId, (string) $data['password'], $request, 'exportar_datos');

        $export = (new DataExport())->build($userId);
        $solicitud = DataRequests::create($userId, (string) $user['email'], 'acceso', 'autoservicio', null);
        Audit::record('privacidad.datos_exportados', 'exito', $request, $userId, null, 'usuarios', $userId, ['solicitud' => $solicitud['codigo']]);

        $res = new Response((string) json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200);
        $res->setHeader('Content-Type', 'application/json; charset=utf-8');
        $res->setHeader('Content-Disposition', 'attachment; filename="barberapp-mis-datos-' . Clock::nowUtc()->format('Ymd') . '.json"');
        $res->setHeader('Cache-Control', 'no-store');
        return $res;
    }

    public function deletionPreview(Request $request): Response
    {
        return $this->ok((new AccountDeletion())->preview((int) ((array) $request->attribute('auth_user'))['id']));
    }

    public function delete(Request $request): Response
    {
        $data = $this->validate($request, ['password' => 'required|string|max:200', 'confirmacion' => 'required|string|max:20']);
        if (mb_strtoupper(trim((string) $data['confirmacion'])) !== 'ELIMINAR') {
            throw new ValidationException(['confirmacion' => ['Escribe ELIMINAR para confirmar.']]);
        }
        $userId = (int) ((array) $request->attribute('auth_user'))['id'];
        $user = (new AccountService())->reauthenticate($userId, (string) $data['password'], $request, 'eliminar_cuenta');
        $result = (new AccountDeletion())->delete($user, $request);
        $res = $this->ok($result + ['mensaje' => 'Tu cuenta fue eliminada. Te enviamos la constancia por correo.']);
        return SessionService::clearCookie($res);
    }

    public function requests(Request $request): Response
    {
        return $this->ok(DataRequests::forUser((int) ((array) $request->attribute('auth_user'))['id']));
    }

    public function storeRequest(Request $request): Response
    {
        return $this->created(DataRequests::submit((array) $request->attribute('auth_user'), $request->json(), $request));
    }
}
