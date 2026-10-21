<?php

declare(strict_types=1);

namespace App\Repositories;

/** Barberías favoritas de un cliente (tabla favoritos). */
final class FavoriteRepository extends BaseRepository
{
    public function clienteIdForUser(int $userId): ?int
    {
        $row = $this->fetchOne('SELECT id FROM clientes WHERE usuario_id = :u AND deleted_at IS NULL', ['u' => $userId]);
        return $row === null ? null : (int) $row['id'];
    }

    /**
     * Perfil de cliente del usuario; se crea si la cuenta aún no lo tiene
     * (p. ej. un propietario que también quiere guardar barberías).
     *
     * @param array<string, mixed> $user
     */
    public function ensureCliente(array $user): int
    {
        $id = $this->clienteIdForUser((int) $user['id']);
        if ($id !== null) {
            return $id;
        }
        return (new UserRepository($this->db))->createClienteProfile((int) $user['id'], [
            'nombres' => (string) $user['nombres'],
            'apellidos' => $user['apellidos'] ?? null,
            'telefono' => $user['telefono'] ?? null,
            'email' => (string) $user['email'],
        ]);
    }

    public function add(int $clienteId, int $tenantId): bool
    {
        return $this->execute('INSERT IGNORE INTO favoritos (cliente_id, tenant_id) VALUES (:c, :t)', ['c' => $clienteId, 't' => $tenantId]) > 0;
    }

    public function remove(int $clienteId, int $tenantId): bool
    {
        return $this->execute('DELETE FROM favoritos WHERE cliente_id = :c AND tenant_id = :t', ['c' => $clienteId, 't' => $tenantId]) > 0;
    }

    public function count(int $clienteId): int
    {
        return (int) ($this->fetchOne('SELECT COUNT(*) AS n FROM favoritos WHERE cliente_id = :c', ['c' => $clienteId])['n'] ?? 0);
    }

    public function isFavorite(int $userId, int $tenantId): bool
    {
        return $this->fetchOne(
            'SELECT 1 FROM favoritos f JOIN clientes c ON c.id = f.cliente_id
              WHERE c.usuario_id = :u AND c.deleted_at IS NULL AND f.tenant_id = :t',
            ['u' => $userId, 't' => $tenantId]
        ) !== null;
    }

    /**
     * Favoritas disponibles (barbería activa), más recientes primero. Las barberías suspendidas
     * se ocultan pero el favorito se conserva por si vuelven a activarse.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(int $clienteId): array
    {
        return $this->fetchAll(
            "SELECT t.id, t.slug, t.nombre_comercial, t.descripcion, t.logo_url, t.portada_url, t.color_primario,
                    (SELECT MIN(s.precio) FROM servicios s WHERE s.tenant_id = t.id AND s.activo = 1 AND s.deleted_at IS NULL) AS precio_desde,
                    (SELECT s.moneda FROM servicios s WHERE s.tenant_id = t.id AND s.activo = 1 AND s.deleted_at IS NULL ORDER BY s.precio LIMIT 1) AS moneda,
                    NULL AS distancia_m, f.created_at AS guardada_en
               FROM favoritos f
               JOIN tenants t ON t.id = f.tenant_id
              WHERE f.cliente_id = :c AND t.estado = 'activo' AND t.deleted_at IS NULL
              ORDER BY f.created_at DESC, t.id DESC",
            ['c' => $clienteId]
        );
    }

    /** @return list<int> ids de barberías favoritas del usuario (para marcar tarjetas). */
    public function tenantIdsForUser(int $userId): array
    {
        $rows = $this->fetchAll(
            'SELECT f.tenant_id FROM favoritos f JOIN clientes c ON c.id = f.cliente_id WHERE c.usuario_id = :u AND c.deleted_at IS NULL',
            ['u' => $userId]
        );
        return array_map(static fn (array $r): int => (int) $r['tenant_id'], $rows);
    }
}
