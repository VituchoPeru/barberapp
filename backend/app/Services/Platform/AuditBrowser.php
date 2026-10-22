<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Helpers\Clock;
use App\Helpers\Ip;
use App\Repositories\BaseRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Consulta de la bitácora de auditoría para el superadministrador (Fase 9). Solo lectura:
 * la tabla es de solo INSERT. Filtros por acción (prefijo), resultado, barbería, cuenta y
 * fechas en la zona horaria de la plataforma; paginación por id descendente.
 */
final class AuditBrowser extends BaseRepository
{
    /**
     * @param array<string, mixed> $f accion, resultado, tenant (slug), usuario (correo), desde, hasta, antes_de (id)
     * @return array{items: list<array<string, mixed>>, siguiente: ?int}
     */
    public function list(array $f, int $limit): array
    {
        $where = ['1 = 1'];
        $p = [];
        if (!empty($f['accion']) && is_string($f['accion']) && preg_match('/^[a-z0-9_.]{2,100}$/', $f['accion'])) {
            $where[] = 'a.accion LIKE :accion';
            $p['accion'] = str_replace('_', '\\_', $f['accion']) . '%';
        }
        if (!empty($f['resultado']) && in_array($f['resultado'], ['exito', 'denegado', 'error'], true)) {
            $where[] = 'a.resultado = :resultado';
            $p['resultado'] = $f['resultado'];
        }
        if (!empty($f['tenant']) && is_string($f['tenant'])) {
            $where[] = 'a.tenant_id = (SELECT id FROM tenants WHERE slug = :slug)';
            $p['slug'] = strtolower(trim($f['tenant']));
        }
        if (!empty($f['usuario']) && is_string($f['usuario'])) {
            $where[] = 'a.usuario_id = (SELECT id FROM usuarios WHERE email = :email)';
            $p['email'] = strtolower(trim($f['usuario']));
        }
        $tz = new DateTimeZone(PlatformSettings::timezone());
        foreach (['desde' => '>=', 'hasta' => '<'] as $k => $op) {
            if (!empty($f[$k]) && is_string($f[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) {
                $d = new DateTimeImmutable($f[$k] . ' 00:00:00', $tz);
                if ($k === 'hasta') {
                    $d = $d->modify('+1 day');
                }
                $where[] = "a.created_at {$op} :{$k}";
                $p[$k] = $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }
        if (!empty($f['antes_de']) && ctype_digit((string) $f['antes_de'])) {
            $where[] = 'a.id < :antes';
            $p['antes'] = (int) $f['antes_de'];
        }
        $rows = $this->fetchAll(
            'SELECT a.*, t.slug tenant_slug, t.nombre_comercial tenant_nombre, u.email usuario_email
               FROM auditoria a
               LEFT JOIN tenants t ON t.id = a.tenant_id
               LEFT JOIN usuarios u ON u.id = a.usuario_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY a.id DESC LIMIT ' . ($limit + 1),
            $p
        );
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return [
            'items' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'fecha' => str_replace(' ', 'T', (string) $r['created_at']) . 'Z',
                'accion' => (string) $r['accion'],
                'resultado' => (string) $r['resultado'],
                'barberia' => $r['tenant_id'] === null ? null : ['id' => (int) $r['tenant_id'], 'slug' => $r['tenant_slug'], 'nombre' => $r['tenant_nombre']],
                'usuario' => $r['usuario_id'] === null ? null : ['id' => (int) $r['usuario_id'], 'email' => $r['usuario_email']],
                'entidad' => $r['entidad'],
                'entidad_id' => $r['entidad_id'] !== null ? (int) $r['entidad_id'] : null,
                'datos_anteriores' => json_decode((string) ($r['datos_anteriores'] ?? 'null'), true),
                'datos_nuevos' => json_decode((string) ($r['datos_nuevos'] ?? 'null'), true),
                'ip' => Ip::fromBinary($r['ip']),
                'user_agent' => $r['user_agent'],
                'request_id' => $r['request_id'],
            ], $rows),
            'siguiente' => $more && $rows !== [] ? (int) end($rows)['id'] : null,
        ];
    }

    /**
     * Familias de acciones (primeros dos segmentos) de los últimos 90 días, para el filtro.
     *
     * @return list<array{accion:string, total:int}>
     */
    public function actions(): array
    {
        $rows = $this->fetchAll(
            "SELECT SUBSTRING_INDEX(accion, '.', 2) familia, COUNT(*) n FROM auditoria WHERE created_at >= :d GROUP BY familia ORDER BY familia",
            ['d' => Clock::nowUtc()->modify('-90 days')->format('Y-m-d H:i:s')]
        );
        return array_map(static fn (array $r): array => ['accion' => (string) $r['familia'], 'total' => (int) $r['n']], $rows);
    }
}
