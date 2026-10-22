<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Agenda del panel de UNA barbería (Fase 6): citas con los datos que necesita el personal
 * (contacto del cliente, notas internas, atención y cobros). Siempre filtrado por tenant.
 */
final class AgendaRepository extends TenantScopedRepository
{
    private const SELECT = "SELECT r.id, r.codigo, r.sucursal_id, r.barbero_id, r.cliente_id, r.estado, r.inicio_utc, r.fin_utc,
                r.zona_horaria, r.duracion_total_minutos, r.precio_total, r.moneda, r.canal, r.barbero_asignado_auto,
                r.notas_cliente, r.notas_internas, r.confirmada_en, r.asistencia_confirmada_en, r.atencion_iniciada_en,
                r.atencion_finalizada_en, r.cancelada_en, r.cancelada_por_tipo, r.motivo_cancelacion, r.reprogramaciones,
                r.version, r.created_at,
                su.nombre AS sucursal_nombre,
                b.nombre_publico AS barbero_nombre, b.foto_url AS barbero_foto, b.usuario_id AS barbero_usuario_id,
                c.nombres AS cliente_nombres, c.apellidos AS cliente_apellidos, c.telefono AS cliente_telefono,
                c.email AS cliente_email, c.usuario_id AS cliente_usuario_id,
                COALESCE((SELECT SUM(p.monto) FROM reserva_pagos p
                           WHERE p.tenant_id = r.tenant_id AND p.reserva_id = r.id AND p.anulado_en IS NULL), 0) AS pagado
           FROM reservas r
           JOIN sucursales su ON su.tenant_id = r.tenant_id AND su.id = r.sucursal_id
           JOIN barberos b ON b.tenant_id = r.tenant_id AND b.id = r.barbero_id
           JOIN clientes c ON c.id = r.cliente_id";

    /**
     * Citas que tocan el rango UTC [desde, hasta).
     *
     * @param list<string>|null $estados
     * @return list<array<string, mixed>>
     */
    public function range(?int $sucursalId, ?int $barberoId, string $desdeUtc, string $hastaUtc, ?array $estados = null, int $limit = 2000): array
    {
        $sql = self::SELECT . ' WHERE r.tenant_id = :t AND r.inicio_utc < :hasta AND r.fin_utc > :desde';
        $params = ['t' => $this->tenantId, 'desde' => $desdeUtc, 'hasta' => $hastaUtc];
        if ($sucursalId !== null) {
            $sql .= ' AND r.sucursal_id = :s';
            $params['s'] = $sucursalId;
        }
        if ($barberoId !== null) {
            $sql .= ' AND r.barbero_id = :b';
            $params['b'] = $barberoId;
        }
        if ($estados !== null && $estados !== []) {
            $in = [];
            foreach (array_values($estados) as $i => $e) {
                $in[] = ":e{$i}";
                $params["e{$i}"] = $e;
            }
            $sql .= ' AND r.estado IN (' . implode(', ', $in) . ')';
        }
        return $this->fetchAll($sql . ' ORDER BY r.inicio_utc, b.orden, r.id LIMIT ' . max(1, $limit), $params);
    }

    /** Próximas citas activas desde ahora (para el dashboard). @return list<array<string, mixed>> */
    public function upcoming(?int $sucursalId, ?int $barberoId, string $nowUtc, string $hastaUtc, int $limit = 8): array
    {
        $sql = self::SELECT . ' WHERE r.tenant_id = :t AND r.ocupa_agenda = 1 AND r.fin_utc > :now AND r.inicio_utc < :hasta';
        $params = ['t' => $this->tenantId, 'now' => $nowUtc, 'hasta' => $hastaUtc];
        if ($sucursalId !== null) {
            $sql .= ' AND r.sucursal_id = :s';
            $params['s'] = $sucursalId;
        }
        if ($barberoId !== null) {
            $sql .= ' AND r.barbero_id = :b';
            $params['b'] = $barberoId;
        }
        return $this->fetchAll($sql . ' ORDER BY r.inicio_utc, r.id LIMIT ' . max(1, $limit), $params);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE r.tenant_id = :t AND r.id = :id', ['t' => $this->tenantId, 'id' => $id]);
    }

