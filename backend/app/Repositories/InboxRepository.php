<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Bandeja de avisos de la cuenta (canal "interna" ya entregado). Siempre filtrada por el
 * usuario autenticado: nadie puede leer ni marcar avisos de otra persona.
 */
final class InboxRepository extends BaseRepository
{
    /** @return array{items: list<array<string, mixed>>, total: int, no_leidas: int} */
    public function list(int $userId, bool $soloNoLeidas, int $limit, int $offset): array
    {
        $where = "n.usuario_id = :u AND n.canal = 'interna' AND n.estado = 'enviada'" . ($soloNoLeidas ? ' AND n.leida_en IS NULL' : '');
        $items = $this->fetchAll(
            "SELECT n.id, n.tipo, n.titulo, n.cuerpo, n.datos, n.enviada_en, n.leida_en, n.reserva_id,
                    t.slug AS tenant_slug, t.nombre_comercial AS tenant_nombre,
                    r.estado AS reserva_estado, r.inicio_utc AS reserva_inicio, r.asistencia_confirmada_en,
                    c.usuario_id AS reserva_cliente_usuario
               FROM notificaciones n
               LEFT JOIN tenants t ON t.id = n.tenant_id
               LEFT JOIN reservas r ON r.tenant_id = n.tenant_id AND r.id = n.reserva_id
               LEFT JOIN clientes c ON c.id = r.cliente_id
              WHERE {$where}
              ORDER BY n.enviada_en DESC, n.id DESC
              LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset,
            ['u' => $userId]
        );
        $total = (int) ($this->fetchOne("SELECT COUNT(*) AS c FROM notificaciones n WHERE {$where}", ['u' => $userId])['c'] ?? 0);
        return ['items' => $items, 'total' => $total, 'no_leidas' => $this->unread($userId)];
    }

    public function unread(int $userId): int
    {
        return (int) ($this->fetchOne(
            "SELECT COUNT(*) AS c FROM notificaciones WHERE usuario_id = :u AND canal = 'interna' AND estado = 'enviada' AND leida_en IS NULL",
            ['u' => $userId]
        )['c'] ?? 0);
    }

    /** Devuelve false si el aviso no existe o no es de la persona. */
    public function markRead(int $userId, int $id, string $now): bool
    {
        $row = $this->fetchOne(
            "SELECT id FROM notificaciones WHERE id = :id AND usuario_id = :u AND canal = 'interna' AND estado = 'enviada'",
            ['id' => $id, 'u' => $userId]
        );
        if ($row === null) {
            return false;
        }
        $this->execute('UPDATE notificaciones SET leida_en = COALESCE(leida_en, :n) WHERE id = :id', ['n' => $now, 'id' => $id]);
        return true;
    }

    public function markAllRead(int $userId, string $now): int
    {
        return $this->execute(
            "UPDATE notificaciones SET leida_en = :n WHERE usuario_id = :u AND canal = 'interna' AND estado = 'enviada' AND leida_en IS NULL",
            ['n' => $now, 'u' => $userId]
        );
    }
}
