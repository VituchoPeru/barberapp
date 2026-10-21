<?php

declare(strict_types=1);

namespace App\Repositories;

use PDOException;

/**
 * Perfil de cliente (tabla clientes) de una cuenta y su vínculo con cada barbería (cliente_tenants).
 * No tiene alcance de tenant porque una persona usa la misma ficha en todas las barberías;
 * cada barbería solo ve a los clientes vinculados a ella.
 */
final class ClientRepository extends BaseRepository
{
    public function idForUser(int $userId): ?int
    {
        $row = $this->fetchOne('SELECT id FROM clientes WHERE usuario_id = :u AND deleted_at IS NULL', ['u' => $userId]);
        return $row === null ? null : (int) $row['id'];
    }

    /**
     * Ficha de cliente de la cuenta; la crea si no existe. Si el correo de la cuenta está verificado
     * y la barbería ya lo había registrado en mostrador (cliente sin cuenta con ese correo),
     * se vincula esa ficha para que la persona vea sus citas anteriores.
     *
     * @param array<string, mixed> $user fila de usuarios
     * @return array{id:int, vinculado_mostrador:bool}
     */
    public function ensureForUser(array $user): array
    {
        $userId = (int) $user['id'];
        $own = $this->idForUser($userId);
        $verified = !empty($user['email_verificado_en']);
        $counter = $verified ? $this->counterClientByEmail((string) $user['email']) : null;

        if ($own !== null) {
            // Solo se fusiona si la ficha propia aún no tiene historial en ninguna barbería.
            if ($counter !== null && !$this->hasTenantLinks($own)) {
                $this->execute('INSERT IGNORE INTO favoritos (cliente_id, tenant_id, created_at) SELECT :c, tenant_id, created_at FROM favoritos WHERE cliente_id = :o', ['c' => $counter, 'o' => $own]);
                $this->execute('DELETE FROM favoritos WHERE cliente_id = :o', ['o' => $own]);
                $this->execute('DELETE FROM clientes WHERE id = :o AND usuario_id = :u', ['o' => $own, 'u' => $userId]);
                if ($this->claim($counter, $userId)) {
                    return ['id' => $counter, 'vinculado_mostrador' => true];
                }
                // Otra petición se adelantó: se recrea la ficha propia.
                return ['id' => $this->create($user), 'vinculado_mostrador' => false];
            }
            return ['id' => $own, 'vinculado_mostrador' => false];
        }
        if ($counter !== null && $this->claim($counter, $userId)) {
            return ['id' => $counter, 'vinculado_mostrador' => true];
        }
        try {
            return ['id' => $this->create($user), 'vinculado_mostrador' => false];
        } catch (PDOException $e) {
            // Dos peticiones simultáneas de la misma cuenta: gana la primera.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 && ($id = $this->idForUser($userId)) !== null) {
                return ['id' => $id, 'vinculado_mostrador' => false];
            }
            throw $e;
        }
    }

    /** Vincula al cliente con la barbería (si no lo estaba) y bloquea esa fila hasta el fin de la transacción. @return array<string, mixed> */
    public function lockTenantLink(int $tenantId, int $clienteId, string $origen): array
    {
        $this->execute(
            'INSERT INTO cliente_tenants (tenant_id, cliente_id, origen) VALUES (:t, :c, :o) ON DUPLICATE KEY UPDATE id = id',
            ['t' => $tenantId, 'c' => $clienteId, 'o' => $origen]
        );
        return (array) $this->fetchOne(
            'SELECT id, bloqueado FROM cliente_tenants WHERE tenant_id = :t AND cliente_id = :c FOR UPDATE',
            ['t' => $tenantId, 'c' => $clienteId]
        );
    }

    /**
     * Otra cita activa del mismo cliente (en cualquier barbería) que se cruza con el intervalo.
     *
     * @return array<string, mixed>|null
     */
    public function overlapping(int $clienteId, string $inicioUtc, string $finUtc, ?int $excludeId = null): ?array
    {
        return $this->fetchOne(
            'SELECT r.codigo, t.nombre_comercial FROM reservas r JOIN tenants t ON t.id = r.tenant_id
              WHERE r.cliente_id = :c AND r.ocupa_agenda = 1 AND r.inicio_utc < :fin AND r.fin_utc > :ini AND r.id <> :ex
              LIMIT 1',
            ['c' => $clienteId, 'ini' => $inicioUtc, 'fin' => $finUtc, 'ex' => $excludeId ?? 0]
        );
    }

    private function counterClientByEmail(string $email): ?int
    {
        $rows = $this->fetchAll(
            'SELECT id FROM clientes WHERE usuario_id IS NULL AND deleted_at IS NULL AND email = :e LIMIT 2',
            ['e' => mb_strtolower(trim($email))]
        );
        // Si hay más de una ficha con ese correo no se adivina cuál es: la barbería deberá unificarlas.
        return count($rows) === 1 ? (int) $rows[0]['id'] : null;
    }

    private function hasTenantLinks(int $clienteId): bool
    {
        return $this->fetchOne('SELECT 1 FROM cliente_tenants WHERE cliente_id = :c LIMIT 1', ['c' => $clienteId]) !== null;
    }

    private function claim(int $clienteId, int $userId): bool
    {
        return $this->execute('UPDATE clientes SET usuario_id = :u WHERE id = :c AND usuario_id IS NULL', ['u' => $userId, 'c' => $clienteId]) === 1;
    }

    /** @param array<string, mixed> $user */
    private function create(array $user): int
    {
        return (new UserRepository($this->db))->createClienteProfile((int) $user['id'], [
            'nombres' => (string) $user['nombres'],
            'apellidos' => $user['apellidos'] ?? null,
            'telefono' => $user['telefono'] ?? null,
            'email' => (string) $user['email'],
        ]);
    }
}
