<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Barberos de una barbería: datos, servicios habilitados, jornada semanal y descansos.
 * Siempre filtrado por tenant. La fila del barbero es el candado de su agenda: toda operación
 * que cambie sus intervalos ocupados o disponibles la bloquea con lock() (SELECT … FOR UPDATE).
 */
final class BarberRepository extends TenantScopedRepository
{
    public const CAMPOS = ['usuario_id', 'sucursal_id', 'nombre_publico', 'especialidad', 'biografia', 'activo', 'acepta_reservas_online', 'orden'];

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->fetchAll(
            "SELECT b.id, b.nombre_publico, b.especialidad, b.biografia, b.foto_url, b.activo, b.acepta_reservas_online, b.orden,
                    b.sucursal_id, su.nombre AS sucursal, b.usuario_id, u.email AS usuario_email,
                    (SELECT COUNT(*) FROM barbero_servicios bs JOIN servicios s ON s.tenant_id = bs.tenant_id AND s.id = bs.servicio_id AND s.deleted_at IS NULL AND s.activo = 1
                      WHERE bs.tenant_id = b.tenant_id AND bs.barbero_id = b.id) AS servicios,
                    (SELECT COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(h.hora_fin, h.hora_inicio))) DIV 60, 0) FROM horarios_barberos h
                      WHERE h.tenant_id = b.tenant_id AND h.barbero_id = b.id AND h.activo = 1) AS minutos_semana
               FROM barberos b
               JOIN sucursales su ON su.tenant_id = b.tenant_id AND su.id = b.sucursal_id
               LEFT JOIN usuarios u ON u.id = b.usuario_id
              WHERE b.tenant_id = :t AND b.deleted_at IS NULL
              ORDER BY b.activo DESC, b.orden, b.nombre_publico",
            ['t' => $this->tenantId]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT b.id, b.nombre_publico, b.especialidad, b.biografia, b.foto_url, b.activo, b.acepta_reservas_online, b.orden,
                    b.sucursal_id, su.nombre AS sucursal, b.usuario_id, u.email AS usuario_email
               FROM barberos b
               JOIN sucursales su ON su.tenant_id = b.tenant_id AND su.id = b.sucursal_id
               LEFT JOIN usuarios u ON u.id = b.usuario_id
              WHERE b.tenant_id = :t AND b.id = :id AND b.deleted_at IS NULL',
            ['t' => $this->tenantId, 'id' => $id]
        );
    }

    /** Sucursal principal del barbero (incluye barberos eliminados, para mostrar su historial). */
    public function branchOf(int $id): ?int
    {
        $row = $this->fetchOne('SELECT sucursal_id FROM barberos WHERE tenant_id = :t AND id = :id', ['t' => $this->tenantId, 'id' => $id]);
        return $row === null ? null : (int) $row['sucursal_id'];
    }

    /** Bloquea la fila del barbero hasta el fin de la transacción (candado estable de su agenda). */
    public function lock(int $id): bool
    {
        return $this->fetchOne('SELECT id FROM barberos WHERE tenant_id = :t AND id = :id AND deleted_at IS NULL FOR UPDATE', ['t' => $this->tenantId, 'id' => $id]) !== null;
    }

    /**
     * Bloquea varios barberos en orden de id (orden fijo = sin interbloqueos entre transacciones).
     *
     * @param list<int> $ids
     */
    public function lockMany(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if ($ids !== []) {
            $this->fetchAll('SELECT id FROM barberos WHERE tenant_id = :t AND id IN (' . implode(',', $ids) . ') ORDER BY id FOR UPDATE', ['t' => $this->tenantId]);
        }
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
        return $this->insert('INSERT INTO barberos (tenant_id, ' . implode(', ', $cols) . ') VALUES (:tenant_id, :' . implode(', :', $cols) . ')', $params);
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
            $this->execute('UPDATE barberos SET ' . implode(', ', $sets) . ' WHERE tenant_id = :t AND id = :id AND deleted_at IS NULL', $params);
        }
    }

    /** Eliminación lógica: se conserva para el historial de reservas. Libera la cuenta vinculada. */
    public function softDelete(int $id): void
    {
        $this->execute(
            'UPDATE barberos SET deleted_at = UTC_TIMESTAMP(), activo = 0, usuario_id = NULL, foto_url = NULL WHERE tenant_id = :t AND id = :id AND deleted_at IS NULL',
            ['t' => $this->tenantId, 'id' => $id]
        );
        $this->execute('DELETE FROM barbero_servicios WHERE tenant_id = :t AND barbero_id = :id', ['t' => $this->tenantId, 'id' => $id]);
    }

    public function setPhoto(int $id, ?string $ruta): void
    {
        $this->execute('UPDATE barberos SET foto_url = :r WHERE tenant_id = :t AND id = :id', ['r' => $ruta, 't' => $this->tenantId, 'id' => $id]);
    }

    public function nextOrder(): int
    {
        return (int) ($this->fetchOne('SELECT COALESCE(MAX(orden), 0) + 1 AS o FROM barberos WHERE tenant_id = :t AND deleted_at IS NULL', ['t' => $this->tenantId])['o'] ?? 1);
    }

    public function countActive(): int
    {
        return (int) ($this->fetchOne('SELECT COUNT(*) AS n FROM barberos WHERE tenant_id = :t AND activo = 1 AND deleted_at IS NULL', ['t' => $this->tenantId])['n'] ?? 0);
    }

    /** ¿La cuenta ya está vinculada a otro barbero de esta barbería? */
    public function userLinked(int $usuarioId, ?int $exceptId = null): bool
    {
        return $this->fetchOne(
            'SELECT 1 FROM barberos WHERE tenant_id = :t AND usuario_id = :u AND id <> :id LIMIT 1',
            ['t' => $this->tenantId, 'u' => $usuarioId, 'id' => $exceptId ?? 0]
        ) !== null;
    }

    /** ¿El usuario es integrante (activo o invitado) de la barbería con rol de barbero? */
    public function userIsBarberMember(int $usuarioId): bool
    {
        return $this->fetchOne(
            "SELECT 1 FROM membresias m JOIN roles r ON r.id = m.rol_id
              WHERE m.tenant_id = :t AND m.usuario_id = :u AND r.codigo = 'barbero' AND m.estado IN ('activo','invitado') LIMIT 1",
            ['t' => $this->tenantId, 'u' => $usuarioId]
        ) !== null;
    }

    /** @return list<array<string, mixed>> cuentas con rol barbero aún sin ficha de barbero */
    public function linkableAccounts(): array
    {
        return $this->fetchAll(
            "SELECT DISTINCT u.id AS usuario_id, u.nombres, u.apellidos, u.email, m.estado
               FROM membresias m JOIN roles r ON r.id = m.rol_id AND r.codigo = 'barbero'
               JOIN usuarios u ON u.id = m.usuario_id AND u.deleted_at IS NULL
              WHERE m.tenant_id = :t AND m.estado IN ('activo','invitado')
                AND NOT EXISTS (SELECT 1 FROM barberos b WHERE b.tenant_id = m.tenant_id AND b.usuario_id = u.id)
              ORDER BY u.nombres",
            ['t' => $this->tenantId]
        );
    }

    // ---------------------------------------------------------------- servicios del barbero

    /** @return list<array<string, mixed>> */
    public function services(int $id): array
    {
        return $this->fetchAll(
            'SELECT bs.servicio_id, s.nombre, s.activo, s.duracion_minutos AS duracion_estandar, s.precio AS precio_estandar,
                    bs.duracion_minutos_override, bs.precio_override,
                    COALESCE(bs.duracion_minutos_override, s.duracion_minutos) AS duracion_minutos,
                    COALESCE(bs.precio_override, s.precio) AS precio
               FROM barbero_servicios bs
               JOIN servicios s ON s.tenant_id = bs.tenant_id AND s.id = bs.servicio_id AND s.deleted_at IS NULL
              WHERE bs.tenant_id = :t AND bs.barbero_id = :id
              ORDER BY s.orden, s.nombre',
            ['t' => $this->tenantId, 'id' => $id]
        );
    }

    /** @param list<array{servicio_id:int, duracion_minutos:?int, precio:?string}> $items */
    public function replaceServices(int $id, array $items): void
    {
        $this->execute('DELETE FROM barbero_servicios WHERE tenant_id = :t AND barbero_id = :id', ['t' => $this->tenantId, 'id' => $id]);
        $stmt = $this->db->prepare(
            'INSERT INTO barbero_servicios (tenant_id, barbero_id, servicio_id, duracion_minutos_override, precio_override) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($items as $it) {
            $stmt->execute([$this->tenantId, $id, $it['servicio_id'], $it['duracion_minutos'], $it['precio']]);
        }
    }

    // ---------------------------------------------------------------- jornada y descansos

    /** @return list<array<string, mixed>> */
    public function shifts(int $id): array
    {
        return $this->fetchAll(
            "SELECT h.id, h.dia_semana, TIME_FORMAT(h.hora_inicio, '%H:%i') AS hora_inicio, TIME_FORMAT(h.hora_fin, '%H:%i') AS hora_fin,
                    h.sucursal_id, su.nombre AS sucursal, h.vigente_desde, h.vigente_hasta
               FROM horarios_barberos h JOIN sucursales su ON su.tenant_id = h.tenant_id AND su.id = h.sucursal_id
              WHERE h.tenant_id = :t AND h.barbero_id = :id AND h.activo = 1
              ORDER BY h.dia_semana, h.hora_inicio",
            ['t' => $this->tenantId, 'id' => $id]
        );
    }

    /** @return list<array<string, mixed>> */
    public function breaks(int $id): array
    {
        return $this->fetchAll(
            "SELECT id, dia_semana, TIME_FORMAT(hora_inicio, '%H:%i') AS hora_inicio, TIME_FORMAT(hora_fin, '%H:%i') AS hora_fin, motivo
               FROM descansos_barberos WHERE tenant_id = :t AND barbero_id = :id ORDER BY dia_semana, hora_inicio",
            ['t' => $this->tenantId, 'id' => $id]
        );
    }

    /**
     * @param list<array{dia_semana:int, hora_inicio:string, hora_fin:string, sucursal_id:int, vigente_desde:?string, vigente_hasta:?string}> $tramos
     * @param list<array{dia_semana:int, hora_inicio:string, hora_fin:string, motivo:?string}> $descansos
     */
    public function replaceSchedule(int $id, array $tramos, array $descansos): void
    {
        $t = ['t' => $this->tenantId, 'id' => $id];
        $this->execute('DELETE FROM horarios_barberos WHERE tenant_id = :t AND barbero_id = :id', $t);
        $this->execute('DELETE FROM descansos_barberos WHERE tenant_id = :t AND barbero_id = :id', $t);
        $ins = $this->db->prepare(
            'INSERT INTO horarios_barberos (tenant_id, barbero_id, sucursal_id, dia_semana, hora_inicio, hora_fin, vigente_desde, vigente_hasta) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($tramos as $s) {
            $ins->execute([$this->tenantId, $id, $s['sucursal_id'], $s['dia_semana'], $s['hora_inicio'], $s['hora_fin'], $s['vigente_desde'], $s['vigente_hasta']]);
        }
        $ins = $this->db->prepare('INSERT INTO descansos_barberos (tenant_id, barbero_id, dia_semana, hora_inicio, hora_fin, motivo) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($descansos as $d) {
            $ins->execute([$this->tenantId, $id, $d['dia_semana'], $d['hora_inicio'], $d['hora_fin'], $d['motivo']]);
        }
    }

    /**
     * Reservas activas (pendiente/confirmada) del barbero que terminan después de $fromUtc.
     *
     * @return list<array<string, mixed>>
     */
    public function activeBookings(int $id, string $fromUtc, ?string $toUtc = null, ?int $sucursalId = null): array
    {
        $sql = 'SELECT id, codigo, sucursal_id, inicio_utc, fin_utc, estado FROM reservas
                 WHERE tenant_id = :t AND barbero_id = :id AND ocupa_agenda = 1 AND fin_utc > :desde';
        $params = ['t' => $this->tenantId, 'id' => $id, 'desde' => $fromUtc];
        if ($toUtc !== null) {
            $sql .= ' AND inicio_utc < :hasta';
            $params['hasta'] = $toUtc;
        }
        if ($sucursalId !== null) {
            $sql .= ' AND sucursal_id = :s';
            $params['s'] = $sucursalId;
        }
        return $this->fetchAll($sql . ' ORDER BY inicio_utc LIMIT 500', $params);
    }
}
