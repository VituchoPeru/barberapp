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
                    r.precio_total, r.cancelada_por_tipo, r.canal, r.atencion_iniciada_en
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
        $sql = 'SELECT p.monto, p.metodo, p.pagado_en FROM reserva_pagos p
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
