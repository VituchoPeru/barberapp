<?php

declare(strict_types=1);

namespace App\Repositories;

/** Sucursales de una barbería y su horario comercial. Siempre filtrado por tenant. */
final class BranchRepository extends TenantScopedRepository
{
    public const CAMPOS = [
        'nombre', 'slug', 'direccion', 'referencia', 'distrito', 'provincia', 'departamento', 'ubigeo',
        'latitud', 'longitud', 'telefono', 'email', 'zona_horaria', 'es_principal', 'activo',
    ];

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->fetchAll(
            'SELECT su.id, su.nombre, su.slug, su.direccion, su.referencia, su.distrito, su.provincia, su.departamento, su.ubigeo,
                    su.latitud, su.longitud, su.telefono, su.email, su.zona_horaria, su.es_principal, su.activo,
                    (SELECT COUNT(*) FROM barberos b WHERE b.tenant_id = su.tenant_id AND b.sucursal_id = su.id AND b.deleted_at IS NULL AND b.activo = 1) AS barberos_activos
               FROM sucursales su
              WHERE su.tenant_id = :t AND su.deleted_at IS NULL
              ORDER BY su.es_principal DESC, su.activo DESC, su.nombre',
            ['t' => $this->tenantId]
        );
        $hours = $this->hours(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        foreach ($rows as &$r) {
            $r['horario'] = $hours[(int) $r['id']] ?? [];
        }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->fetchOne(
            'SELECT id, nombre, slug, direccion, referencia, distrito, provincia, departamento, ubigeo, latitud, longitud,
                    telefono, email, zona_horaria, es_principal, activo
               FROM sucursales WHERE tenant_id = :t AND id = :id AND deleted_at IS NULL',
            ['t' => $this->tenantId, 'id' => $id]
        );
        if ($row !== null) {
            $row['horario'] = $this->hours([$id])[$id] ?? [];
        }
        return $row;
    }

    /**
     * Horario comercial por sucursal.
     *
     * @param list<int> $ids
     * @return array<int, list<array{dia_semana:int, hora_apertura:string, hora_cierre:string}>>
     */
    public function hours(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $rows = $this->fetchAll(
            "SELECT sucursal_id, dia_semana, TIME_FORMAT(hora_apertura, '%H:%i') AS hora_apertura, TIME_FORMAT(hora_cierre, '%H:%i') AS hora_cierre
               FROM horarios_sucursales WHERE tenant_id = :t AND sucursal_id IN ({$in})
              ORDER BY sucursal_id, dia_semana, hora_apertura",
            ['t' => $this->tenantId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['sucursal_id']][] = ['dia_semana' => (int) $r['dia_semana'], 'hora_apertura' => $r['hora_apertura'], 'hora_cierre' => $r['hora_cierre']];
        }
        return $out;
    }

    /**
     * Sucursales (no eliminadas) con su horario, en el formato que usan WeeklySchedule y WorkingTime.
     *
     * @return array<int, array{nombre:string, activo:bool, zona_horaria:string, horario:list<array{0:int, 1:string, 2:string}>}>
     */
    public function scheduleMap(bool $onlyActive = false): array
    {
        $rows = $this->fetchAll(
            'SELECT id, nombre, activo, zona_horaria FROM sucursales WHERE tenant_id = :t AND deleted_at IS NULL' . ($onlyActive ? ' AND activo = 1' : ''),
            ['t' => $this->tenantId]
        );
        $hours = $this->hours(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $map = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $map[$id] = [
                'nombre' => (string) $r['nombre'],
                'activo' => (bool) $r['activo'],
                'zona_horaria' => (string) $r['zona_horaria'],
                'horario' => array_map(static fn (array $h): array => [$h['dia_semana'], $h['hora_apertura'], $h['hora_cierre']], $hours[$id] ?? []),
            ];
        }
        return $map;
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        // Incluye sucursales eliminadas: la clave única (tenant, slug) no distingue deleted_at.
        return $this->fetchOne(
            'SELECT 1 FROM sucursales WHERE tenant_id = :t AND slug = :s AND id <> :id LIMIT 1',
            ['t' => $this->tenantId, 's' => $slug, 'id' => $exceptId ?? 0]
        ) !== null;
    }

    public function countActive(): int
    {
        return (int) ($this->fetchOne('SELECT COUNT(*) AS n FROM sucursales WHERE tenant_id = :t AND activo = 1 AND deleted_at IS NULL', ['t' => $this->tenantId])['n'] ?? 0);
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
            'INSERT INTO sucursales (tenant_id, ' . implode(', ', $cols) . ') VALUES (:tenant_id, :' . implode(', :', $cols) . ')',
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
            $this->execute('UPDATE sucursales SET ' . implode(', ', $sets) . ' WHERE tenant_id = :t AND id = :id AND deleted_at IS NULL', $params);
        }
    }

    /** Solo una sucursal principal por barbería. */
    public function setPrincipal(int $id): void
    {
        $this->execute('UPDATE sucursales SET es_principal = IF(id = :id, 1, 0) WHERE tenant_id = :t AND deleted_at IS NULL', ['t' => $this->tenantId, 'id' => $id]);
    }

    public function softDelete(int $id): void
    {
        $this->execute(
            'UPDATE sucursales SET deleted_at = UTC_TIMESTAMP(), activo = 0, es_principal = 0 WHERE tenant_id = :t AND id = :id AND deleted_at IS NULL',
            ['t' => $this->tenantId, 'id' => $id]
        );
    }

    /** @param list<array{dia_semana:int, hora_apertura:string, hora_cierre:string}> $tramos */
    public function replaceHours(int $id, array $tramos): void
    {
        $this->execute('DELETE FROM horarios_sucursales WHERE tenant_id = :t AND sucursal_id = :id', ['t' => $this->tenantId, 'id' => $id]);
        $stmt = $this->db->prepare('INSERT INTO horarios_sucursales (tenant_id, sucursal_id, dia_semana, hora_apertura, hora_cierre) VALUES (?, ?, ?, ?, ?)');
        foreach ($tramos as $t) {
            $stmt->execute([$this->tenantId, $id, $t['dia_semana'], $t['hora_apertura'], $t['hora_cierre']]);
        }
    }

    /** Barberos (activos o no, no eliminados) cuya sucursal principal es esta. */
    public function barbersCount(int $id): int
    {
        return (int) ($this->fetchOne(
            'SELECT COUNT(*) AS n FROM barberos WHERE tenant_id = :t AND sucursal_id = :id AND deleted_at IS NULL AND activo = 1',
            ['t' => $this->tenantId, 'id' => $id]
        )['n'] ?? 0);
    }

    /** Tramos de jornada de barberos en esta sucursal. */
    public function scheduleCount(int $id): int
    {
        return (int) ($this->fetchOne(
            'SELECT COUNT(*) AS n FROM horarios_barberos h JOIN barberos b ON b.tenant_id = h.tenant_id AND b.id = h.barbero_id AND b.deleted_at IS NULL
              WHERE h.tenant_id = :t AND h.sucursal_id = :id AND h.activo = 1',
            ['t' => $this->tenantId, 'id' => $id]
        )['n'] ?? 0);
    }

    public function futureBookingsCount(int $id, string $nowUtc): int
    {
        return (int) ($this->fetchOne(
            'SELECT COUNT(*) AS n FROM reservas WHERE tenant_id = :t AND sucursal_id = :id AND ocupa_agenda = 1 AND fin_utc > :now',
            ['t' => $this->tenantId, 'id' => $id, 'now' => $nowUtc]
        )['n'] ?? 0);
    }
}
