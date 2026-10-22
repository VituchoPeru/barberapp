<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Datos del motor de reservas de UNA barbería (siempre filtrado por tenant).
 * Las operaciones que cambian intervalos ocupados se hacen dentro de una transacción que ya
 * bloqueó la fila de los barberos afectados (BarberRepository::lock / lockMany).
 */
final class BookingRepository extends TenantScopedRepository
{
    /** Columnas para presentar una reserva (comprobante, detalle, listados). */
    public const SELECT_DETAIL = "SELECT r.id, r.tenant_id, r.codigo, r.sucursal_id, r.barbero_id, r.cliente_id, r.estado,
                r.inicio_utc, r.fin_utc, r.zona_horaria, r.duracion_total_minutos, r.precio_total, r.moneda, r.canal,
                r.barbero_asignado_auto, r.notas_cliente, r.confirmada_en, r.asistencia_confirmada_en, r.cancelada_en,
                r.cancelada_por_tipo, r.motivo_cancelacion, r.reprogramaciones, r.version, r.created_at,
                t.slug AS tenant_slug, t.nombre_comercial AS tenant_nombre, t.logo_url AS tenant_logo, t.telefono AS tenant_telefono,
                t.whatsapp AS tenant_whatsapp, t.estado AS tenant_estado,
                su.nombre AS sucursal_nombre, su.direccion AS sucursal_direccion, su.distrito AS sucursal_distrito,
                su.referencia AS sucursal_referencia, su.telefono AS sucursal_telefono, su.latitud, su.longitud,
                b.nombre_publico AS barbero_nombre, b.foto_url AS barbero_foto, b.usuario_id AS barbero_usuario_id,
                c.usuario_id AS cliente_usuario_id, c.nombres AS cliente_nombres, c.apellidos AS cliente_apellidos
           FROM reservas r
           JOIN tenants t ON t.id = r.tenant_id
           JOIN sucursales su ON su.tenant_id = r.tenant_id AND su.id = r.sucursal_id
           JOIN barberos b ON b.tenant_id = r.tenant_id AND b.id = r.barbero_id
           JOIN clientes c ON c.id = r.cliente_id";