    /**
     * Citas de un cliente en esta barbería.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function forClient(int $clienteId, string $tipo, string $nowUtc, int $limit, int $offset): array
    {
        $cond = $tipo === 'proximas' ? 'r.ocupa_agenda = 1 AND r.fin_utc > :now' : '(r.ocupa_agenda = 0 OR r.fin_utc <= :now)';
        $order = $tipo === 'proximas' ? 'r.inicio_utc ASC' : 'r.inicio_utc DESC';
        $params = ['t' => $this->tenantId, 'c' => $clienteId, 'now' => $nowUtc];
        $total = (int) ($this->fetchOne("SELECT COUNT(*) AS n FROM reservas r WHERE r.tenant_id = :t AND r.cliente_id = :c AND {$cond}", $params)['n'] ?? 0);
        $items = $this->fetchAll(self::SELECT . " WHERE r.tenant_id = :t AND r.cliente_id = :c AND {$cond} ORDER BY {$order}, r.id LIMIT "
            . max(1, $limit) . ' OFFSET ' . max(0, $offset), $params);
        return ['items' => $items, 'total' => $total];
    }

    /**
     * Historial de un barbero (Fase 7): citas que ya empezaron dentro del rango UTC, de la más
     * reciente a la más antigua, con totales por estado del mismo rango.
     *
     * @return array{items: list<array<string, mixed>>, total: int, por_estado: array<string, int>}
     */
    public function barberHistory(int $barberoId, string $desdeUtc, string $hastaUtc, ?string $estado, int $limit, int $offset): array
    {
        $cond = 'r.tenant_id = :t AND r.barbero_id = :b AND r.inicio_utc >= :desde AND r.inicio_utc < :hasta';
        $params = ['t' => $this->tenantId, 'b' => $barberoId, 'desde' => $desdeUtc, 'hasta' => $hastaUtc];
        $porEstado = ['pendiente' => 0, 'confirmada' => 0, 'completada' => 0, 'cancelada' => 0, 'no_asistio' => 0];
        foreach ($this->fetchAll("SELECT r.estado, COUNT(*) AS n FROM reservas r WHERE {$cond} GROUP BY r.estado", $params) as $row) {
            $porEstado[(string) $row['estado']] = (int) $row['n'];
        }
        if ($estado !== null) {
            $cond .= ' AND r.estado = :e';
            $params['e'] = $estado;
        }
        $total = $estado !== null ? ($porEstado[$estado] ?? 0) : array_sum($porEstado);
        $items = $this->fetchAll(self::SELECT . " WHERE {$cond} ORDER BY r.inicio_utc DESC, r.id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset), $params);
        return ['items' => $items, 'total' => $total, 'por_estado' => $porEstado];
    }

    /**
     * Visitas anteriores de un cliente CON ESTE barbero (lo que el barbero está autorizado a ver
     * del historial del cliente): citas que empezaron antes de $antesUtc, más recientes primero.
     *
     * @return array{items: list<array<string, mixed>>, atendidas: int}
     */
    public function clientVisitsWithBarber(int $clienteId, int $barberoId, string $antesUtc, int $limit = 5): array
    {
        $params = ['t' => $this->tenantId, 'c' => $clienteId, 'b' => $barberoId, 'antes' => $antesUtc];
        $where = 'r.tenant_id = :t AND r.cliente_id = :c AND r.barbero_id = :b AND r.inicio_utc < :antes';
        $atendidas = (int) ($this->fetchOne("SELECT COUNT(*) AS n FROM reservas r WHERE {$where} AND r.estado = 'completada'", $params)['n'] ?? 0);
        $items = $this->fetchAll(self::SELECT . " WHERE {$where} AND r.estado IN ('completada', 'no_asistio')
            ORDER BY r.inicio_utc DESC, r.id DESC LIMIT " . max(1, $limit), $params);
        return ['items' => $items, 'atendidas' => $atendidas];
    }

    /**
     * Servicios de varias reservas, agrupados por reserva.
     *
     * @param list<int> $ids
     * @return array<int, list<array<string, mixed>>>
     */
    public function servicesFor(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = $this->fetchAll(
                'SELECT reserva_id, servicio_id, orden, nombre_servicio, duracion_minutos, precio, inicio_utc, fin_utc
                   FROM reserva_servicios WHERE tenant_id = :t AND reserva_id IN (' . implode(',', $chunk) . ') ORDER BY reserva_id, orden',
                ['t' => $this->tenantId]
            );
            foreach ($rows as $r) {
                $out[(int) $r['reserva_id']][] = $r;
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function payments(int $reservaId): array
    {
        return $this->fetchAll(
            "SELECT p.id, p.monto, p.moneda, p.metodo, p.referencia, p.pagado_en, p.anulado_en, p.motivo_anulacion,
                    TRIM(CONCAT(u.nombres, ' ', COALESCE(u.apellidos, ''))) AS registrado_por
               FROM reserva_pagos p LEFT JOIN usuarios u ON u.id = p.registrado_por
              WHERE p.tenant_id = :t AND p.reserva_id = :r ORDER BY p.id",
            ['t' => $this->tenantId, 'r' => $reservaId]
        );
    }

    /** @return array<string, mixed>|null */
    public function payment(int $reservaId, int $pagoId): ?array
    {
        return $this->fetchOne(
            'SELECT id, monto, anulado_en FROM reserva_pagos WHERE tenant_id = :t AND reserva_id = :r AND id = :id FOR UPDATE',
            ['t' => $this->tenantId, 'r' => $reservaId, 'id' => $pagoId]
        );
    }

    public function paidTotal(int $reservaId): string
    {
        return (string) ($this->fetchOne(
            'SELECT COALESCE(SUM(monto), 0) AS s FROM reserva_pagos WHERE tenant_id = :t AND reserva_id = :r AND anulado_en IS NULL',
            ['t' => $this->tenantId, 'r' => $reservaId]
        )['s'] ?? '0');
    }

    /** @param array<string, mixed> $d */
    public function addPayment(int $reservaId, array $d): int
    {
        return $this->insert(
            'INSERT INTO reserva_pagos (tenant_id, reserva_id, monto, moneda, metodo, referencia, pagado_en, registrado_por)
             VALUES (:t, :r, :monto, :moneda, :metodo, :ref, :pagado, :por)',
            ['t' => $this->tenantId, 'r' => $reservaId, 'monto' => $d['monto'], 'moneda' => $d['moneda'], 'metodo' => $d['metodo'],
                'ref' => $d['referencia'], 'pagado' => $d['pagado_en'], 'por' => $d['registrado_por']]
        );
    }

    public function voidPayment(int $pagoId, int $userId, string $motivo, string $nowUtc): void
    {
        $this->execute(
            'UPDATE reserva_pagos SET anulado_en = :now, anulado_por = :u, motivo_anulacion = :m WHERE tenant_id = :t AND id = :id AND anulado_en IS NULL',
            ['now' => $nowUtc, 'u' => $userId, 'm' => $motivo, 't' => $this->tenantId, 'id' => $pagoId]
        );
    }

    /**
     * Historial interno de la cita: eventos con el nombre de quien actuó.
     *
     * @return list<array<string, mixed>>
     */
    public function events(int $reservaId): array
    {
        return $this->fetchAll(
            "SELECT e.id, e.tipo, e.estado_anterior, e.estado_nuevo, e.inicio_anterior_utc, e.inicio_nuevo_utc,
                    e.barbero_anterior_id, e.barbero_nuevo_id, e.actor_tipo, e.datos, e.created_at,
                    TRIM(CONCAT(u.nombres, ' ', COALESCE(u.apellidos, ''))) AS actor_nombre
               FROM reserva_eventos e LEFT JOIN usuarios u ON u.id = e.actor_usuario_id
              WHERE e.tenant_id = :t AND e.reserva_id = :r ORDER BY e.id",
            ['t' => $this->tenantId, 'r' => $reservaId]
        );
    }

    public function updateNotes(int $id, ?string $notas): void
    {
        $this->execute('UPDATE reservas SET notas_internas = :n WHERE tenant_id = :t AND id = :id', ['n' => $notas, 't' => $this->tenantId, 'id' => $id]);
    }

    /**
     * Recalcula primera/última visita e inasistencias del cliente en esta barbería a partir de
     * sus reservas (así una corrección de asistencia nunca deja contadores desfasados).
     */
    public function recomputeClientStats(int $clienteId): void
    {
        $this->execute(
            "UPDATE cliente_tenants ct SET
                    primera_visita_en = (SELECT MIN(r.inicio_utc) FROM reservas r WHERE r.tenant_id = ct.tenant_id AND r.cliente_id = ct.cliente_id AND r.estado = 'completada'),
                    ultima_visita_en = (SELECT MAX(r.inicio_utc) FROM reservas r WHERE r.tenant_id = ct.tenant_id AND r.cliente_id = ct.cliente_id AND r.estado = 'completada'),
                    total_inasistencias = (SELECT COUNT(*) FROM reservas r WHERE r.tenant_id = ct.tenant_id AND r.cliente_id = ct.cliente_id AND r.estado = 'no_asistio')
              WHERE ct.tenant_id = :t AND ct.cliente_id = :c",
            ['t' => $this->tenantId, 'c' => $clienteId]
        );
    }

    /** Id del barbero vinculado a la cuenta (null si la cuenta no es barbero aquí). */
    public function barberIdForUser(int $userId): ?int
    {
        $row = $this->fetchOne('SELECT id FROM barberos WHERE tenant_id = :t AND usuario_id = :u AND deleted_at IS NULL', ['t' => $this->tenantId, 'u' => $userId]);
        return $row === null ? null : (int) $row['id'];
    }

    /**
     * Barberos que se muestran como columnas de la agenda de una sucursal: activos con sede
     * principal o jornada allí, más los que tengan citas en el rango (aunque ya no estén).
     *
     * @param list<int> $conCitas
     * @return list<array<string, mixed>>
     */
    public function agendaBarbers(?int $sucursalId, array $conCitas): array
    {
        $extra = array_values(array_unique(array_map('intval', $conCitas)));
        $sql = 'SELECT b.id, b.nombre_publico, b.foto_url, b.orden, b.activo, b.sucursal_id, b.usuario_id FROM barberos b
                 WHERE b.tenant_id = :t AND ((b.deleted_at IS NULL AND b.activo = 1';
        $params = ['t' => $this->tenantId];
        if ($sucursalId !== null) {
            $sql .= ' AND (b.sucursal_id = :s OR EXISTS (SELECT 1 FROM horarios_barberos h WHERE h.tenant_id = b.tenant_id AND h.barbero_id = b.id AND h.sucursal_id = :s2 AND h.activo = 1))';
            $params['s'] = $sucursalId;
            $params['s2'] = $sucursalId;
        }
        $sql .= ')' . ($extra !== [] ? ' OR b.id IN (' . implode(',', $extra) . ')' : '') . ')';
        return $this->fetchAll($sql . ' ORDER BY b.orden, b.nombre_publico, b.id', $params);
    }

    /**
     * Bloqueos (aprobados y solicitados) que tocan el rango, para mostrarlos en la agenda.
     *
     * @return list<array<string, mixed>>
     */
    public function blocks(?int $sucursalId, string $desdeUtc, string $hastaUtc): array
    {
        $sql = "SELECT id, tipo, estado, barbero_id, sucursal_id, inicio_utc, fin_utc, motivo FROM bloqueos_agenda
                 WHERE tenant_id = :t AND estado IN ('aprobado', 'solicitado') AND inicio_utc < :hasta AND fin_utc > :desde";
        $params = ['t' => $this->tenantId, 'desde' => $desdeUtc, 'hasta' => $hastaUtc];
        if ($sucursalId !== null) {
            $sql .= ' AND (sucursal_id IS NULL OR sucursal_id = :s)';
            $params['s'] = $sucursalId;
        }
        return $this->fetchAll($sql . ' ORDER BY inicio_utc', $params);
    }

    /**
     * Catálogo para registrar citas desde el panel: servicios activos y barberos activos con
     * los servicios que realizan y las sucursales donde atienden.
     *
     * @return array{servicios: list<array<string, mixed>>, barberos: list<array<string, mixed>>}
     */
    public function bookingCatalog(): array
    {
        $servicios = $this->fetchAll(
            'SELECT s.id, s.nombre, s.duracion_minutos, s.precio, c.nombre AS categoria FROM servicios s
               LEFT JOIN categorias_servicio c ON c.tenant_id = s.tenant_id AND c.id = s.categoria_id
              WHERE s.tenant_id = :t AND s.activo = 1 AND s.deleted_at IS NULL ORDER BY c.orden IS NULL, c.orden, s.orden, s.nombre',
            ['t' => $this->tenantId]
        );
        $barberos = $this->fetchAll(
            'SELECT b.id, b.nombre_publico, b.sucursal_id,
                    (SELECT GROUP_CONCAT(bs.servicio_id) FROM barbero_servicios bs WHERE bs.tenant_id = b.tenant_id AND bs.barbero_id = b.id) AS servicio_ids,
                    (SELECT GROUP_CONCAT(DISTINCT h.sucursal_id) FROM horarios_barberos h WHERE h.tenant_id = b.tenant_id AND h.barbero_id = b.id AND h.activo = 1) AS sucursal_ids
               FROM barberos b WHERE b.tenant_id = :t AND b.activo = 1 AND b.deleted_at IS NULL ORDER BY b.orden, b.nombre_publico',
            ['t' => $this->tenantId]
        );
        $ids = static fn ($csv): array => $csv === null || $csv === '' ? [] : array_map('intval', explode(',', (string) $csv));
        return [
            'servicios' => array_map(static fn (array $s): array => [
                'id' => (int) $s['id'], 'nombre' => (string) $s['nombre'], 'duracion_minutos' => (int) $s['duracion_minutos'],
                'precio' => (string) $s['precio'], 'categoria' => $s['categoria'],
            ], $servicios),
            'barberos' => array_map(static fn (array $b): array => [
                'id' => (int) $b['id'], 'nombre' => (string) $b['nombre_publico'], 'servicio_ids' => $ids($b['servicio_ids']),
                'sucursal_ids' => array_values(array_filter(array_unique(array_merge([(int) $b['sucursal_id']], $ids($b['sucursal_ids']))), static fn (int $id): bool => $id > 0)),
            ], $barberos),
        ];
    }
}
