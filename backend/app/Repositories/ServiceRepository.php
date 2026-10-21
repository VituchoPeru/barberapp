<?php

declare(strict_types=1);

namespace App\Repositories;

/** Servicios y categorías de servicio de una barbería (panel). Siempre filtrado por tenant. */
final class ServiceRepository extends TenantScopedRepository
{
    public const CAMPOS = ['categoria_id', 'tipo_servicio_id', 'nombre', 'descripcion', 'duracion_minutos', 'precio', 'activo', 'orden'];

    // ---------------------------------------------------------------- categorías

    /** @return list<array<string, mixed>> */
    public function categories(): array
    {
        return $this->fetchAll(
            'SELECT c.id, c.nombre, c.orden, c.activo,
                    (SELECT COUNT(*) FROM servicios s WHERE s.tenant_id = c.tenant_id AND s.categoria_id = c.id AND s.deleted_at IS NULL) AS servicios
               FROM categorias_servicio c WHERE c.tenant_id = :t ORDER BY c.orden, c.nombre',
            ['t' => $this->tenantId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findCategory(int $id): ?array
    {
        return $this->fetchOne('SELECT id, nombre, orden, activo FROM categorias_servicio WHERE tenant_id = :t AND id = :id', ['t' => $this->tenantId, 'id' => $id]);
    }

    public function categoryNameExists(string $nombre, ?int $exceptId = null): bool
    {
        return $this->fetchOne(
            'SELECT 1 FROM categorias_servicio WHERE tenant_id = :t AND nombre = :n AND id <> :id LIMIT 1',
            ['t' => $this->tenantId, 'n' => $nombre, 'id' => $exceptId ?? 0]
        ) !== null;
    }

    public function createCategory(string $nombre, int $orden, bool $activo): int
    {
        return $this->insert(
            'INSERT INTO categorias_servicio (tenant_id, nombre, orden, activo) VALUES (:t, :n, :o, :a)',
            ['t' => $this->tenantId, 'n' => $nombre, 'o' => $orden, 'a' => (int) $activo]
        );
    }

    /** @param array<string, mixed> $data */
    public function updateCategory(int $id, array $data): void
    {
        $sets = [];
        $params = ['t' => $this->tenantId, 'id' => $id];
        foreach (['nombre', 'orden', 'activo'] as $k) {
            if (array_key_exists($k, $data)) {
                $sets[] = "{$k} = :{$k}";
                $params[$k] = is_bool($data[$k]) ? (int) $data[$k] : $data[$k];
            }
        }
        if ($sets !== []) {
            $this->execute('UPDATE categorias_servicio SET ' . implode(', ', $sets) . ' WHERE tenant_id = :t AND id = :id', $params);
        }
    }

    public function deleteCategory(int $id): void
    {
        // Los servicios eliminados (soft delete) que aún la referencian quedan sin categoría.
        $this->execute('UPDATE servicios SET categoria_id = NULL WHERE tenant_id = :t AND categoria_id = :id AND deleted_at IS NOT NULL', ['t' => $this->tenantId, 'id' => $id]);
        $this->execute('DELETE FROM categorias_servicio WHERE tenant_id = :t AND id = :id', ['t' => $this->tenantId, 'id' => $id]);
    }

    public function nextCategoryOrder(): int
    {
        return (int) ($this->fetchOne('SELECT COALESCE(MAX(orden), 0) + 1 AS o FROM categorias_servicio WHERE tenant_id = :t', ['t' => $this->tenantId])['o'] ?? 1);
    }

    // ---------------------------------------------------------------- servicios

    /** @return list<array<string, mixed>> todos los servicios no eliminados (activos e inactivos) */
    public function all(): array
    {
        return $this->fetchAll(
            'SELECT s.id, s.nombre, s.descripcion, s.duracion_minutos, s.precio, s.moneda, s.activo, s.orden,
                    s.categoria_id, c.nombre AS categoria, ts.codigo AS tipo, ts.nombre AS tipo_nombre,
                    (SELECT COUNT(*) FROM barbero_servicios bs JOIN barberos b ON b.tenant_id = bs.tenant_id AND b.id = bs.barbero_id
                      WHERE bs.tenant_id = s.tenant_id AND bs.servicio_id = s.id AND b.deleted_at IS NULL AND b.activo = 1) AS barberos
               FROM servicios s
               LEFT JOIN categorias_servicio c ON c.tenant_id = s.tenant_id AND c.id = s.categoria_id
               LEFT JOIN tipos_servicio ts ON ts.id = s.tipo_servicio_id
              WHERE s.tenant_id = :t AND s.deleted_at IS NULL
              ORDER BY c.orden IS NULL, c.orden, s.orden, s.nombre',
            ['t' => $this->tenantId]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT s.id, s.nombre, s.descripcion, s.duracion_minutos, s.precio, s.moneda, s.activo, s.orden,
                    s.categoria_id, c.nombre AS categoria, ts.codigo AS tipo, ts.nombre AS tipo_nombre
               FROM servicios s
               LEFT JOIN categorias_servicio c ON c.tenant_id = s.tenant_id AND c.id = s.categoria_id
               LEFT JOIN tipos_servicio ts ON ts.id = s.tipo_servicio_id
              WHERE s.tenant_id = :t AND s.id = :id AND s.deleted_at IS NULL',
            ['t' => $this->tenantId, 'id' => $id]
        );
    }

    /**
     * Servicios no eliminados con los ids indicados.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $rows = $this->fetchAll(
            "SELECT id, nombre, duracion_minutos, precio, activo FROM servicios WHERE tenant_id = :t AND deleted_at IS NULL AND id IN ({$in})",
            ['t' => $this->tenantId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    public function nameExists(string $nombre, ?int $exceptId = null): bool
    {
        return $this->fetchOne(
            'SELECT 1 FROM servicios WHERE tenant_id = :t AND nombre = :n AND deleted_at IS NULL AND id <> :id LIMIT 1',
            ['t' => $this->tenantId, 'n' => $nombre, 'id' => $exceptId ?? 0]
        ) !== null;
    }

    public function tipoId(string $codigo): ?int
    {
        $row = $this->fetchOne('SELECT id FROM tipos_servicio WHERE codigo = :c AND activo = 1', ['c' => $codigo]);
        return $row === null ? null : (int) $row['id'];
    }

    /** @return list<array<string, mixed>> */
    public function tipos(): array
    {
        return $this->fetchAll('SELECT codigo, nombre FROM tipos_servicio WHERE activo = 1 ORDER BY orden');
    }

    public function nextOrder(): int
    {
        return (int) ($this->fetchOne('SELECT COALESCE(MAX(orden), 0) + 1 AS o FROM servicios WHERE tenant_id = :t AND deleted_at IS NULL', ['t' => $this->tenantId])['o'] ?? 1);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $data = array_intersect_key($data, array_flip(self::CAMPOS));
        $cols = array_keys($data);
        $params = ['tenant_id' => $this->tenantId];
        foreach ($data as $k => $v) {
            $params[$k] = is_bool($v) ? (int) $v : $v;
        }
        return $this->insert(
            'INSERT INTO servicios (tenant_id, ' . implode(', ', $cols) . ') VALUES (:tenant_id, :' . implode(', :', $cols) . ')',
            $params
        );
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $sets = [];
        $params = ['t' => $this->tenantId, 'id' => $id];
        foreach ($data as $k => $v) {
            if (!in_array($k, self::CAMPOS, true)) {
                continue;
            }
            $sets[] = "{$k} = :{$k}";
            $params[$k] = is_bool($v) ? (int) $v : $v;
        }
        if ($sets !== []) {
            $this->execute('UPDATE servicios SET ' . implode(', ', $sets) . ' WHERE tenant_id = :t AND id = :id AND deleted_at IS NULL', $params);
        }
    }

    /**
     * Eliminación lógica: las reservas pasadas conservan su copia (nombre, precio, duración).
     * Se quita de los barberos para que no se pueda volver a ofrecer.
     */
    public function softDelete(int $id): void
    {
        $this->execute('UPDATE servicios SET deleted_at = UTC_TIMESTAMP(), activo = 0 WHERE tenant_id = :t AND id = :id AND deleted_at IS NULL', ['t' => $this->tenantId, 'id' => $id]);
        $this->execute('DELETE FROM barbero_servicios WHERE tenant_id = :t AND servicio_id = :id', ['t' => $this->tenantId, 'id' => $id]);
    }

    public function futureBookingsCount(int $id, string $nowUtc): int
    {
        return (int) ($this->fetchOne(
            'SELECT COUNT(DISTINCT r.id) AS n FROM reserva_servicios rs
               JOIN reservas r ON r.tenant_id = rs.tenant_id AND r.id = rs.reserva_id
              WHERE rs.tenant_id = :t AND rs.servicio_id = :id AND r.ocupa_agenda = 1 AND r.fin_utc > :now',
            ['t' => $this->tenantId, 'id' => $id, 'now' => $nowUtc]
        )['n'] ?? 0);
    }
}
