<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Bloqueos de agenda (vacaciones, ausencias, feriados, descansos puntuales y bloqueos).
 * Alcance:  barbero_id + sucursal_id NULL → todo el barbero;  barbero_id NULL + sucursal_id → toda la sucursal;
 *           ambos NULL → toda la barbería (p. ej. feriado);  ambos → el barbero solo en esa sucursal.
 */
final class AgendaBlockRepository extends TenantScopedRepository
{
    public const TIPOS = ['descanso', 'vacaciones', 'ausencia', 'feriado', 'bloqueo'];
    public const ESTADOS = ['solicitado', 'aprobado', 'rechazado'];

    /**
     * @param array{id?:?int, desde?:?string, hasta?:?string, barbero_id?:?int, sucursal_id?:?int, estado?:?string, tipo?:?string} $f fechas UTC
     * @return list<array<string, mixed>>
     */
    public function search(array $f, int $limit = 200): array
    {
        $where = ['ba.tenant_id = :t'];
        $params = ['t' => $this->tenantId];
        if (!empty($f['id'])) {
            $where[] = 'ba.id = :id';
            $params['id'] = $f['id'];
        }
        if (!empty($f['desde'])) {
            $where[] = 'ba.fin_utc > :desde';
            $params['desde'] = $f['desde'];
        }
        if (!empty($f['hasta'])) {
            $where[] = 'ba.inicio_utc < :hasta';
            $params['hasta'] = $f['hasta'];
        }
        if (!empty($f['barbero_id'])) {
            // Bloqueos del barbero y los generales (sucursal o barbería) que también lo afectan.
            $where[] = '(ba.barbero_id = :b OR ba.barbero_id IS NULL)';
            $params['b'] = $f['barbero_id'];
        }
        if (!empty($f['sucursal_id'])) {
            $where[] = '(ba.sucursal_id = :s OR ba.sucursal_id IS NULL)';
            $params['s'] = $f['sucursal_id'];
        }
        if (!empty($f['estado'])) {
            $where[] = 'ba.estado = :e';
            $params['e'] = $f['estado'];
        }
        if (!empty($f['tipo'])) {
            $where[] = 'ba.tipo = :tp';
            $params['tp'] = $f['tipo'];
        }
        return $this->fetchAll(
            'SELECT ba.id, ba.tipo, ba.estado, ba.barbero_id, b.nombre_publico AS barbero, ba.sucursal_id, su.nombre AS sucursal,
                    ba.inicio_utc, ba.fin_utc, ba.motivo, ba.created_at,
                    ba.creado_por, CONCAT_WS(\' \', uc.nombres, uc.apellidos) AS creado_por_nombre,
                    ba.aprobado_por, CONCAT_WS(\' \', ua.nombres, ua.apellidos) AS aprobado_por_nombre
               FROM bloqueos_agenda ba
               LEFT JOIN barberos b ON b.tenant_id = ba.tenant_id AND b.id = ba.barbero_id
               LEFT JOIN sucursales su ON su.tenant_id = ba.tenant_id AND su.id = ba.sucursal_id
               LEFT JOIN usuarios uc ON uc.id = ba.creado_por
               LEFT JOIN usuarios ua ON ua.id = ba.aprobado_por
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY ba.inicio_utc, ba.id
              LIMIT ' . (int) $limit,
            $params
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT id, tipo, estado, barbero_id, sucursal_id, inicio_utc, fin_utc, motivo, creado_por, aprobado_por
               FROM bloqueos_agenda WHERE tenant_id = :t AND id = :id',
            ['t' => $this->tenantId, 'id' => $id]
        );
    }

    /** @param array{tipo:string, barbero_id:?int, sucursal_id:?int, inicio_utc:string, fin_utc:string, motivo:?string, estado:string, creado_por:?int, aprobado_por:?int} $d */
    public function create(array $d): int
    {
        return $this->insert(
            'INSERT INTO bloqueos_agenda (tenant_id, barbero_id, sucursal_id, tipo, inicio_utc, fin_utc, motivo, estado, creado_por, aprobado_por)
             VALUES (:t, :b, :s, :tipo, :ini, :fin, :motivo, :estado, :creado, :aprobado)',
            [
                't' => $this->tenantId, 'b' => $d['barbero_id'], 's' => $d['sucursal_id'], 'tipo' => $d['tipo'],
                'ini' => $d['inicio_utc'], 'fin' => $d['fin_utc'], 'motivo' => $d['motivo'], 'estado' => $d['estado'],
                'creado' => $d['creado_por'], 'aprobado' => $d['aprobado_por'],
            ]
        );
    }

    public function setEstado(int $id, string $estado, ?int $aprobadoPor): void
    {
        $this->execute(
            'UPDATE bloqueos_agenda SET estado = :e, aprobado_por = :u WHERE tenant_id = :t AND id = :id',
            ['e' => $estado, 'u' => $aprobadoPor, 't' => $this->tenantId, 'id' => $id]
        );
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM bloqueos_agenda WHERE tenant_id = :t AND id = :id', ['t' => $this->tenantId, 'id' => $id]);
    }

    /**
     * Bloqueos APROBADOS que afectan al barbero en el rango (UTC): los suyos y los generales.
     *
     * @return list<array{sucursal_id:?int, inicio_utc:string, fin_utc:string}>
     */
    public function approvedFor(int $barberoId, string $desdeUtc, string $hastaUtc): array
    {
        return $this->fetchAll(
            "SELECT sucursal_id, inicio_utc, fin_utc, tipo, motivo FROM bloqueos_agenda
              WHERE tenant_id = :t AND estado = 'aprobado' AND (barbero_id = :b OR barbero_id IS NULL)
                AND inicio_utc < :hasta AND fin_utc > :desde
              ORDER BY inicio_utc",
            ['t' => $this->tenantId, 'b' => $barberoId, 'desde' => $desdeUtc, 'hasta' => $hastaUtc]
        );
    }

    /**
     * Reservas activas que chocan con un bloqueo (para no dejar citas dentro de un bloqueo).
     *
     * @return list<array<string, mixed>>
     */
    public function conflictingBookings(?int $barberoId, ?int $sucursalId, string $inicioUtc, string $finUtc): array
    {
        $where = ['r.tenant_id = :t', 'r.ocupa_agenda = 1', 'r.inicio_utc < :fin', 'r.fin_utc > :ini'];
        $params = ['t' => $this->tenantId, 'ini' => $inicioUtc, 'fin' => $finUtc];
        if ($barberoId !== null) {
            $where[] = 'r.barbero_id = :b';
            $params['b'] = $barberoId;
        }
        if ($sucursalId !== null) {
            $where[] = 'r.sucursal_id = :s';
            $params['s'] = $sucursalId;
        }
        return $this->fetchAll(
            'SELECT r.id, r.codigo, r.barbero_id, b.nombre_publico AS barbero, r.inicio_utc, r.fin_utc, r.estado
               FROM reservas r JOIN barberos b ON b.tenant_id = r.tenant_id AND b.id = r.barbero_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY r.inicio_utc LIMIT 50',
            $params
        );
    }

    /**
     * Ids de barberos afectados por un bloqueo general (para bloquear sus agendas antes de validar).
     *
     * @return list<int>
     */
    public function affectedBarberIds(?int $barberoId, ?int $sucursalId): array
    {
        if ($barberoId !== null) {
            return [$barberoId];
        }
        $sql = 'SELECT DISTINCT b.id FROM barberos b WHERE b.tenant_id = :t AND b.deleted_at IS NULL';
        $params = ['t' => $this->tenantId];
        if ($sucursalId !== null) {
            $sql .= ' AND (b.sucursal_id = :s OR EXISTS (SELECT 1 FROM horarios_barberos h WHERE h.tenant_id = b.tenant_id AND h.barbero_id = b.id AND h.sucursal_id = :s2))';
            $params['s'] = $sucursalId;
            $params['s2'] = $sucursalId;
        }
        return array_map('intval', array_column($this->fetchAll($sql, $params), 'id'));
    }
}
