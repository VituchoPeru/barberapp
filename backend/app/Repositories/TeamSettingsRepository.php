<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Ajustes del equipo de una barbería (Fase 7), guardados en `configuraciones` (tenant_id + clave).
 * Sin fila se usan los valores por defecto, que son los más prudentes.
 */
final class TeamSettingsRepository extends TenantScopedRepository
{
    /** clave → [valor por defecto, descripción] */
    public const AJUSTES = [
        'ausencias_requieren_aprobacion' => [true, 'Las ausencias que piden los barberos esperan la aprobación del encargado'],
        'barbero_edita_horario' => [false, 'El barbero puede cambiar su propia jornada semanal desde su panel'],
    ];

    /** @return array<string, bool> */
    public function all(): array
    {
        $out = array_map(static fn (array $a): bool => $a[0], self::AJUSTES);
        $rows = $this->fetchAll(
            "SELECT clave, valor FROM configuraciones WHERE tenant_id = :t AND clave LIKE 'equipo.%'",
            ['t' => $this->tenantId]
        );
        foreach ($rows as $r) {
            $k = substr((string) $r['clave'], 7);
            if (array_key_exists($k, $out)) {
                $out[$k] = (bool) json_decode((string) $r['valor'], true);
            }
        }
        return $out;
    }

    public function set(string $clave, bool $valor, int $usuarioId): void
    {
        $this->execute(
            'INSERT INTO configuraciones (tenant_id, clave, valor, descripcion, es_publica, actualizado_por)
             VALUES (:t, :c, :v, :d, 0, :u)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), actualizado_por = VALUES(actualizado_por)',
            ['t' => $this->tenantId, 'c' => 'equipo.' . $clave, 'v' => json_encode($valor), 'd' => self::AJUSTES[$clave][1], 'u' => $usuarioId]
        );
    }
}
