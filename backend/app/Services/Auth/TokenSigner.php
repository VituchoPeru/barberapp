<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Helpers\Clock;
use App\Helpers\Tokens;
use RuntimeException;

/**
 * Access tokens firmados con HMAC-SHA256 (formato JWT compacto, alg HS256 fijo).
 * No se acepta ningún otro algoritmo ni "none". La clave sale de APP_KEY.
 */
final class TokenSigner
{
    public static function key(): string
    {
        $raw = (string) Config::get('auth.key', '');
        if (str_starts_with($raw, 'base64:')) {
            $raw = (string) base64_decode(substr($raw, 7), true);
        }
        if (strlen($raw) < 32) {
            throw new RuntimeException('APP_KEY no está configurada o es demasiado corta. Ejecute: php bin/console key:generate');
        }
        return $raw;
    }

    /** @param array<string, mixed> $claims */
    public static function sign(array $claims): string
    {
        $header = Tokens::base64url((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = Tokens::base64url((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
        $signature = Tokens::base64url(hash_hmac('sha256', $header . '.' . $payload, self::key(), true));
        return $header . '.' . $payload . '.' . $signature;
    }

    /**
     * Devuelve los claims si la firma es válida y el token no venció; null en cualquier otro caso.
     *
     * @return array<string, mixed>|null
     */
    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || strlen($token) > 2048) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $header = json_decode((string) Tokens::base64urlDecode($h), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256' || ($header['typ'] ?? null) !== 'JWT') {
            return null;
        }
        $expected = Tokens::base64url(hash_hmac('sha256', $h . '.' . $p, self::key(), true));
        if (!hash_equals($expected, $s)) {
            return null;
        }
        $claims = json_decode((string) Tokens::base64urlDecode($p), true);
        if (!is_array($claims) || !isset($claims['exp'], $claims['sub'], $claims['sid'])) {
            return null;
        }
        $now = Clock::nowUtc()->getTimestamp();
        if ((int) $claims['exp'] <= $now || (isset($claims['nbf']) && (int) $claims['nbf'] > $now + 30)) {
            return null;
        }
        if (($claims['typ'] ?? null) !== 'access') {
            return null;
        }
        return $claims;
    }

    /** Token CSRF de una sesión web: HMAC de la sesión, no necesita almacenarse. */
    public static function csrfFor(int $sessionId, string $sessionTokenHash): string
    {
        return Tokens::base64url(hash_hmac('sha256', 'csrf|' . $sessionId . '|' . $sessionTokenHash, self::key(), true));
    }
}
