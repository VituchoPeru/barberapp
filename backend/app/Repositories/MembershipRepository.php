<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\Clock;

/** Membresías (rol de un usuario dentro de una barbería) y permisos por rol. */
final class MembershipRepository extends BaseRepository
{
    /**
     * Membresías del usuario agrupadas por barbería.
     *
     * @param list<string> $estados
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId, array $estados = ['activo', 'invitado']): array
    {
        $in = implode(',', array_map(static fn (int $i): string => ':e' . $i, array_keys($estados)));
        $params = ['u' => $userId];
        foreach ($estados as $i => $estado) {
            $params['e' . $i] = $estado;
        }
        $rows = $this->fetchAll(
            "SELECT m.id, m.estado, m.aceptado_en, m.created_at, r.codigo AS rol, r.nombre AS rol_nombre,
                    t.id AS tenant_id, t.slug, t.nombre_comercial, t.estado AS tenant_estado, t.logo_url
               FROM membresias m
               JOIN roles r   ON r.id = m.rol_id
               JOIN tenants t ON t.id = m.tenant_id AND t.deleted_at IS NULL
              WHERE m.usuario_id = :u AND m.estado IN ({$in})
              ORDER BY t.nombre_comercial, r.id",
            $params
        );

        $byTenant = [];
        foreach ($rows as $row) {
            $tid = (int) $row['tenant_id'];
            if (!isset($byTenant[$tid])) {
                $byTenant[$tid] = [
                    'tenant' => [
                        'id' => $tid,
                        'slug' => $row['slug'],
                        'nombre_comercial' => $row['nombre_comercial'],
                        'estado' => $row['tenant_estado'],
                        'logo_url' => $row['logo_url'],
                    ],
                    'roles' => [],
                    'invitaciones' => [],
                ];
            }
            if ($row['estado'] === 'activo') {
                $byTenant[$tid]['roles'][] = $row['rol'];
            } else {
                $byTenant[$tid]['invitaciones'][] = ['membresia_id' => (int) $row['id'], 'rol' => $row['rol'], 'enviada_en' => $row['created_at']];
            }
        }
        $result = [];
        foreach ($byTenant as $item) {
            $item['permisos'] = $this->permissionsForRoles($item['roles']);
            $result[] = $item;
        }
        return $result;
    }

    /** @return list<string> Roles activos del usuario en la barbería. */
    public function activeRoles(int $userId, int $tenantId): array
    {
        $rows = $this->fetchAll(
            "SELECT DISTINCT r.codigo FROM membresias m JOIN roles r ON r.id = m.rol_id
              WHERE m.usuario_id = :u AND m.tenant_id = :t AND m.estado = 'activo'",
            ['u' => $userId, 't' => $tenantId]
        );
        return array_map(static fn (array $r): string => (string) $r['codigo'], $rows);
    }

    /**
     * @param list<string> $roles
     * @return list<string>
     */
    public function permissionsForRoles(array $roles): array
    {
        if ($roles === []) {
            return [];
        }
        $params = [];
        $in = [];
        foreach (array_values($roles) as $i => $rol) {
            $in[] = ':r' . $i;
            $params['r' . $i] = $rol;
        }
        $rows = $this->fetchAll(
            'SELECT DISTINCT p.codigo FROM rol_permisos rp
               JOIN roles r ON r.id = rp.rol_id
               JOIN permisos p ON p.id = rp.permiso_id
              WHERE r.codigo IN (' . implode(',', $in) . ')
              ORDER BY p.codigo',
            $params
        );
        return array_map(static fn (array $r): string => (string) $r['codigo'], $rows);
    }

    /** @return list<string> Permisos del rol de plataforma (superadmin), si tiene. */
    public function platformPermissions(?int $rolPlataformaId): array
    {
        if ($rolPlataformaId === null) {
            return [];
        }
        $rows = $this->fetchAll(
            "SELECT p.codigo FROM rol_permisos rp
               JOIN roles r ON r.id = rp.rol_id AND r.ambito = 'plataforma'
               JOIN permisos p ON p.id = rp.permiso_id
              WHERE rp.rol_id = :r ORDER BY p.codigo",
            ['r' => $rolPlataformaId]
        );
        return array_map(static fn (array $r): string => (string) $r['codigo'], $rows);
    }

    public function roleId(string $codigo): ?int
    {
        $row = $this->fetchOne('SELECT id FROM roles WHERE codigo = :c', ['c' => $codigo]);
        return $row ? (int) $row['id'] : null;
    }

