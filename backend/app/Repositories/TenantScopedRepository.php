<?php

declare(strict_types=1);

namespace App\Repositories;

use InvalidArgumentException;
use PDO;

/**
 * Base para repositorios de datos de una barbería. El tenant se fija al construir el
 * repositorio (desde el contexto verificado por el backend) y toda consulta debe filtrar
 * por $this->tenantId. Así un controlador no puede "olvidar" el aislamiento.
 */
abstract class TenantScopedRepository extends BaseRepository
{
    public function __construct(protected int $tenantId, ?PDO $db = null)
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('tenant_id inválido para repositorio con alcance de tenant.');
        }
        parent::__construct($db);
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }
}
