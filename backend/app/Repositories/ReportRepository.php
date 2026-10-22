<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Consultas de indicadores del panel (Fase 6). Devuelven filas en UTC; la agrupación por día y
 * mes se hace en PHP con la zona horaria de la sucursal (no depende de las tablas de zonas de MySQL).
 */
final class ReportRepository extends TenantScopedRepository
{
    /**
     * Reservas que comienzan en [desde, hasta).
     *
     * @return list<array<string, mixed>>
     */
    public function bookings(string $desdeUtc, string $hastaUtc, ?int $sucursalId, ?int $barberoId): array
    {
        [$where, $params] = $this->filters('r', $desdeUtc, $hastaUtc, $sucursalId, $barberoId);
        return $this->fetchAll(
            "SELECT r.id, r.estado, r.inicio_utc, r.fin_utc, r.barbero_id, r.sucursal_id, r.cliente_id, r.duracion_total_minutos,
                    r.precio_total, r.cancelada_por_tipo, r.canal, r.atencion_iniciada_en, r.atencion_finalizada_en,
                    r.confirmada_en, r.asistencia_confirmada_en
               FROM reservas r WHERE {$where} ORDER BY r.inicio_utc",
            $params
        );
    }

    /**
     * Servicios más solicitados (sin contar citas canceladas).
     *
     * @return list<array<string, mixed>>
     */
    public function topServices(string $desdeUtc, string $hastaUtc, ?int $sucursalId, ?int $barberoId, int $limit = 10): array
    {
        [$where, $params] = $this->filters('r', $desdeUtc, $hastaUtc, $sucursalId, $barberoId);
        return $this->fetchAll(
            "SELECT rs.servicio_id, MAX(rs.nombre_servicio) AS nombre, COUNT(*) AS cantidad,
                    SUM(r.estado = 'completada') AS atendidos, SUM(rs.precio) AS importe_previsto
               FROM reserva_servicios rs JOIN reservas r ON r.tenant_id = rs.tenant_id AND r.id = rs.reserva_id
              WHERE {$where} AND r.estado <> 'cancelada'
              GROUP BY rs.servicio_id ORDER BY cantidad DESC, nombre LIMIT " . max(1, $limit),
            $params
        );
    }

    /**
     * Cobros no anulados registrados en [desde, hasta) (por fecha de cobro).
     *
     * @return list<array<string, mixed>>
     */
    public function payments(string $desdeUtc, string $hastaUtc, ?int $sucursalId, ?int $barberoId): array
    {
        $sql = 'SELECT p.reserva_id, p.monto, p.metodo, p.pagado_en, r.barbero_id FROM reserva_pagos p
                  JOIN reservas r ON r.tenant_id = p.tenant_id AND r.id = p.reserva_id
                 WHERE p.tenant_id = :t AND p.anulado_en IS NULL AND p.pagado_en >= :desde AND p.pagado_en < :hasta';
        $params = ['t' => $this->tenantId, 'desde' => $desdeUtc, 'hasta' => $hastaUtc];
        if ($sucursalId !== null) {
            $sql .= ' AND r.sucursal_id = :s';
            $params['s'] = $sucursalId;
        }
        if ($barberoId !== null) {
            $sql .= ' AND r.barbero_id = :b';
            $params['b'] = $barberoId;
        }
        return $this->fetchAll($sql . ' ORDER BY p.pagado_en', $params);
    }

    /** Citas pendientes de confirmar desde ahora. */
    public function pendingToConfirm(string $nowUtc, ?int $sucursalId): int
    {
        $sql = "SELECT COUNT(*) AS n FROM reservas WHERE tenant_id = :t AND estado = 'pendiente' AND fin_utc > :now";
        $params = ['t' => $this->tenantId, 'now' => $nowUtc];
        if ($sucursalId !== null) {
            $sql .= ' AND sucursal_id = :s';
            $params['s'] = $sucursalId;
        }
        return (int) ($this->fetchOne($sql, $params)['n'] ?? 0);
    }

    /** Solicitudes de bloqueo de barberos esperando aprobación. */
    public function pendingBlockRequests(string $nowUtc): int
    {
        return (int) ($this->fetchOne(
            "SELECT COUNT(*) AS n FROM bloqueos_agenda WHERE tenant_id = :t AND estado = 'solicitado' AND fin_utc > :now",
            ['t' => $this->tenantId, 'now' => $nowUtc]
        )['n'] ?? 0);
    }

    /** Clientes cuya primera visita atendida cae en el rango (clientes nuevos). */
    public function newClients(string $desdeUtc, string $hastaUtc): int
    {
        return (int) ($this->fetchOne(
            'SELECT COUNT(*) AS n FROM cliente_tenants WHERE tenant_id = :t AND primera_visita_en >= :desde AND primera_visita_en < :hasta',
            ['t' => $this->tenantId, 'desde' => $desdeUtc, 'hasta' => $hastaUtc]
        )['n'] ?? 0);
    }