    /**
     * Fila de política: la propia de la sucursal o, si no hay, la general de la barbería.
     *
     * @return array<string, mixed>|null
     */
    public function policyRow(?int $sucursalId): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM politicas_reserva
              WHERE tenant_id = :t AND (sucursal_id IS NULL OR sucursal_id = :s)
              ORDER BY sucursal_id IS NULL LIMIT 1',
            ['t' => $this->tenantId, 's' => $sucursalId ?? 0]
        );
    }

    /** @return list<array<string, mixed>> la general (sucursal_id NULL) primero */
    public function policyRows(): array
    {
        return $this->fetchAll(
            'SELECT p.*, su.nombre AS sucursal FROM politicas_reserva p
               LEFT JOIN sucursales su ON su.tenant_id = p.tenant_id AND su.id = p.sucursal_id
              WHERE p.tenant_id = :t ORDER BY p.sucursal_id IS NOT NULL, su.nombre',
            ['t' => $this->tenantId]
        );
    }

    /** @param array<string, mixed> $data */
    public function savePolicy(?int $sucursalId, array $data): void
    {
        $cols = array_keys($data);
        $params = ['t' => $this->tenantId, 's' => $sucursalId];
        foreach ($data as $k => $v) {
            $params[$k] = is_bool($v) ? (int) $v : $v;
        }
        $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = VALUES({$c})", $cols));
        $this->execute(
            'INSERT INTO politicas_reserva (tenant_id, sucursal_id, ' . implode(', ', $cols) . ') VALUES (:t, :s, :' . implode(', :', $cols) . ')'
            . ($sets !== '' ? ' ON DUPLICATE KEY UPDATE ' . $sets : ''),
            $params
        );
    }

    public function deletePolicy(int $sucursalId): bool
    {
        return $this->execute('DELETE FROM politicas_reserva WHERE tenant_id = :t AND sucursal_id = :s', ['t' => $this->tenantId, 's' => $sucursalId]) > 0;
    }

    /** @return array<string, mixed>|null sucursal activa de la barbería */
    public function branch(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT id, nombre, direccion, distrito, zona_horaria FROM sucursales
              WHERE tenant_id = :t AND id = :id AND activo = 1 AND deleted_at IS NULL',
            ['t' => $this->tenantId, 'id' => $id]
        );
    }

    /**
     * Servicios activos por id (en el orden pedido no se garantiza: ver $ids).
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function activeServices(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $rows = $this->fetchAll(
            "SELECT id, nombre, duracion_minutos, precio, moneda FROM servicios
              WHERE tenant_id = :t AND id IN ({$in}) AND activo = 1 AND deleted_at IS NULL",
            ['t' => $this->tenantId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    /**
     * Barberos que pueden atender TODOS los servicios en la sucursal (sede principal o con jornada allí).
     * Devuelve, por barbero, la duración y el precio efectivos de cada servicio (con sus valores propios).
     *
     * @param list<int> $serviceIds
     * @return array<int, array{id:int, nombre:string, foto_url:?string, orden:int, servicios:array<int, array{duracion:int, precio:string}>}>
     */
    public function candidates(int $sucursalId, array $serviceIds, ?int $barberoId, bool $onlineOnly): array
    {
        if ($serviceIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $serviceIds));
        $sql = "SELECT b.id, b.nombre_publico, b.foto_url, b.orden, bs.servicio_id,
                       COALESCE(bs.duracion_minutos_override, s.duracion_minutos) AS duracion,
                       COALESCE(bs.precio_override, s.precio) AS precio
                  FROM barberos b
                  JOIN barbero_servicios bs ON bs.tenant_id = b.tenant_id AND bs.barbero_id = b.id AND bs.servicio_id IN ({$in})
                  JOIN servicios s ON s.tenant_id = bs.tenant_id AND s.id = bs.servicio_id AND s.activo = 1 AND s.deleted_at IS NULL
                 WHERE b.tenant_id = :t AND b.activo = 1 AND b.deleted_at IS NULL
                   AND (b.sucursal_id = :s OR EXISTS (SELECT 1 FROM horarios_barberos h
                         WHERE h.tenant_id = b.tenant_id AND h.barbero_id = b.id AND h.sucursal_id = :s2 AND h.activo = 1))";
        $params = ['t' => $this->tenantId, 's' => $sucursalId, 's2' => $sucursalId];
        if ($onlineOnly) {
            $sql .= ' AND b.acepta_reservas_online = 1';
        }
        if ($barberoId !== null) {
            $sql .= ' AND b.id = :b';
            $params['b'] = $barberoId;
        }
        $out = [];
        foreach ($this->fetchAll($sql . ' ORDER BY b.orden, b.id', $params) as $r) {
            $id = (int) $r['id'];
            $out[$id] ??= ['id' => $id, 'nombre' => (string) $r['nombre_publico'], 'foto_url' => $r['foto_url'], 'orden' => (int) $r['orden'], 'servicios' => []];
            $out[$id]['servicios'][(int) $r['servicio_id']] = ['duracion' => (int) $r['duracion'], 'precio' => (string) $r['precio']];
        }
        $need = count(array_unique($serviceIds));
        return array_filter($out, static fn (array $b): bool => count($b['servicios']) === $need);
    }

    /**
     * Reservas activas (pendiente/confirmada) de varios barberos que tocan el rango UTC.
     *
     * @param list<int> $barberIds
     * @return list<array<string, mixed>>
     */
    public function activeBookings(array $barberIds, string $desdeUtc, string $hastaUtc): array
    {
        if ($barberIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $barberIds));
        return $this->fetchAll(
            "SELECT id, barbero_id, sucursal_id, inicio_utc, fin_utc FROM reservas
              WHERE tenant_id = :t AND barbero_id IN ({$in}) AND ocupa_agenda = 1
                AND inicio_utc < :hasta AND fin_utc > :desde
              ORDER BY inicio_utc",
            ['t' => $this->tenantId, 'desde' => $desdeUtc, 'hasta' => $hastaUtc]
        );
    }

    public function countActiveForClient(int $clienteId, string $nowUtc, ?int $excludeId = null): int
    {
        return (int) ($this->fetchOne(
            'SELECT COUNT(*) AS n FROM reservas WHERE tenant_id = :t AND cliente_id = :c AND ocupa_agenda = 1 AND fin_utc > :now AND id <> :ex',
            ['t' => $this->tenantId, 'c' => $clienteId, 'now' => $nowUtc, 'ex' => $excludeId ?? 0]
        )['n'] ?? 0);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(self::SELECT_DETAIL . ' WHERE r.tenant_id = :t AND r.id = :id', ['t' => $this->tenantId, 'id' => $id]);
    }

    /** Bloquea la fila de la reserva (después de bloquear al barbero). @return array<string, mixed>|null */
    public function lock(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT id, barbero_id, sucursal_id, cliente_id, estado, inicio_utc, fin_utc, version, reprogramaciones, barbero_asignado_auto
               FROM reservas WHERE tenant_id = :t AND id = :id FOR UPDATE',
            ['t' => $this->tenantId, 'id' => $id]
        );
    }

    /** @param array<string, mixed> $d */
    public function create(array $d): int
    {
        $cols = array_keys($d);
        $params = ['tenant_id' => $this->tenantId];
        foreach ($d as $k => $v) {
            $params[$k] = is_bool($v) ? (int) $v : $v;
        }
        return $this->insert('INSERT INTO reservas (tenant_id, ' . implode(', ', $cols) . ') VALUES (:tenant_id, :' . implode(', :', $cols) . ')', $params);
    }

    /**
     * @param list<array{servicio_id:int, nombre:string, duracion:int, precio:string, inicio_utc:string, fin_utc:string}> $items
     */
    public function replaceServices(int $reservaId, array $items): void
    {
        $this->execute('DELETE FROM reserva_servicios WHERE tenant_id = :t AND reserva_id = :r', ['t' => $this->tenantId, 'r' => $reservaId]);
        $stmt = $this->db->prepare(
            'INSERT INTO reserva_servicios (tenant_id, reserva_id, servicio_id, orden, nombre_servicio, duracion_minutos, precio, inicio_utc, fin_utc)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (array_values($items) as $i => $it) {
            $stmt->execute([$this->tenantId, $reservaId, $it['servicio_id'], $i + 1, $it['nombre'], $it['duracion'], $it['precio'], $it['inicio_utc'], $it['fin_utc']]);
        }
    }

    /** @return list<array<string, mixed>> */
    public function services(int $reservaId): array
    {
        return $this->fetchAll(
            'SELECT servicio_id, orden, nombre_servicio, duracion_minutos, precio, inicio_utc, fin_utc
               FROM reserva_servicios WHERE tenant_id = :t AND reserva_id = :r ORDER BY orden',
            ['t' => $this->tenantId, 'r' => $reservaId]
        );
    }

    /**
     * Actualiza la reserva solo si la versión no cambió (control optimista además del candado).
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, int $version, array $data): bool
    {
        $sets = ['version = version + 1'];
        $params = ['t' => $this->tenantId, 'id' => $id, 'v' => $version];
        foreach ($data as $k => $v) {
            $sets[] = "{$k} = :{$k}";
            $params[$k] = is_bool($v) ? (int) $v : $v;
        }
        return $this->execute('UPDATE reservas SET ' . implode(', ', $sets) . ' WHERE tenant_id = :t AND id = :id AND version = :v', $params) === 1;
    }

    /** @param array<string, mixed> $e */
    public function addEvent(int $reservaId, array $e): int
    {
        $e += [
            'estado_anterior' => null, 'estado_nuevo' => null, 'inicio_anterior_utc' => null, 'fin_anterior_utc' => null,
            'inicio_nuevo_utc' => null, 'fin_nuevo_utc' => null, 'barbero_anterior_id' => null, 'barbero_nuevo_id' => null,
            'actor_usuario_id' => null, 'datos' => null, 'ip' => null, 'created_at' => null,
        ];
        return $this->insert(
            'INSERT INTO reserva_eventos (tenant_id, reserva_id, tipo, estado_anterior, estado_nuevo, inicio_anterior_utc, fin_anterior_utc,
                                          inicio_nuevo_utc, fin_nuevo_utc, barbero_anterior_id, barbero_nuevo_id, actor_tipo, actor_usuario_id, datos, ip, created_at)
             VALUES (:t, :r, :tipo, :ea, :en, :ia, :fa, :inn, :fn, :ba, :bn, :at, :au, :datos, :ip, COALESCE(:creado, UTC_TIMESTAMP()))',
            [
                't' => $this->tenantId, 'r' => $reservaId, 'tipo' => $e['tipo'], 'ea' => $e['estado_anterior'], 'en' => $e['estado_nuevo'],
                'ia' => $e['inicio_anterior_utc'], 'fa' => $e['fin_anterior_utc'], 'inn' => $e['inicio_nuevo_utc'], 'fn' => $e['fin_nuevo_utc'],
                'ba' => $e['barbero_anterior_id'], 'bn' => $e['barbero_nuevo_id'], 'at' => $e['actor_tipo'], 'au' => $e['actor_usuario_id'],
                'datos' => $e['datos'] === null ? null : json_encode($e['datos'], JSON_UNESCAPED_UNICODE), 'ip' => $e['ip'], 'creado' => $e['created_at'],
            ]
        );
    }

    /** @return list<array<string, mixed>> */
    public function events(int $reservaId): array
    {
        return $this->fetchAll(
            'SELECT id, tipo, estado_anterior, estado_nuevo, inicio_anterior_utc, fin_anterior_utc, inicio_nuevo_utc, fin_nuevo_utc,
                    barbero_anterior_id, barbero_nuevo_id, actor_tipo, created_at
               FROM reserva_eventos WHERE tenant_id = :t AND reserva_id = :r ORDER BY id',
            ['t' => $this->tenantId, 'r' => $reservaId]
        );
    }

    public function codeExists(string $codigo): bool
    {
        return $this->fetchOne('SELECT 1 FROM reservas WHERE codigo = :c', ['c' => $codigo]) !== null;
    }

    public function incrementClientBookings(int $clienteId): void
    {
        $this->execute(
            'UPDATE cliente_tenants SET total_reservas = total_reservas + 1 WHERE tenant_id = :t AND cliente_id = :c',
            ['t' => $this->tenantId, 'c' => $clienteId]
        );
    }

    /** Nombres de barberos por id (para el historial). @param list<int> $ids @return array<int, string> */
    public function barberNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $rows = $this->fetchAll('SELECT id, nombre_publico FROM barberos WHERE tenant_id = :t AND id IN (' . implode(',', $ids) . ')', ['t' => $this->tenantId]);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (string) $r['nombre_publico'];
        }
        return $out;
    }
}