    public function roleCode(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $row = $this->fetchOne('SELECT codigo FROM roles WHERE id = :id', ['id' => $id]);
        return $row ? (string) $row['codigo'] : null;
    }

    /** Crea o reactiva una membresía (la combinación usuario + barbería + rol es única). Devuelve su id. */
    public function upsert(int $tenantId, int $userId, string $rol, string $estado, ?int $invitedBy): int
    {
        $rolId = $this->roleId($rol);
        if ($rolId === null) {
            throw new \InvalidArgumentException("Rol desconocido: {$rol}");
        }
        $now = Clock::nowSql();
        $existing = $this->fetchOne(
            'SELECT id, estado FROM membresias WHERE usuario_id = :u AND tenant_id = :t AND rol_id = :r FOR UPDATE',
            ['u' => $userId, 't' => $tenantId, 'r' => $rolId]
        );
        if ($existing) {
            $this->execute(
                'UPDATE membresias SET estado = :e, invitado_por = :i, aceptado_en = :a WHERE id = :id',
                ['e' => $estado, 'i' => $invitedBy, 'a' => $estado === 'activo' ? $now : null, 'id' => $existing['id']]
            );
            return (int) $existing['id'];
        }
        return $this->insert(
            'INSERT INTO membresias (tenant_id, usuario_id, rol_id, estado, invitado_por, aceptado_en, created_at, updated_at)
             VALUES (:t, :u, :r, :e, :i, :a, :c, :up)',
            ['t' => $tenantId, 'u' => $userId, 'r' => $rolId, 'e' => $estado, 'i' => $invitedBy, 'a' => $estado === 'activo' ? $now : null, 'c' => $now, 'up' => $now]
        );
    }

    /**
     * Membresía de una barbería concreta (para gestionar personal). Siempre filtra por tenant.
     *
     * @return array<string, mixed>|null
     */
    public function findInTenant(int $tenantId, int $membershipId): ?array
    {
        return $this->fetchOne(
            'SELECT m.id, m.usuario_id, m.estado, r.codigo AS rol FROM membresias m JOIN roles r ON r.id = m.rol_id
              WHERE m.tenant_id = :t AND m.id = :id',
            ['t' => $tenantId, 'id' => $membershipId]
        );
    }

    /** @return array<string, mixed>|null Membresía del propio usuario (para aceptar o rechazar invitaciones). */
    public function findForUser(int $userId, int $membershipId): ?array
    {
        return $this->fetchOne(
            'SELECT m.id, m.tenant_id, m.estado, r.codigo AS rol FROM membresias m JOIN roles r ON r.id = m.rol_id
              WHERE m.usuario_id = :u AND m.id = :id FOR UPDATE',
            ['u' => $userId, 'id' => $membershipId]
        );
    }

    public function setStatus(int $membershipId, string $estado): void
    {
        $this->execute(
            'UPDATE membresias SET estado = :e, aceptado_en = IF(:e2 = \'activo\', :now, aceptado_en) WHERE id = :id',
            ['e' => $estado, 'e2' => $estado, 'now' => Clock::nowSql(), 'id' => $membershipId]
        );
    }

    public function countActiveOwners(int $tenantId): int
    {
        $row = $this->fetchOne(
            "SELECT COUNT(*) AS n FROM membresias m JOIN roles r ON r.id = m.rol_id
              WHERE m.tenant_id = :t AND r.codigo = 'propietario' AND m.estado = 'activo'",
            ['t' => $tenantId]
        );
        return (int) ($row['n'] ?? 0);
    }

    /** @return list<array<string, mixed>> Personal de la barbería. */
    public function staff(int $tenantId): array
    {
        $rows = $this->fetchAll(
            "SELECT m.id AS membresia_id, m.estado, m.aceptado_en, m.created_at, r.codigo AS rol,
                    u.id AS usuario_id, u.nombres, u.apellidos, u.email
               FROM membresias m
               JOIN roles r ON r.id = m.rol_id
               JOIN usuarios u ON u.id = m.usuario_id AND u.deleted_at IS NULL
              WHERE m.tenant_id = :t AND m.estado IN ('activo','invitado','suspendido')
              ORDER BY FIELD(r.codigo, 'propietario','administrador','barbero'), u.nombres",
            ['t' => $tenantId]
        );
        foreach ($rows as &$row) {
            $row['membresia_id'] = (int) $row['membresia_id'];
            $row['usuario_id'] = (int) $row['usuario_id'];
        }
        return $rows;
    }
}
