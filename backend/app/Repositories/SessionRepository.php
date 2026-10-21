<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\Clock;
use App\Helpers\Ip;

/** Sesiones (web y móvil) y refresh tokens rotativos. */
final class SessionRepository extends BaseRepository
{
    /** @param array{usuario_id:int, canal:string, token_hash:?string, ip:string, user_agent:string, dispositivo:?string, expira_en:string} $data */
    public function create(array $data): int
    {
        $now = Clock::nowSql();
        return $this->insert(
            'INSERT INTO sesiones (usuario_id, canal, token_hash, nombre_dispositivo, ip, user_agent, ultimo_uso_en, expira_en, created_at)
             VALUES (:u, :canal, :hash, :disp, :ip, :ua, :uso, :exp, :c)',
            [
                'u' => $data['usuario_id'],
                'canal' => $data['canal'],
                'hash' => $data['token_hash'],
                'disp' => $data['dispositivo'],
                'ip' => Ip::toBinary($data['ip']),
                'ua' => $data['user_agent'],
                'uso' => $now,
                'exp' => $data['expira_en'],
                'c' => $now,
            ]
        );
    }

    /**
     * Sesión vigente (no revocada ni vencida) de un usuario activo.
     *
     * @return array<string, mixed>|null
     */
    public function findActive(int $id): ?array
    {
        return $this->fetchOne(
            "SELECT s.id, s.usuario_id, s.canal, s.token_hash, s.tenant_id, s.ultimo_uso_en, s.expira_en, s.created_at
               FROM sesiones s
               JOIN usuarios u ON u.id = s.usuario_id AND u.estado = 'activo' AND u.deleted_at IS NULL
              WHERE s.id = :id AND s.revocada_en IS NULL AND s.expira_en > :now",
            ['id' => $id, 'now' => Clock::nowSql()]
        );
    }

    /** @return array<string, mixed>|null */
    public function findActiveByTokenHash(string $hash): ?array
    {
        return $this->fetchOne(
            "SELECT s.id, s.usuario_id, s.canal, s.token_hash, s.tenant_id, s.ultimo_uso_en, s.expira_en, s.created_at
               FROM sesiones s
               JOIN usuarios u ON u.id = s.usuario_id AND u.estado = 'activo' AND u.deleted_at IS NULL
              WHERE s.token_hash = :h AND s.canal = 'web' AND s.revocada_en IS NULL AND s.expira_en > :now",
            ['h' => $hash, 'now' => Clock::nowSql()]
        );
    }

    /** Actualiza la última actividad como máximo una vez por minuto (evita una escritura por petición). */
    public function touch(int $id, string $lastUse): void
    {
        $now = Clock::nowUtc();
        if (strtotime($lastUse . ' UTC') > $now->getTimestamp() - 60) {
            return;
        }
        $this->execute('UPDATE sesiones SET ultimo_uso_en = :now WHERE id = :id AND expira_en >= :now2', [
            'now' => $now->format('Y-m-d H:i:s'), 'id' => $id, 'now2' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    public function revoke(int $id, string $motivo): void
    {
        $this->execute(
            'UPDATE sesiones SET revocada_en = :now, motivo_revocacion = :m WHERE id = :id AND revocada_en IS NULL',
            ['now' => Clock::nowSql(), 'm' => $motivo, 'id' => $id]
        );
    }

    public function revokeAllForUser(int $userId, string $motivo, ?int $exceptId = null): int
    {
        return $this->execute(
            'UPDATE sesiones SET revocada_en = :now, motivo_revocacion = :m
              WHERE usuario_id = :u AND revocada_en IS NULL AND id <> :except',
            ['now' => Clock::nowSql(), 'm' => $motivo, 'u' => $userId, 'except' => $exceptId ?? 0]
        );
    }

    public function setTenant(int $id, ?int $tenantId): void
    {
        $this->execute('UPDATE sesiones SET tenant_id = :t WHERE id = :id', ['t' => $tenantId, 'id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    public function listActiveForUser(int $userId): array
    {
        $rows = $this->fetchAll(
            'SELECT id, canal, nombre_dispositivo, ip, user_agent, ultimo_uso_en, expira_en, created_at
               FROM sesiones
              WHERE usuario_id = :u AND revocada_en IS NULL AND expira_en > :now
              ORDER BY ultimo_uso_en DESC',
            ['u' => $userId, 'now' => Clock::nowSql()]
        );
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['ip'] = Ip::fromBinary($row['ip']);
        }
        return $rows;
    }

    // ---------------------------------------------------------------- refresh tokens

    public function createRefreshToken(int $sessionId, string $hash, string $expiresAt): void
    {
        $this->insert(
            'INSERT INTO tokens_refresco (sesion_id, token_hash, expira_en, created_at) VALUES (:s, :h, :e, :c)',
            ['s' => $sessionId, 'h' => $hash, 'e' => $expiresAt, 'c' => Clock::nowSql()]
        );
    }

    /**
     * Busca el refresh token y bloquea su fila (debe llamarse dentro de una transacción).
     *
     * @return array<string, mixed>|null
     */
    public function findRefreshTokenForUpdate(string $hash): ?array
    {
        return $this->fetchOne(
            'SELECT t.id, t.sesion_id, t.expira_en, t.usado_en, s.usuario_id
               FROM tokens_refresco t
               JOIN sesiones s ON s.id = t.sesion_id
              WHERE t.token_hash = :h
              FOR UPDATE',
            ['h' => $hash]
        );
    }

    public function markRefreshTokenUsed(int $id): void
    {
        $this->execute('UPDATE tokens_refresco SET usado_en = :now WHERE id = :id', ['now' => Clock::nowSql(), 'id' => $id]);
    }

    /** Limpieza periódica: sesiones vencidas o revocadas hace más de 30 días (con sus refresh tokens). */
    public function purge(): int
    {
        $limit = Clock::nowUtc()->modify('-30 days')->format('Y-m-d H:i:s');
        return $this->execute(
            'DELETE FROM sesiones WHERE expira_en < :a OR (revocada_en IS NOT NULL AND revocada_en < :b)',
            ['a' => $limit, 'b' => $limit]
        );
    }
}