    /**
     * Cobrado (no anulado) por reserva, sin importar la fecha del cobro: sirve para comparar el
     * importe previsto de las citas atendidas con lo que realmente se cobró por ellas.
     *
     * @param list<int> $reservaIds
     * @return array<int, float>
     */
    public function paidByBooking(array $reservaIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($reservaIds)), 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            foreach ($this->fetchAll(
                "SELECT reserva_id, SUM(monto) AS cobrado FROM reserva_pagos
                  WHERE tenant_id = :t AND anulado_en IS NULL AND reserva_id IN ({$in}) GROUP BY reserva_id",
                ['t' => $this->tenantId]
            ) as $r) {
                $out[(int) $r['reserva_id']] = (float) $r['cobrado'];
            }
        }
        return $out;
    }

    /**
     * Clientes (de la lista) con alguna visita atendida ANTES de $antesUtc, con los mismos
     * filtros de sucursal y barbero del reporte.
     *
     * @param list<int> $clienteIds
     * @return array<int, true>
     */
    public function clientsWithPriorVisit(array $clienteIds, string $antesUtc, ?int $sucursalId, ?int $barberoId): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($clienteIds)), 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $sql = "SELECT DISTINCT cliente_id FROM reservas
                     WHERE tenant_id = :t AND estado = 'completada' AND inicio_utc < :antes AND cliente_id IN ({$in})";
            $params = ['t' => $this->tenantId, 'antes' => $antesUtc];
            if ($sucursalId !== null) {
                $sql .= ' AND sucursal_id = :s';
                $params['s'] = $sucursalId;
            }
            if ($barberoId !== null) {
                $sql .= ' AND barbero_id = :b';
                $params['b'] = $barberoId;
            }
            foreach ($this->fetchAll($sql, $params) as $r) {
                $out[(int) $r['cliente_id']] = true;
            }
        }
        return $out;
    }

    /**
     * Nombres de clientes (para el ranking de recurrentes, solo con clientes.ver).
     *
     * @param list<int> $clienteIds
     * @return array<int, string>
     */
    public function clientNames(array $clienteIds): array
    {
        if ($clienteIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', array_values(array_unique($clienteIds))));
        $out = [];
        foreach ($this->fetchAll(
            "SELECT c.id, TRIM(CONCAT(c.nombres, ' ', COALESCE(c.apellidos, ''))) AS nombre
               FROM cliente_tenants ct JOIN clientes c ON c.id = ct.cliente_id
              WHERE ct.tenant_id = :t AND ct.cliente_id IN ({$in})",
            ['t' => $this->tenantId]
        ) as $r) {
            $out[(int) $r['id']] = (string) $r['nombre'];
        }
        return $out;
    }

    /**
     * Detalle de reservas para exportar (una fila por cita), con sucursal, barbero, cliente,
     * servicios y lo cobrado. Limitado a $limit filas (se pide una más para detectar el exceso).
     *
     * @return list<array<string, mixed>>
     */
    public function detail(string $desdeUtc, string $hastaUtc, ?int $sucursalId, ?int $barberoId, int $limit): array
    {
        [$where, $params] = $this->filters('r', $desdeUtc, $hastaUtc, $sucursalId, $barberoId);
        return $this->fetchAll(
            "SELECT r.id, r.codigo, r.estado, r.inicio_utc, r.fin_utc, r.duracion_total_minutos, r.precio_total, r.canal,
                    r.cancelada_por_tipo, r.confirmada_en, r.asistencia_confirmada_en, r.reprogramaciones,
                    s.nombre AS sucursal, b.nombre_publico AS barbero,
                    TRIM(CONCAT(c.nombres, ' ', COALESCE(c.apellidos, ''))) AS cliente,
                    (SELECT GROUP_CONCAT(rs.nombre_servicio ORDER BY rs.orden SEPARATOR ' + ')
                       FROM reserva_servicios rs WHERE rs.tenant_id = r.tenant_id AND rs.reserva_id = r.id) AS servicios,
                    (SELECT COALESCE(SUM(p.monto), 0) FROM reserva_pagos p
                      WHERE p.tenant_id = r.tenant_id AND p.reserva_id = r.id AND p.anulado_en IS NULL) AS cobrado
               FROM reservas r
               JOIN sucursales s ON s.tenant_id = r.tenant_id AND s.id = r.sucursal_id
               JOIN barberos b ON b.tenant_id = r.tenant_id AND b.id = r.barbero_id
               JOIN clientes c ON c.id = r.cliente_id
              WHERE {$where} ORDER BY r.inicio_utc, r.id LIMIT " . max(1, $limit + 1),
            $params
        );
    }

    /**
     * Barberos de la barbería, incluidos los dados de baja (pueden tener historial en el rango).
     *
     * @return list<array<string, mixed>>
     */
    public function barbers(): array
    {
        return $this->fetchAll(
            'SELECT id, nombre_publico, activo, deleted_at FROM barberos WHERE tenant_id = :t ORDER BY deleted_at IS NOT NULL, orden, nombre_publico',
            ['t' => $this->tenantId]
        );
    }

    /** @return array{0:string, 1:array<string, mixed>} */
    private function filters(string $a, string $desdeUtc, string $hastaUtc, ?int $sucursalId, ?int $barberoId): array
    {
        $where = "{$a}.tenant_id = :t AND {$a}.inicio_utc >= :desde AND {$a}.inicio_utc < :hasta";
        $params = ['t' => $this->tenantId, 'desde' => $desdeUtc, 'hasta' => $hastaUtc];
        if ($sucursalId !== null) {
            $where .= " AND {$a}.sucursal_id = :s";
            $params['s'] = $sucursalId;
        }
        if ($barberoId !== null) {
            $where .= " AND {$a}.barbero_id = :b";
            $params['b'] = $barberoId;
        }
        return [$where, $params];
    }
}
