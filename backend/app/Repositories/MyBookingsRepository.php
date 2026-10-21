<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Reservas de UNA persona en todas las barberías (pantalla "Mis citas").
 * Siempre filtra por la cuenta autenticada: clientes.usuario_id = usuario.
 */
final class MyBookingsRepository extends BaseRepository
{
    /**
     * Ubica una reserva por id sin revelar datos: tenant y dueño.
     *
     * @return array{tenant_id:int, usuario_id:?int}|null
     */
    public function locate(int $id): ?array
    {
        $row = $this->fetchOne('SELECT r.tenant_id, c.usuario_id FROM reservas r JOIN clientes c ON c.id = r.cliente_id WHERE r.id = :id', ['id' => $id]);
        return $row === null ? null : ['tenant_id' => (int) $row['tenant_id'], 'usuario_id' => $row['usuario_id'] === null ? null : (int) $row['usuario_id']];
    }

    /**
     * @param string $tipo proximas (activas por venir, la más cercana primero) | pasadas (el resto, la más reciente primero)
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function list(int $userId, string $tipo, string $nowUtc, int $limit, int $offset): array
    {
        $cond = $tipo === 'proximas'
            ? 'r.ocupa_agenda = 1 AND r.fin_utc > :now'
            : 'NOT (r.ocupa_agenda = 1 AND r.fin_utc > :now)';
        $order = $tipo === 'proximas' ? 'r.inicio_utc ASC, r.id ASC' : 'r.inicio_utc DESC, r.id DESC';
        $params = ['u' => $userId, 'now' => $nowUtc];
        $total = (int) ($this->fetchOne(
            "SELECT COUNT(*) AS n FROM reservas r JOIN clientes c ON c.id = r.cliente_id WHERE c.usuario_id = :u AND {$cond}",
            $params
        )['n'] ?? 0);
        $items = $total === 0 ? [] : $this->fetchAll(
            BookingRepository::SELECT_DETAIL . " WHERE c.usuario_id = :u AND {$cond} ORDER BY {$order} LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset,
            $params
        );
        return ['items' => $items, 'total' => $total];
    }

    /**
     * Servicios de varias reservas.
     *
     * @param list<int> $ids
     * @return array<int, list<array<string, mixed>>>
     */
    public function servicesFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->fetchAll(
            'SELECT reserva_id, servicio_id, orden, nombre_servicio, duracion_minutos, precio, inicio_utc, fin_utc
               FROM reserva_servicios WHERE reserva_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY reserva_id, orden'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['reserva_id']][] = $r;
        }
        return $out;
    }

    /**
     * Política aplicable a cada (barbería, sucursal) de la lista.
     *
     * @param list<array{0:int, 1:int}> $pairs
     * @return array<string, array<string, mixed>|null> "tenant:sucursal" => fila
     */
    public function policies(array $pairs): array
    {
        $out = [];
        foreach ($pairs as [$t, $s]) {
            $key = "{$t}:{$s}";
            if (!array_key_exists($key, $out)) {
                $out[$key] = (new BookingRepository($t, $this->db))->policyRow($s);
            }
        }
        return $out;
    }
}
