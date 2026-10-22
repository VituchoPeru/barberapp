<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Core\Config;

/**
 * Proxies de confianza (TRUSTED_PROXIES): solo si la conexión llega desde uno de ellos se
 * leen X-Forwarded-For y X-Forwarded-Proto. Así nadie puede falsear su IP (y saltarse los
 * límites de intentos) ni fingir HTTPS enviando esas cabeceras directamente.
 */
final class TrustedProxies
{
    /** @return list<string> */
    public static function list(): array
    {
        return array_values(array_filter((array) Config::get('security.trusted_proxies', []), 'is_string'));
    }

    public static function isTrusted(string $ip): bool
    {
        foreach (self::list() as $range) {
            if (self::inRange($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    /** IP o CIDR (IPv4 e IPv6). Rangos mal escritos nunca coinciden. */
    public static function inRange(string $ip, string $range): bool
    {
        $range = trim($range);
        if ($range === '') {
            return false;
        }
        [$subnet, $bits] = str_contains($range, '/') ? explode('/', $range, 2) : [$range, null];
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($subnet);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $max = strlen($ipBin) * 8;
        if ($bits === null) {
            $bits = $max;
        } elseif (!ctype_digit($bits) || (int) $bits > $max) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    /**
     * IP del cliente: se recorre X-Forwarded-For de derecha a izquierda saltando proxies de
     * confianza; la primera IP que no es de confianza es el cliente.
     */
    public static function clientIp(string $remote, ?string $forwardedFor): string
    {
        if ($forwardedFor === null || $forwardedFor === '' || !self::isTrusted($remote)) {
            return $remote;
        }
        $hops = array_reverse(array_map('trim', explode(',', $forwardedFor)));
        foreach ($hops as $hop) {
            if (@inet_pton($hop) === false) {
                return $remote; // cabecera malformada: no se confía en ella
            }
            if (!self::isTrusted($hop)) {
                return $hop;
            }
        }
        return $remote;
    }
}
