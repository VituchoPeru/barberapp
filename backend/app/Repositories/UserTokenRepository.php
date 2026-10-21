<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\Clock;
use App\Helpers\Ip;

/** Tokens de un solo uso enviados por correo (verificación y recuperación/invitación). */
final class UserTokenRepository extends BaseRepository
{
    public const VERIFY_EMAIL = 'verificar_email';
    public const RESET_PASSWORD = 'restablecer_password';

    public function create(int $userId, string $tipo, string $hash, string $email, string $expiresAt, ?string $ip): void
    {
        $this->insert(
            'INSERT INTO tokens_usuario (usuario_id, tipo, token_hash, email, expira_en, ip, created_at)
             VALUES (:u, :t, :h, :e, :x, :ip, :c)',
            ['u' => $userId, 't' => $tipo, 'h' => $hash, 'e' => $email, 'x' => $expiresAt, 'ip' => $ip ? Ip::toBinary($ip) : null, 'c' => Clock::nowSql()]
        );
    }

    /** Invalida los tokens pendientes del mismo tipo (solo vale el último enviado). */
    public function invalidatePending(int $userId, string $tipo): void
    {
        $this->execute(
            'UPDATE tokens_usuario SET usado_en = :now WHERE usuario_id = :u AND tipo = :t AND usado_en IS NULL',
            ['now' => Clock::nowSql(), 'u' => $userId, 't' => $tipo]
        );
    }

    /**
     * Token vigente: no usado, no vencido y emitido para el correo actual del usuario. Bloquea la fila.
     *
     * @return array<string, mixed>|null
     */
    public function findValidForUpdate(string $hash, string $tipo): ?array
    {
        return $this->fetchOne(
            "SELECT t.id, t.usuario_id, t.email, u.email AS email_actual, u.email_verificado_en
               FROM tokens_usuario t
               JOIN usuarios u ON u.id = t.usuario_id AND u.deleted_at IS NULL AND u.estado = 'activo'
              WHERE t.token_hash = :h AND t.tipo = :t AND t.usado_en IS NULL AND t.expira_en > :now
                AND t.email = u.email
              FOR UPDATE",
            ['h' => $hash, 't' => $tipo, 'now' => Clock::nowSql()]
        );
    }

    public function markUsed(int $id): void
    {
        $this->execute('UPDATE tokens_usuario SET usado_en = :now WHERE id = :id', ['now' => Clock::nowSql(), 'id' => $id]);
    }

    public function purge(): int
    {
        return $this->execute(
            'DELETE FROM tokens_usuario WHERE expira_en < :limit',
            ['limit' => Clock::nowUtc()->modify('-7 days')->format('Y-m-d H:i:s')]
        );
    }
}
