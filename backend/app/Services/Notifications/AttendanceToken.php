<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Helpers\Tokens;
use App\Services\Auth\TokenSigner;

/**
 * Enlace firmado "Confirmar asistencia" de los recordatorios (correo y push).
 *
 * Token = base64url(payload) . "." . base64url(HMAC-SHA256(APP_KEY, "asistencia|" . payload)).
 * El payload lleva barbería, reserva y la hora de inicio vigente cuando se envió el aviso:
 *  - si la cita se reprograma, la hora ya no coincide y el enlace deja de servir;
 *  - vence a la hora de la cita (después ya no tiene sentido confirmar).
 * Solo permite confirmar la asistencia de esa cita: no inicia sesión ni muestra otros datos.
 */
final class AttendanceToken
{
    public static function issue(int $tenantId, int $reservaId, string $inicioUtc): string
    {
        $payload = Tokens::base64url((string) json_encode(['t' => $tenantId, 'r' => $reservaId, 'i' => strtotime($inicioUtc . ' UTC')]));
        return $payload . '.' . self::sign($payload);
    }

    /** @return array{tenant_id:int, reserva_id:int, inicio_ts:int}|null */
    public static function parse(string $token): ?array
    {
        if (strlen($token) > 300 || !preg_match('/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/', $token)) {
            return null;
        }
        [$payload, $sig] = explode('.', $token, 2);
        if (!hash_equals(self::sign($payload), $sig)) {
            return null;
        }
        $d = json_decode((string) Tokens::base64urlDecode($payload), true);
        if (!is_array($d) || !is_int($d['t'] ?? null) || !is_int($d['r'] ?? null) || !is_int($d['i'] ?? null)) {
            return null;
        }
        return ['tenant_id' => $d['t'], 'reserva_id' => $d['r'], 'inicio_ts' => $d['i']];
    }

    private static function sign(string $payload): string
    {
        return Tokens::base64url(hash_hmac('sha256', 'asistencia|' . $payload, TokenSigner::key(), true));
    }
}
