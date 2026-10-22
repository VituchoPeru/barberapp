<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Helpers\Clock;
use App\Repositories\BaseRepository;
use App\Services\Audit;

/**
 * Gestión de barberías desde el panel SaaS (Fase 9): listado, ficha, aprobar, rechazar,
 * suspender y reactivar.
 *
 * Transiciones:   pendiente → activo (aprobar) | cancelado (rechazar)
 *                 activo    → suspendido (suspender, con motivo)
 *                 suspendido → activo (reactivar)
 * Una barbería suspendida conserva sus datos y su panel (para regularizar), pero no aparece
 * en el marketplace ni acepta reservas nuevas; las citas ya reservadas no se cancelan solas.
 *
 * La ficha muestra solo datos del negocio y cifras agregadas; nunca los clientes de la barbería.
 */
final class TenantAdminService extends BaseRepository
{
    public const ESTADOS = ['pendiente', 'activo', 'suspendido', 'cancelado'];

    /**
     * @param array<string, mixed> $f q, estado, plan, demo (1|0), page, per_page
     * @return array{items: list<array<string, mixed>>, total:int, resumen: array<string, int>}
     */
    public function list(array $f, int $limit, int $offset): array
    {
        $where = ['t.deleted_at IS NULL'];
        $p = [];
        if (!empty($f['q'])) {
            $q = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(trim((string) $f['q']), 0, 80)) . '%';
            $where[] = '(t.nombre_comercial LIKE :q1 OR t.slug LIKE :q2 OR t.ruc LIKE :q3 OR t.email LIKE :q4 OR u.email LIKE :q5)';
            $p += ['q1' => $q, 'q2' => $q, 'q3' => $q, 'q4' => $q, 'q5' => $q];
        }
        if (!empty($f['estado']) && in_array($f['estado'], self::ESTADOS, true)) {
            $where[] = 't.estado = :estado';
            $p['estado'] = $f['estado'];
        }
        if (!empty($f['plan'])) {
            $where[] = 'pl.codigo = :plan';
            $p['plan'] = (string) $f['plan'];
        }
        if (isset($f['demo']) && $f['demo'] !== '' && in_array((string) $f['demo'], ['0', '1'], true)) {
            $where[] = 't.es_demo = :demo';
            $p['demo'] = (int) $f['demo'];
        }
        $from = 'FROM tenants t
                 LEFT JOIN usuarios u ON u.id = t.propietario_usuario_id
                 LEFT JOIN (SELECT tenant_id, MAX(id) id FROM suscripciones GROUP BY tenant_id) ls ON ls.tenant_id = t.id
                 LEFT JOIN suscripciones s ON s.id = ls.id
                 LEFT JOIN planes pl ON pl.id = s.plan_id
                WHERE ' . implode(' AND ', $where);
        $total = (int) ($this->fetchOne("SELECT COUNT(*) n {$from}", $p)['n'] ?? 0);
        $d30 = Clock::nowUtc()->modify('-30 days')->format('Y-m-d H:i:s');
        $rows = $this->fetchAll(
            "SELECT t.id, t.slug, t.nombre_comercial, t.estado, t.es_demo, t.publicado_marketplace, t.created_at, t.aprobado_en, t.suspendido_en,
                    t.motivo_suspension, u.nombres prop_nombres, u.apellidos prop_apellidos, u.email prop_email,
                    s.id sus_id, s.estado sus_estado, s.periodo_fin_en, pl.codigo plan_codigo, pl.nombre plan_nombre,
                    (SELECT COUNT(*) FROM sucursales x WHERE x.tenant_id = t.id AND x.activo = 1 AND x.deleted_at IS NULL) sucursales,
                    (SELECT COUNT(*) FROM barberos x WHERE x.tenant_id = t.id AND x.activo = 1 AND x.deleted_at IS NULL) barberos,
                    (SELECT COUNT(*) FROM reservas x WHERE x.tenant_id = t.id AND x.created_at >= :d30) reservas_30_dias
             {$from}
             ORDER BY (t.estado = 'pendiente') DESC, t.created_at DESC, t.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $p + ['d30' => $d30]
        );
        $resumen = array_fill_keys(self::ESTADOS, 0);
        foreach ($this->fetchAll('SELECT estado, COUNT(*) n FROM tenants WHERE deleted_at IS NULL GROUP BY estado') as $r) {
            $resumen[(string) $r['estado']] = (int) $r['n'];
        }
        return ['items' => array_map([self::class, 'row'], $rows), 'total' => $total, 'resumen' => $resumen];
    }

    /** @return array<string, mixed> */
    public function show(int $id): array
    {
        $t = $this->fetchOne(
            'SELECT t.id, t.slug, t.nombre_comercial, t.razon_social, t.ruc, t.email, t.telefono, t.zona_horaria, t.estado, t.es_demo,
                    t.publicado_marketplace, t.created_at, t.aprobado_en, t.suspendido_en, t.motivo_suspension, t.nota_interna,
                    t.propietario_usuario_id, u.nombres prop_nombres, u.apellidos prop_apellidos, u.email prop_email,
                    u.email_verificado_en prop_verificado, u.estado prop_estado
               FROM tenants t LEFT JOIN usuarios u ON u.id = t.propietario_usuario_id
              WHERE t.id = :id AND t.deleted_at IS NULL',
            ['id' => $id]
        );
        if ($t === null) {
            throw new NotFoundException('Barbería no encontrada.');
        }
        $d30 = Clock::nowUtc()->modify('-30 days')->format('Y-m-d H:i:s');
        $uso = $this->fetchOne(
            "SELECT (SELECT COUNT(*) FROM sucursales WHERE tenant_id = :t1 AND activo = 1 AND deleted_at IS NULL) sucursales,
                    (SELECT COUNT(*) FROM barberos WHERE tenant_id = :t2 AND activo = 1 AND deleted_at IS NULL) barberos,
                    (SELECT COUNT(*) FROM servicios WHERE tenant_id = :t3 AND activo = 1 AND deleted_at IS NULL) servicios,
                    (SELECT COUNT(*) FROM reservas WHERE tenant_id = :t4 AND created_at >= :d30) reservas_30_dias,
                    (SELECT COUNT(*) FROM reservas WHERE tenant_id = :t5) reservas_total,
                    (SELECT COUNT(*) FROM cliente_tenants WHERE tenant_id = :t6) clientes",
            ['t1' => $id, 't2' => $id, 't3' => $id, 't4' => $id, 't5' => $id, 't6' => $id, 'd30' => $d30]
        ) ?? [];
        $equipo = $this->fetchAll(
            "SELECT r.codigo rol, COUNT(*) n FROM membresias m JOIN roles r ON r.id = m.rol_id
              WHERE m.tenant_id = :t AND m.estado = 'activo' GROUP BY r.codigo",
            ['t' => $id]
        );
        $subs = (new SubscriptionService())->forTenant($id);
        $pagos = (new SubscriptionService())->payments(['tenant_id' => $id], 20, 0)['items'];
        $historial = $this->fetchAll(
            "SELECT a.accion, a.created_at, a.datos_nuevos, u.email
               FROM auditoria a LEFT JOIN usuarios u ON u.id = a.usuario_id
              WHERE a.entidad = 'tenants' AND a.entidad_id = :id AND a.accion LIKE 'plataforma.barberia.%'
              ORDER BY a.id DESC LIMIT 20",
            ['id' => $id]
        );

        return self::row($t) + [
            'razon_social' => $t['razon_social'], 'ruc' => $t['ruc'], 'email' => $t['email'], 'telefono' => $t['telefono'],
            'zona_horaria' => $t['zona_horaria'], 'nota_interna' => $t['nota_interna'],
            'propietario' => $t['propietario_usuario_id'] === null ? null : [
                'id' => (int) $t['propietario_usuario_id'], 'nombre' => trim($t['prop_nombres'] . ' ' . ($t['prop_apellidos'] ?? '')),
                'email' => $t['prop_email'], 'email_verificado' => $t['prop_verificado'] !== null, 'estado' => $t['prop_estado'],
            ],
            'uso' => array_map('intval', $uso),
            'equipo' => array_combine(array_column($equipo, 'rol'), array_map('intval', array_column($equipo, 'n'))) ?: [],
            'suscripciones' => $subs,
            'pagos' => $pagos,
            'historial' => array_map(static fn (array $h): array => [
                'accion' => (string) $h['accion'], 'fecha' => self::iso((string) $h['created_at']), 'por' => $h['email'],
                'datos' => json_decode((string) ($h['datos_nuevos'] ?? 'null'), true),
            ], $historial),
        ];
    }

    /** @return array<string, mixed> */
    public function approve(int $id, Request $request): array
    {
        return $this->transition($id, ['pendiente'], 'activo', 'aprobar', null, $request);
    }

    /** @return array<string, mixed> */
    public function reject(int $id, string $motivo, Request $request): array
    {
        return $this->transition($id, ['pendiente'], 'cancelado', 'rechazar', $motivo, $request);
    }

    /** @return array<string, mixed> */
    public function suspend(int $id, string $motivo, Request $request): array
    {
        return $this->transition($id, ['activo'], 'suspendido', 'suspender', $motivo, $request);
    }

    /** @return array<string, mixed> */
    public function reactivate(int $id, Request $request): array
    {
        return $this->transition($id, ['suspendido'], 'activo', 'reactivar', null, $request);
    }

    /**
     * Datos internos del equipo de BARBERAPP: marca de demostración y nota.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data, Request $request): array
    {
        $t = $this->lockTenant($id);
        $changes = [];
        if (array_key_exists('es_demo', $data) && $data['es_demo'] !== null && (int) (bool) $data['es_demo'] !== (int) $t['es_demo']) {
            $changes['es_demo'] = (int) (bool) $data['es_demo'];
        }
        if (array_key_exists('nota_interna', $data) && ($data['nota_interna'] ?? null) !== $t['nota_interna']) {
            $changes['nota_interna'] = $data['nota_interna'] === '' ? null : $data['nota_interna'];
        }
        if ($changes !== []) {
            $sets = implode(', ', array_map(static fn (string $k): string => "{$k} = :{$k}", array_keys($changes)));
            $this->execute("UPDATE tenants SET {$sets} WHERE id = :id", $changes + ['id' => $id]);
            Audit::record('plataforma.barberia.actualizada', 'exito', $request, null, $id, 'tenants', $id, $changes, array_intersect_key($t, $changes));
        }
        return $this->show($id);
    }

    /**
     * @param list<string> $from
     * @return array<string, mixed>
     */
    private function transition(int $id, array $from, string $to, string $accion, ?string $motivo, Request $request): array
    {
        $result = Database::transaction(function () use ($id, $from, $to, $accion, $motivo, $request): array {
            $t = $this->lockTenant($id);
            if (!in_array($t['estado'], $from, true)) {
                throw new HttpException(409, 'invalid_tenant_state', self::stateMessage($accion, (string) $t['estado']));
            }
            $now = Clock::nowSql();
            $prueba = null;
            switch ($accion) {
                case 'aprobar':
                    $this->execute("UPDATE tenants SET estado = 'activo', aprobado_en = :n, motivo_suspension = NULL WHERE id = :id", ['n' => $now, 'id' => $id]);
                    $codigo = 'barberia_aprobada';
                    // Fase 10: la prueba gratuita empieza al aprobarse (no mientras espera revisión).
                    $prueba = (new SubscriptionService())->startTrial($id);
                    break;
                case 'rechazar':
                    $this->execute("UPDATE tenants SET estado = 'cancelado', publicado_marketplace = 0, motivo_suspension = :m WHERE id = :id", ['m' => $motivo, 'id' => $id]);
                    $codigo = 'barberia_rechazada';
                    break;
                case 'suspender':
                    $this->execute("UPDATE tenants SET estado = 'suspendido', suspendido_en = :n, motivo_suspension = :m WHERE id = :id", ['n' => $now, 'm' => $motivo, 'id' => $id]);
                    $codigo = 'barberia_suspendida';
                    break;
                default: // reactivar
                    $this->execute("UPDATE tenants SET estado = 'activo', suspendido_en = NULL, motivo_suspension = NULL, aprobado_en = COALESCE(aprobado_en, :n) WHERE id = :id", ['n' => $now, 'id' => $id]);
                    $codigo = 'barberia_reactivada';
            }
            $futuras = (int) ($this->fetchOne(
                "SELECT COUNT(*) n FROM reservas WHERE tenant_id = :t AND estado IN ('pendiente','confirmada') AND inicio_utc >= :n",
                ['t' => $id, 'n' => $now]
            )['n'] ?? 0);
            $avisos = PlatformNotifier::tenantStatus($t, $codigo, $motivo, 'plataforma:' . $codigo . ':' . $id . ':' . Clock::nowUtc()->getTimestamp());
            Audit::record('plataforma.barberia.' . ['aprobar' => 'aprobada', 'rechazar' => 'rechazada', 'suspender' => 'suspendida', 'reactivar' => 'reactivada'][$accion],
                'exito', $request, null, $id, 'tenants', $id,
                array_filter(['estado' => $to, 'motivo' => $motivo, 'reservas_futuras' => $futuras], static fn ($v) => $v !== null),
                ['estado' => $t['estado']]);
            return ['reservas_futuras' => $futuras, 'avisos' => $avisos, 'prueba' => $prueba ?? null];
        });
        return ['barberia' => $this->show($id), 'reservas_futuras' => $result['reservas_futuras'], 'avisos_enviados' => $result['avisos'], 'prueba_suscripcion_id' => $result['prueba']];
    }

    /** @return array<string, mixed> */
    private function lockTenant(int $id): array
    {
        $t = $this->fetchOne('SELECT id, slug, nombre_comercial, estado, es_demo, nota_interna, propietario_usuario_id FROM tenants WHERE id = :id AND deleted_at IS NULL FOR UPDATE', ['id' => $id]);
        if ($t === null) {
            throw new NotFoundException('Barbería no encontrada.');
        }
        return $t;
    }

    private static function stateMessage(string $accion, string $estado): string
    {
        $labels = ['pendiente' => 'pendiente de aprobación', 'activo' => 'activa', 'suspendido' => 'suspendida', 'cancelado' => 'cancelada'];
        $que = ['aprobar' => 'aprobar', 'rechazar' => 'rechazar', 'suspender' => 'suspender', 'reactivar' => 'reactivar'][$accion];
        return "No se puede {$que} una barbería " . ($labels[$estado] ?? $estado) . '.';
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public static function row(array $r): array
    {
        $out = [
            'id' => (int) $r['id'],
            'slug' => (string) $r['slug'],
            'nombre' => (string) $r['nombre_comercial'],
            'estado' => (string) $r['estado'],
            'es_demo' => (bool) $r['es_demo'],
            'publicado_marketplace' => (bool) $r['publicado_marketplace'],
            'creado_en' => self::iso((string) $r['created_at']),
            'aprobado_en' => $r['aprobado_en'] ? self::iso((string) $r['aprobado_en']) : null,
            'suspendido_en' => $r['suspendido_en'] ? self::iso((string) $r['suspendido_en']) : null,
            'motivo' => $r['motivo_suspension'],
        ];
        if (array_key_exists('sus_estado', $r)) {
            $out['propietario'] = $r['prop_email'] === null ? null : ['nombre' => trim($r['prop_nombres'] . ' ' . ($r['prop_apellidos'] ?? '')), 'email' => $r['prop_email']];
            $out['suscripcion'] = $r['sus_id'] === null ? null : [
                'id' => (int) $r['sus_id'], 'estado' => SubscriptionService::effectiveState((string) $r['sus_estado'], (string) $r['periodo_fin_en']),
                'plan' => ['codigo' => $r['plan_codigo'], 'nombre' => $r['plan_nombre']], 'periodo_fin_en' => self::iso((string) $r['periodo_fin_en']),
            ];
            $out['uso'] = ['sucursales' => (int) $r['sucursales'], 'barberos' => (int) $r['barberos'], 'reservas_30_dias' => (int) $r['reservas_30_dias']];
        }
        return $out;
    }

    private static function iso(string $sql): string
    {
        return str_replace(' ', 'T', $sql) . 'Z';
    }
}
