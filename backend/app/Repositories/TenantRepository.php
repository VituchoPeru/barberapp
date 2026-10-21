<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Config;
use App\Helpers\Clock;
use App\Helpers\Slug;

/** Alta y consulta básica de barberías (tenants). */
final class TenantRepository extends BaseRepository
{
    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->fetchOne(
            'SELECT id, slug, nombre_comercial, estado, zona_horaria, logo_url FROM tenants WHERE slug = :s AND deleted_at IS NULL',
            ['s' => $slug]
        );
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT id, slug, nombre_comercial, estado, zona_horaria, logo_url FROM tenants WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id]
        );
    }

    public function slugAvailable(string $slug): bool
    {
        if (in_array($slug, (array) Config::get('auth.reserved_slugs', []), true)) {
            return false;
        }
        return $this->fetchOne('SELECT id FROM tenants WHERE slug = :s', ['s' => $slug]) === null;
    }

    /** Primer slug libre a partir de un texto: "barberia-x", "barberia-x-2", … */
    public function uniqueSlug(string $base): string
    {
        $base = Slug::from($base, 60);
        if (strlen($base) < 3) {
            $base = trim('barberia-' . $base, '-');
        }
        $candidate = $base;
        for ($i = 2; !$this->slugAvailable($candidate); $i++) {
            $candidate = $base . '-' . $i;
        }
        return $candidate;
    }

    /** ¿Las nuevas barberías requieren aprobación del superadministrador? (configuraciones globales) */
    public function requiresApproval(): bool
    {
        $row = $this->fetchOne("SELECT valor FROM configuraciones WHERE tenant_id IS NULL AND clave = 'plataforma.aprobacion_manual_tenants'");
        return $row === null ? true : (bool) json_decode((string) $row['valor'], true);
    }

    /** @param array{slug:string, nombre_comercial:string, telefono:?string, ruc:?string, email:string, propietario:int, estado:string} $data */
    public function create(array $data): int
    {
        $now = Clock::nowSql();
        $id = $this->insert(
            'INSERT INTO tenants (slug, nombre_comercial, ruc, email, telefono, estado, propietario_usuario_id, aprobado_en, created_at, updated_at)
             VALUES (:slug, :nombre, :ruc, :email, :tel, :estado, :prop, :aprobado, :c, :u)',
            [
                'slug' => $data['slug'],
                'nombre' => $data['nombre_comercial'],
                'ruc' => $data['ruc'],
                'email' => $data['email'],
                'tel' => $data['telefono'],
                'estado' => $data['estado'],
                'prop' => $data['propietario'],
                'aprobado' => $data['estado'] === 'activo' ? $now : null,
                'c' => $now,
                'u' => $now,
            ]
        );
        // Política de reserva por defecto de la barbería (valores por defecto de la tabla).
        $this->execute('INSERT INTO politicas_reserva (tenant_id) VALUES (:t)', ['t' => $id]);
        return $id;
    }

    public function rucExists(string $ruc): bool
    {
        return $this->fetchOne('SELECT id FROM tenants WHERE ruc = :r', ['r' => $ruc]) !== null;
    }
}
