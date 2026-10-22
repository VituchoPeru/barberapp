<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Clientes vistos desde el panel de UNA barbería (Fase 6). Una barbería solo ve a las personas
 * vinculadas a ella en cliente_tenants (reservaron allí o fueron registradas en su mostrador);
 * nunca el resto de la base de clientes de la plataforma.
 */
final class PanelClientRepository extends TenantScopedRepository
{
    private const SELECT = "SELECT c.id, c.nombres, c.apellidos, c.telefono, c.email, c.usuario_id, c.created_at AS cliente_desde,
                ct.origen, ct.notas_internas, ct.bloqueado, ct.primera_visita_en, ct.ultima_visita_en, ct.total_reservas,
                ct.total_inasistencias, ct.created_at AS vinculado_en,
                (SELECT MIN(r.inicio_utc) FROM reservas r WHERE r.tenant_id = ct.tenant_id AND r.cliente_id = ct.cliente_id
                    AND r.ocupa_agenda = 1 AND r.fin_utc > :now) AS proxima_cita_utc
           FROM cliente_tenants ct JOIN clientes c ON c.id = ct.cliente_id AND c.deleted_at IS NULL";

    /**
     * Búsqueda por nombre, teléfono o correo. Sin texto devuelve los más recientes.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(string $q, string $nowUtc, string $orden, int $limit, int $offset): array
    {
        $where = ' WHERE ct.tenant_id = :t';
        $params = ['t' => $this->tenantId];
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . addcslashes(mb_strtolower($q), '%_\\') . '%';
            $digits = preg_replace('/\D/', '', $q);
            $where .= " AND (LOWER(CONCAT(c.nombres, ' ', COALESCE(c.apellidos, ''))) LIKE :q1 OR LOWER(c.email) LIKE :q2"
                . ($digits !== '' && strlen((string) $digits) >= 3 ? ' OR c.telefono LIKE :q3' : '') . ')';
            $params['q1'] = $like;
            $params['q2'] = $like;
            if ($digits !== '' && strlen((string) $digits) >= 3) {
                $params['q3'] = '%' . $digits . '%';
            }
        }
        $order = match ($orden) {
            'nombre' => 'c.nombres, c.apellidos, c.id',
            'reservas' => 'ct.total_reservas DESC, c.id',
            default => 'COALESCE(ct.ultima_visita_en, ct.created_at) DESC, c.id DESC',
        };
        $total = (int) ($this->fetchOne('SELECT COUNT(*) AS n FROM cliente_tenants ct JOIN clientes c ON c.id = ct.cliente_id AND c.deleted_at IS NULL' . $where, $params)['n'] ?? 0);
        $items = $this->fetchAll(self::SELECT . $where . " ORDER BY {$order} LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset), $params + ['now' => $nowUtc]);
        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function find(int $clienteId, string $nowUtc): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE ct.tenant_id = :t AND ct.cliente_id = :c', ['t' => $this->tenantId, 'c' => $clienteId, 'now' => $nowUtc]);
    }

    /** Bloquea el vínculo cliente–barbería (mismo candado que la reserva en línea). @return array<string, mixed>|null */
    public function lockLink(int $clienteId): ?array
    {
        return $this->fetchOne(
            'SELECT ct.id, ct.bloqueado FROM cliente_tenants ct JOIN clientes c ON c.id = ct.cliente_id AND c.deleted_at IS NULL
              WHERE ct.tenant_id = :t AND ct.cliente_id = :c FOR UPDATE',
            ['t' => $this->tenantId, 'c' => $clienteId]
        );
    }

    /** Cliente ya vinculado con el mismo teléfono o correo (para no duplicar fichas de mostrador). */
    public function findByContact(?string $telefono, ?string $email): ?int
    {
        if ($telefono === null && $email === null) {
            return null;
        }
        $conds = [];
        $params = ['t' => $this->tenantId];
        if ($telefono !== null) {
            $conds[] = 'c.telefono = :tel';
            $params['tel'] = $telefono;
        }
        if ($email !== null) {
            $conds[] = 'c.email = :email';
            $params['email'] = $email;
        }
        $row = $this->fetchOne(
            'SELECT c.id FROM cliente_tenants ct JOIN clientes c ON c.id = ct.cliente_id AND c.deleted_at IS NULL
              WHERE ct.tenant_id = :t AND (' . implode(' OR ', $conds) . ') ORDER BY c.usuario_id IS NULL, c.id LIMIT 1',
            $params
        );
        return $row === null ? null : (int) $row['id'];
    }

