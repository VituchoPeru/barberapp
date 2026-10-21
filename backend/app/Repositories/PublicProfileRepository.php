<?php

declare(strict_types=1);

namespace App\Repositories;

/** Datos del perfil público editables desde el panel de la barbería. Siempre filtrados por tenant. */
final class PublicProfileRepository extends TenantScopedRepository
{
    public const CAMPOS = ['descripcion', 'telefono', 'whatsapp', 'instagram', 'facebook', 'tiktok', 'sitio_web', 'publicado_marketplace'];

    /** @return array<string, mixed> */
    public function profile(): array
    {
        return (array) $this->fetchOne(
            'SELECT id, slug, nombre_comercial, estado, descripcion, telefono, whatsapp, instagram, facebook, tiktok, sitio_web,
                    publicado_marketplace, logo_url, portada_url
               FROM tenants WHERE id = :t',
            ['t' => $this->tenantId]
        );
    }

    /** @param array<string, mixed> $data solo claves de CAMPOS */
    public function update(array $data): void
    {
        $sets = [];
        $params = ['t' => $this->tenantId];
        foreach ($data as $campo => $valor) {
            if (!in_array($campo, self::CAMPOS, true)) {
                continue;
            }
            $sets[] = "{$campo} = :{$campo}";
            $params[$campo] = is_bool($valor) ? (int) $valor : $valor;
        }
        if ($sets !== []) {
            $this->execute('UPDATE tenants SET ' . implode(', ', $sets) . ' WHERE id = :t', $params);
        }
    }

    /** @return array{sucursal:bool, horario:bool, servicio:bool, barbero:bool} requisitos para publicarse */
    public function readiness(): array
    {
        $t = ['t' => $this->tenantId];
        return [
            'sucursal' => $this->exists('SELECT 1 FROM sucursales WHERE tenant_id = :t AND activo = 1 AND deleted_at IS NULL', $t),
            'horario' => $this->exists(
                'SELECT 1 FROM horarios_sucursales h JOIN sucursales s ON s.tenant_id = h.tenant_id AND s.id = h.sucursal_id
                  WHERE h.tenant_id = :t AND s.activo = 1 AND s.deleted_at IS NULL',
                $t
            ),
            'servicio' => $this->exists('SELECT 1 FROM servicios WHERE tenant_id = :t AND activo = 1 AND deleted_at IS NULL', $t),
            'barbero' => $this->exists(
                'SELECT 1 FROM barberos b JOIN barbero_servicios bs ON bs.tenant_id = b.tenant_id AND bs.barbero_id = b.id
                   JOIN servicios s ON s.tenant_id = bs.tenant_id AND s.id = bs.servicio_id AND s.activo = 1 AND s.deleted_at IS NULL
                  WHERE b.tenant_id = :t AND b.activo = 1 AND b.deleted_at IS NULL',
                $t
            ),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function photos(): array
    {
        return $this->fetchAll(
            'SELECT id, tipo, ruta, ancho, alto, bytes, texto_alternativo, sucursal_id, orden, created_at
               FROM tenant_fotos WHERE tenant_id = :t ORDER BY FIELD(tipo, \'logo\', \'portada\', \'galeria\'), orden, id',
            ['t' => $this->tenantId]
        );
    }

    public function countGallery(): int
    {
        return (int) ($this->fetchOne("SELECT COUNT(*) AS n FROM tenant_fotos WHERE tenant_id = :t AND tipo = 'galeria'", ['t' => $this->tenantId])['n'] ?? 0);
    }

    /** @param array{ruta:string, ancho:int, alto:int, bytes:int} $img */
    public function addPhoto(string $tipo, array $img, ?string $alt, ?int $userId): int
    {
        $orden = (int) ($this->fetchOne('SELECT COALESCE(MAX(orden), 0) + 1 AS o FROM tenant_fotos WHERE tenant_id = :t AND tipo = :tipo', ['t' => $this->tenantId, 'tipo' => $tipo])['o'] ?? 1);
        return $this->insert(
            'INSERT INTO tenant_fotos (tenant_id, tipo, ruta, ancho, alto, bytes, texto_alternativo, orden, subido_por)
             VALUES (:t, :tipo, :ruta, :w, :h, :b, :alt, :o, :u)',
            ['t' => $this->tenantId, 'tipo' => $tipo, 'ruta' => $img['ruta'], 'w' => $img['ancho'], 'h' => $img['alto'], 'b' => $img['bytes'], 'alt' => $alt, 'o' => $orden, 'u' => $userId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findPhoto(int $id): ?array
    {
        return $this->fetchOne('SELECT id, tipo, ruta FROM tenant_fotos WHERE tenant_id = :t AND id = :id', ['t' => $this->tenantId, 'id' => $id]);
    }

    /** @return list<array<string, mixed>> fotos del mismo tipo distintas de $exceptId (logo/portada son únicos) */
    public function othersOfType(string $tipo, int $exceptId): array
    {
        return $this->fetchAll('SELECT id, ruta FROM tenant_fotos WHERE tenant_id = :t AND tipo = :tipo AND id <> :id', ['t' => $this->tenantId, 'tipo' => $tipo, 'id' => $exceptId]);
    }

    public function deletePhoto(int $id): void
    {
        $this->execute('DELETE FROM tenant_fotos WHERE tenant_id = :t AND id = :id', ['t' => $this->tenantId, 'id' => $id]);
    }

    /** Logo y portada se reflejan en tenants.logo_url / portada_url (ruta relativa o null). */
    public function setBrandImage(string $tipo, ?string $ruta): void
    {
        $col = $tipo === 'logo' ? 'logo_url' : 'portada_url';
        $this->execute("UPDATE tenants SET {$col} = :r WHERE id = :t", ['r' => $ruta, 't' => $this->tenantId]);
    }

    /** @param array<string, mixed> $params */
    private function exists(string $sql, array $params): bool
    {
        return $this->fetchOne($sql . ' LIMIT 1', $params) !== null;
    }
}
