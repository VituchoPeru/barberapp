<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\SessionRepository;
use App\Repositories\UserRepository;
use App\Services\Audit;
use App\Services\Auth\SessionService;
use App\Services\Auth\TokenSigner;
use App\Helpers\Clock;
use App\Helpers\Tokens;

/**
 * Autentica la petición y deja en el request:
 *   'auth_user'    → fila del usuario (sin password_hash)
 *   'auth_session' → sesión vigente (id, canal, tenant_id…)
 *   'auth_via'     → 'bearer' | 'cookie'
 *
 * Orden: 1) Authorization: Bearer <access token> (app móvil)  2) cookie de sesión (web).
 * Con cookie, los métodos que modifican datos exigen X-CSRF-Token válido.
 * Con $optional = true la petición continúa sin usuario si no hay credenciales.
 * Con $anonymousOnInvalid = true (rutas públicas como el marketplace), una cookie de sesión
 * vencida o inválida no bloquea la página: se audita y la petición sigue como anónima.
 * Un access token móvil inválido sí responde 401, para que la app lo renueve con /auth/refresh.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private bool $optional = false, private bool $anonymousOnInvalid = false)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $sessions = new SessionRepository();
        $bearer = $request->bearerToken();
        $cookie = $request->cookie(SessionService::cookieName());

        if ($bearer !== null) {
            $claims = TokenSigner::verify($bearer);
            $session = $claims ? $sessions->findActive((int) $claims['sid']) : null;
            if ($session === null || (int) $session['usuario_id'] !== (int) $claims['sub'] || $session['canal'] !== 'movil') {
                return $this->reject($request, 'auth.token_invalido', 'invalid_token', 'Tu sesión expiró. Inicia sesión nuevamente.');
            }
            $via = 'bearer';
        } elseif ($cookie !== null && $cookie !== '') {
            $session = Tokens::looksValid($cookie) ? $sessions->findActiveByTokenHash(Tokens::hash($cookie)) : null;
            if ($session !== null && $this->idleExpired((string) $session['ultimo_uso_en'])) {
                $sessions->revoke((int) $session['id'], 'inactividad');
                $session = null;
            }
            if ($session === null) {
                $response = $this->reject($request, 'auth.sesion_invalida', 'session_expired', 'Tu sesión expiró. Inicia sesión nuevamente.', $next);
                return SessionService::clearCookie($response);
            }
            if (!$request->isSafeMethod()) {
                $sent = (string) $request->header('x-csrf-token', '');
                $expected = TokenSigner::csrfFor((int) $session['id'], (string) $session['token_hash']);
                if ($sent === '' || !hash_equals($expected, $sent)) {
                    Audit::denied('auth.csrf_invalido', $request, [], (int) $session['usuario_id']);
                    throw new HttpException(403, 'csrf_mismatch', 'La solicitud no pudo verificarse. Recarga la página e inténtalo de nuevo.');
                }
            }
            $via = 'cookie';
        } else {
            if ($this->optional) {
                return $next($request);
            }
            throw new HttpException(401, 'unauthenticated', 'Debes iniciar sesión.', null, ['WWW-Authenticate' => 'Bearer']);
        }

        $user = (new UserRepository())->findById((int) $session['usuario_id']);
        if ($user === null || $user['estado'] !== 'activo') {
            return $this->reject($request, 'auth.usuario_inactivo', 'unauthenticated', 'Debes iniciar sesión.', $next);
        }
        unset($user['password_hash']);

        $sessions->touch((int) $session['id'], (string) $session['ultimo_uso_en']);
        $session['id'] = (int) $session['id'];
        $session['usuario_id'] = (int) $session['usuario_id'];
        $session['tenant_id'] = $session['tenant_id'] !== null ? (int) $session['tenant_id'] : null;

        $request->withAttribute('auth_user', $user);
        $request->withAttribute('auth_session', $session);
        $request->withAttribute('auth_via', $via);
        return $next($request);
    }

    private function idleExpired(string $lastUse): bool
    {
        $idle = (int) Config::get('auth.web_idle_minutes', 120);
        return $idle > 0 && strtotime($lastUse . ' UTC') < Clock::nowUtc()->getTimestamp() - $idle * 60;
    }

    /** Credenciales presentes pero inválidas (también en modo opcional): se audita y se responde 401. */
    private function reject(Request $request, string $accion, string $code, string $message, ?callable $next = null): Response
    {
        Audit::denied($accion, $request);
        if ($next !== null && $this->optional && $this->anonymousOnInvalid) {
            return $next($request);
        }
        $response = Response::error(401, $code, $message);
        $response->setHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
        return $response;
    }
}
