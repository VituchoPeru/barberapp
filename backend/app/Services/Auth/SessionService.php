<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Clock;
use App\Helpers\Tokens;
use App\Repositories\SessionRepository;
use App\Services\Audit;

/**
 * Emisión y renovación de sesiones.
 *
 * Web   → cookie HttpOnly con un token opaco (en BD solo su SHA-256) + token CSRF derivado.
 * Móvil → access token HS256 de 15 min + refresh token opaco rotativo (un solo uso).
 */
final class SessionService
{
    private SessionRepository $sessions;

    public function __construct(?SessionRepository $sessions = null)
    {
        $this->sessions = $sessions ?? new SessionRepository();
    }

    /**
     * @param array<string, mixed> $user Fila de usuarios
     * @return array{data: array<string, mixed>, cookie: ?string, expires: int}
     */
    public function issue(array $user, string $canal, Request $request, ?string $dispositivo = null): array
    {
        $now = Clock::nowUtc();
        $userId = (int) $user['id'];

        if ($canal === 'web') {
            $token = Tokens::random();
            $hash = Tokens::hash($token);
            $expires = $now->modify('+' . (int) Config::get('auth.web_session_hours', 168) . ' hours');
            $id = $this->sessions->create([
                'usuario_id' => $userId, 'canal' => 'web', 'token_hash' => $hash, 'ip' => $request->ip(),
                'user_agent' => $request->userAgent(), 'dispositivo' => $dispositivo, 'expira_en' => $expires->format('Y-m-d H:i:s'),
            ]);
            return [
                'data' => [
                    'sesion' => ['id' => $id, 'canal' => 'web', 'expira_en' => $expires->format('Y-m-d\TH:i:s\Z')],
                    'csrf_token' => TokenSigner::csrfFor($id, $hash),
                ],
                'cookie' => $token,
                'expires' => $expires->getTimestamp(),
            ];
        }

        $expires = $now->modify('+' . (int) Config::get('auth.mobile_session_days', 90) . ' days');
        $id = $this->sessions->create([
            'usuario_id' => $userId, 'canal' => 'movil', 'token_hash' => null, 'ip' => $request->ip(),
            'user_agent' => $request->userAgent(), 'dispositivo' => $dispositivo, 'expira_en' => $expires->format('Y-m-d H:i:s'),
        ]);
        return [
            'data' => ['sesion' => ['id' => $id, 'canal' => 'movil', 'expira_en' => $expires->format('Y-m-d\TH:i:s\Z')]]
                + $this->mobileTokens($userId, $id, $expires->format('Y-m-d H:i:s')),
            'cookie' => null,
            'expires' => $expires->getTimestamp(),
        ];
    }

    /** @return array<string, mixed> access_token + refresh_token nuevos para una sesión móvil */
    private function mobileTokens(int $userId, int $sessionId, string $sessionExpiresAt): array
    {
        $now = Clock::nowUtc();
        $ttl = (int) Config::get('auth.access_ttl', 900);
        $access = TokenSigner::sign([
            'iss' => (string) Config::get('app.url'),
            'sub' => $userId,
            'sid' => $sessionId,
            'typ' => 'access',
            'iat' => $now->getTimestamp(),
            'exp' => $now->getTimestamp() + $ttl,
            'jti' => bin2hex(random_bytes(8)),
        ]);

        $refresh = Tokens::random();
        $refreshExpires = $now->modify('+' . (int) Config::get('auth.refresh_ttl_days', 30) . ' days')->format('Y-m-d H:i:s');
        if ($refreshExpires > $sessionExpiresAt) {
            $refreshExpires = $sessionExpiresAt;
        }
        $this->sessions->createRefreshToken($sessionId, Tokens::hash($refresh), $refreshExpires);

        return [
            'token_type' => 'Bearer',
            'access_token' => $access,
            'expires_in' => $ttl,
            'refresh_token' => $refresh,
            'refresh_expira_en' => str_replace(' ', 'T', $refreshExpires) . 'Z',
        ];
    }

    /**
     * Rota el refresh token. Si se presenta uno ya usado (posible robo) se revoca toda la sesión.
     *
     * @return array<string, mixed>
     */
    public function refresh(string $refreshToken, Request $request): array
    {
        $invalid = static fn (): HttpException => new HttpException(401, 'invalid_refresh_token', 'La sesión expiró. Inicia sesión nuevamente.');
        if (!Tokens::looksValid($refreshToken)) {
            Audit::denied('auth.refresh_invalido', $request);
            throw $invalid();
        }

        $outcome = Database::transaction(function () use ($refreshToken): array {
            $row = $this->sessions->findRefreshTokenForUpdate(Tokens::hash($refreshToken));
            if ($row === null) {
                return ['error' => 'no_existe'];
            }
            if ($row['usado_en'] !== null) {
                $this->sessions->revoke((int) $row['sesion_id'], 'refresh_reutilizado');
                return ['error' => 'reutilizado', 'usuario_id' => (int) $row['usuario_id'], 'sesion_id' => (int) $row['sesion_id']];
            }
            $session = $this->sessions->findActive((int) $row['sesion_id']);
            if ($session === null || $row['expira_en'] <= Clock::nowSql()) {
                return ['error' => 'vencido', 'usuario_id' => (int) $row['usuario_id']];
            }
            $this->sessions->markRefreshTokenUsed((int) $row['id']);
            $this->sessions->touch((int) $session['id'], '1970-01-01 00:00:00');
            $tokens = $this->mobileTokens((int) $session['usuario_id'], (int) $session['id'], (string) $session['expira_en']);
            return ['ok' => true, 'usuario_id' => (int) $session['usuario_id'], 'sesion_id' => (int) $session['id'], 'tokens' => $tokens,
                'expira_en' => (string) $session['expira_en']];
        });

        if (!isset($outcome['ok'])) {
            // La auditoría se escribe fuera de la transacción (también cuando hubo revocación).
            Audit::denied('auth.refresh_' . $outcome['error'], $request, [], $outcome['usuario_id'] ?? null);
            throw $invalid();
        }
        return ['sesion' => ['id' => $outcome['sesion_id'], 'canal' => 'movil', 'expira_en' => str_replace(' ', 'T', $outcome['expira_en']) . 'Z']]
            + $outcome['tokens'];
    }

    // ---------------------------------------------------------------- cookies

    public static function cookieName(): string
    {
        return (string) Config::get('auth.cookie.name', 'barberapp_session');
    }

    public static function attachCookie(Response $response, string $token, int $expires): Response
    {
        $cfg = (array) Config::get('auth.cookie', []);
        return $response->withCookie(self::cookieName(), $token, [
            'expires' => $expires,
            'path' => $cfg['path'] ?? '/',
            'domain' => $cfg['domain'] ?? '',
            'secure' => (bool) ($cfg['secure'] ?? true),
            'httponly' => true,
            'samesite' => $cfg['samesite'] ?? 'Lax',
        ]);
    }

    public static function clearCookie(Response $response): Response
    {
        return self::attachCookie($response, '', 1);
    }
}