    /**
     * Cliente de mostrador (sin cuenta) vinculado a esta barbería. Si luego crea una cuenta con
     * ese correo y lo verifica, la ficha pasa a su cuenta (decisión 043).
     *
     * @param array{nombres:string, apellidos:?string, telefono:?string, email:?string} $d
     */
    public function createCounterClient(array $d): int
    {
        $id = $this->insert(
            'INSERT INTO clientes (usuario_id, nombres, apellidos, telefono, email) VALUES (NULL, :n, :a, :tel, :e)',
            ['n' => $d['nombres'], 'a' => $d['apellidos'], 'tel' => $d['telefono'], 'e' => $d['email']]
        );
        $this->insert(
            "INSERT INTO cliente_tenants (tenant_id, cliente_id, origen) VALUES (:t, :c, 'panel')",
            ['t' => $this->tenantId, 'c' => $id]
        );
        return $id;
    }

    /**
     * Actualiza datos de contacto de un cliente de mostrador. Los clientes con cuenta administran
     * sus propios datos: aquí no se tocan.
     *
     * @param array<string, mixed> $d
     */
    public function updateCounterClient(int $clienteId, array $d): bool
    {
        if ($d === []) {
            return false;
        }
        $sets = [];
        $params = ['id' => $clienteId, 't' => $this->tenantId];
        foreach ($d as $k => $v) {
            $sets[] = "c.{$k} = :{$k}";
            $params[$k] = $v;
        }
        return $this->execute(
            'UPDATE clientes c JOIN cliente_tenants ct ON ct.cliente_id = c.id AND ct.tenant_id = :t
                SET ' . implode(', ', $sets) . ' WHERE c.id = :id AND c.usuario_id IS NULL',
            $params
        ) > 0;
    }

    /** @param array<string, mixed> $d notas_internas, bloqueado */
    public function updateLink(int $clienteId, array $d): void
    {
        if ($d === []) {
            return;
        }
        $sets = [];
        $params = ['t' => $this->tenantId, 'c' => $clienteId];
        foreach ($d as $k => $v) {
            $sets[] = "{$k} = :{$k}";
            $params[$k] = is_bool($v) ? (int) $v : $v;
        }
        $this->execute('UPDATE cliente_tenants SET ' . implode(', ', $sets) . ' WHERE tenant_id = :t AND cliente_id = :c', $params);
    }

    /**
     * Resumen de la relación del cliente con la barbería (todo calculado desde sus reservas).
     *
     * @return array<string, mixed>
     */
    public function stats(int $clienteId): array
    {
        $rows = $this->fetchAll(
            'SELECT estado, COUNT(*) AS n FROM reservas WHERE tenant_id = :t AND cliente_id = :c GROUP BY estado',
            ['t' => $this->tenantId, 'c' => $clienteId]
        );
        $porEstado = ['pendiente' => 0, 'confirmada' => 0, 'completada' => 0, 'cancelada' => 0, 'no_asistio' => 0];
        foreach ($rows as $r) {
            $porEstado[(string) $r['estado']] = (int) $r['n'];
        }
        $servicios = $this->fetchAll(
            "SELECT rs.nombre_servicio AS nombre, COUNT(*) AS veces FROM reserva_servicios rs
               JOIN reservas r ON r.tenant_id = rs.tenant_id AND r.id = rs.reserva_id
              WHERE rs.tenant_id = :t AND r.cliente_id = :c AND r.estado = 'completada'
              GROUP BY rs.nombre_servicio ORDER BY veces DESC, nombre LIMIT 3",
            ['t' => $this->tenantId, 'c' => $clienteId]
        );
        $barbero = $this->fetchOne(
            "SELECT b.nombre_publico AS nombre, COUNT(*) AS veces FROM reservas r JOIN barberos b ON b.tenant_id = r.tenant_id AND b.id = r.barbero_id
              WHERE r.tenant_id = :t AND r.cliente_id = :c AND r.estado = 'completada'
              GROUP BY b.id, b.nombre_publico ORDER BY veces DESC, b.nombre_publico LIMIT 1",
            ['t' => $this->tenantId, 'c' => $clienteId]
        );
        return [
            'por_estado' => $porEstado,
            'servicios_frecuentes' => array_map(static fn (array $s): array => ['nombre' => (string) $s['nombre'], 'veces' => (int) $s['veces']], $servicios),
            'barbero_frecuente' => $barbero === null ? null : ['nombre' => (string) $barbero['nombre'], 'veces' => (int) $barbero['veces']],
        ];
    }

    /** Total cobrado (cobros registrados, no anulados) al cliente en esta barbería. */
    public function paidTotal(int $clienteId): string
    {
        return (string) ($this->fetchOne(
            'SELECT COALESCE(SUM(p.monto), 0) AS s FROM reserva_pagos p JOIN reservas r ON r.tenant_id = p.tenant_id AND r.id = p.reserva_id
              WHERE p.tenant_id = :t AND r.cliente_id = :c AND p.anulado_en IS NULL',
            ['t' => $this->tenantId, 'c' => $clienteId]
        )['s'] ?? '0');
    }
}
