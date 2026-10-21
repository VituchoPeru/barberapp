<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth\AuthService;
use App\Services\Auth\SessionService;
use App\Services\Auth\TokenSigner;

/**
 * POST /api/v1/auth/register | login | logout | refresh | forgot-password | reset-password | verify-email
 * GET  /api/v1/auth/csrf
 */
final class AuthController extends BaseController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    public function register(Request $request): Response
    {
        $data = $this->validate($request, [
            'tipo' => 'required|in:cliente,propietario',
            'email' => 'required|email',
            'password' => 'required|string|max:200',
            'nombres' => 'required|string|min:2|max:100',
            'apellidos' => 'nullable|string|max:100',
            'telefono' => 'nullable|regex:/^\+?[0-9]{6,15}$/',
            'acepta_terminos' => 'required|boolean',
            'canal' => 'nullable|in:web,movil',
            'dispositivo' => 'nullable|string|max:120',
        ]);
        $barberia = $request->input('barberia');
        if ($data['tipo'] === 'propietario' && !is_array($barberia)) {
            throw new \App\Core\Exceptions\ValidationException(['barberia' => ['Indica los datos de tu barbería.']]);
        }
        $data['barberia'] = is_array($barberia) ? $barberia : null;
        $data['canal'] = $data['canal'] ?? 'web';

        $issued = $this->auth->register($data, $request);
        return $this->withSession(Response::success($issued['data'], 201), $issued);
    }

    public function login(Request $request): Response
    {
        $data = $this->validate($request, [
            'email' => 'required|email',
            'password' => 'required|string|max:200',
            'canal' => 'nullable|in:web,movil',
            'dispositivo' => 'nullable|string|max:120',
        ]);
        $issued = $this->auth->login($data['email'], $data['password'], $data['canal'] ?? 'web', $data['dispositivo'] ?? null, $request);
        return $this->withSession($this->ok($issued['data']), $issued);
    }

    public function logout(Request $request): Response
    {
        $data = $this->validate($request, ['todas' => 'nullable|boolean']);
        $closed = $this->auth->logout($request, (bool) ($data['todas'] ?? false));
        $response = $this->ok(['sesiones_cerradas' => $closed]);
        return $request->attribute('auth_via') === 'cookie' ? SessionService::clearCookie($response) : $response;
    }

    public function refresh(Request $request): Response
    {
        $data = $this->validate($request, ['refresh_token' => 'required|string|max:200']);
        (new \App\Services\Auth\RateLimiter())->consume('refresh_ip', $request->ip());
        return $this->ok((new SessionService())->refresh($data['refresh_token'], $request));
    }

    public function forgotPassword(Request $request): Response
    {
        $data = $this->validate($request, ['email' => 'required|email']);
        $this->auth->forgotPassword($data['email'], $request);
        return Response::success([
            'mensaje' => 'Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña.',
        ], 202);
    }

    public function resetPassword(Request $request): Response
    {
        $data = $this->validate($request, [
            'token' => 'required|string|max:200',
            'password' => 'required|string|max:200',
        ]);
        $this->auth->resetPassword($data['token'], $data['password'], $request);
        return $this->ok(['mensaje' => 'Tu contraseña se actualizó. Por seguridad cerramos todas tus sesiones: inicia sesión de nuevo.']);
    }

    public function verifyEmail(Request $request): Response
    {
        $data = $this->validate($request, ['token' => 'required|string|max:200']);
        return $this->ok(['usuario' => $this->auth->verifyEmail($data['token'], $request), 'mensaje' => 'Tu correo quedó verificado.']);
    }

    public function resendVerification(Request $request): Response
    {
        $sent = $this->auth->resendVerification($request);
        return $this->ok([
            'enviado' => $sent,
            'mensaje' => $sent ? 'Te enviamos un nuevo enlace de verificación.' : 'Tu correo ya estaba verificado.',
        ]);
    }

    /** Token CSRF de la sesión web actual (para recuperar el valor tras recargar la página). */
    public function csrf(Request $request): Response
    {
        $session = (array) $request->attribute('auth_session');
        if ($request->attribute('auth_via') !== 'cookie') {
            throw new HttpException(400, 'not_a_web_session', 'El token CSRF solo aplica a sesiones web con cookie.');
        }
        return $this->ok(['csrf_token' => TokenSigner::csrfFor((int) $session['id'], (string) $session['token_hash'])]);
    }

    /** @param array{data: array<string, mixed>, cookie: ?string, expires: int} $issued */
    private function withSession(Response $response, array $issued): Response
    {
        if ($issued['cookie'] !== null) {
            SessionService::attachCookie($response, $issued['cookie'], $issued['expires']);
        }
        return $response;
    }
}
