<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Consultas públicas del marketplace. Solo expone datos comerciales que la barbería publica:
 * nunca clientes, reservas, notas internas, correos de usuarios ni datos de facturación.
 *
 * Reglas de visibilidad:
 *  - Listado y búsqueda: barbería activa, publicada en el marketplace y con al menos una sucursal activa.
 *  - Perfil por enlace directo (/b/{slug}): basta con que la barbería esté activa, aunque no
 *    esté listada en el marketplace (así funciona el QR del local).
 */
final class MarketplaceRepository extends BaseRepository
{
    public const ORDENES = ['relevancia', 'nombre', 'precio', 'distancia', 'recientes'];

    private const VISIBLE = "t.estado = 'activo' AND t.publicado_marketplace = 1 AND t.deleted_at IS NULL";
    private const SUCURSAL_ACTIVA = 'su.tenant_id = t.id AND su.activo = 1 AND su.deleted_at IS NULL';
    private const SERVICIO_ACTIVO = 's.tenant_id = t.id AND s.activo = 1 AND s.deleted_at IS NULL';

    /** @var array<string, mixed> */
    private array $params = [];

    /**
     * @param array{q?:?string, distrito?:?string, tipo?:?string, precio_min?:?string, precio_max?:?string,
     *              lat?:?float, lng?:?float, radio_km?:?float, orden?:?string} $f
     * @return array{items:list<array<string, mixed>>, total:int}
     */
    public function search(array $f, int $limit, int $offset): array
    {
        $this->params = [];
        $where = [self::VISIBLE];

        // Sucursal activa (opcionalmente en el distrito o dentro del radio indicado).
        $suc = [self::SUCURSAL_ACTIVA];
        if (!empty($f['distrito'])) {
            $suc[] = 'su.distrito = ' . $this->bind($f['distrito']);
        }
        $hasGeo = isset($f['lat'], $f['lng']);
        if ($hasGeo && !empty($f['radio_km'])) {
            $suc[] = 'su.latitud IS NOT NULL AND ST_Distance_Sphere(POINT(su.longitud, su.latitud), POINT('
                . $this->bind($f['lng']) . ', ' . $this->bind($f['lat']) . ')) <= ' . $this->bind((float) $f['radio_km'] * 1000);
        }
        $where[] = 'EXISTS (SELECT 1 FROM sucursales su WHERE ' . implode(' AND ', $suc) . ')';

        // Servicios: tipo y rango de precio deben cumplirse en el MISMO servicio.
        $srv = [self::SERVICIO_ACTIVO];
        if (!empty($f['tipo'])) {
            $srv[] = 's.tipo_servicio_id = (SELECT ts.id FROM tipos_servicio ts WHERE ts.codigo = ' . $this->bind($f['tipo']) . ' AND ts.activo = 1)';
        }
        if (isset($f['precio_min']) && $f['precio_min'] !== null) {
            $srv[] = 's.precio >= ' . $this->bind($f['precio_min']);
        }
        if (isset($f['precio_max']) && $f['precio_max'] !== null) {
            $srv[] = 's.precio <= ' . $this->bind($f['precio_max']);
        }
        $srvSql = implode(' AND ', $srv);
        $where[] = "EXISTS (SELECT 1 FROM servicios s WHERE {$srvSql})";

        $q = isset($f['q']) ? trim((string) $f['q']) : '';
        if ($q !== '') {
            $like = '%' . $this->escapeLike($q) . '%';
            $where[] = '(t.nombre_comercial LIKE ' . $this->bind($like)
                . ' OR t.descripcion LIKE ' . $this->bind($like)
                . ' OR EXISTS (SELECT 1 FROM sucursales su WHERE ' . self::SUCURSAL_ACTIVA . ' AND su.distrito LIKE ' . $this->bind($like) . ')'
                . ' OR EXISTS (SELECT 1 FROM servicios s WHERE ' . self::SERVICIO_ACTIVO . ' AND s.nombre LIKE ' . $this->bind($like) . '))';
        }
        $whereSql = implode("\n   AND ", $where);
        $filterParams = $this->params;

        $total = (int) ($this->fetchOne("SELECT COUNT(*) AS n FROM tenants t WHERE {$whereSql}", $filterParams)['n'] ?? 0);
        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $distanceSql = 'NULL';
        if ($hasGeo) {
            $distanceSql = '(SELECT MIN(ST_Distance_Sphere(POINT(su.longitud, su.latitud), POINT('
                . $this->bind($f['lng']) . ', ' . $this->bind($f['lat']) . '))) FROM sucursales su WHERE '
                . self::SUCURSAL_ACTIVA . ' AND su.latitud IS NOT NULL)';
        }

        $orden = $f['orden'] ?? null;
        if (!in_array($orden, self::ORDENES, true)) {
            $orden = $q !== '' ? 'relevancia' : ($hasGeo ? 'distancia' : 'nombre');
        }
        if ($orden === 'distancia' && !$hasGeo) {
            $orden = 'nombre';
        }
        switch ($orden) {
            case 'relevancia':
                $orderSql = $q !== ''
                    ? 'CASE WHEN t.nombre_comercial LIKE ' . $this->bind($this->escapeLike($q) . '%') . ' THEN 0 WHEN t.nombre_comercial LIKE '
                        . $this->bind('%' . $this->escapeLike($q) . '%') . ' THEN 1 ELSE 2 END, t.nombre_comercial'
                    : 't.nombre_comercial';
                break;
            case 'precio':
                $orderSql = 'precio_desde IS NULL, precio_desde, t.nombre_comercial';
                break;
            case 'distancia':
                $orderSql = 'distancia_m IS NULL, distancia_m, t.nombre_comercial';
                break;
            case 'recientes':
                $orderSql = 'COALESCE(t.aprobado_en, t.created_at) DESC, t.id DESC';
                break;
            default:
                $orderSql = 't.nombre_comercial';
        }

        $sql = "SELECT t.id, t.slug, t.nombre_comercial, t.descripcion, t.logo_url, t.portada_url, t.color_primario,
                       (SELECT MIN(s.precio) FROM servicios s WHERE {$srvSql}) AS precio_desde,
                       (SELECT s.moneda FROM servicios s WHERE " . self::SERVICIO_ACTIVO . " ORDER BY s.precio LIMIT 1) AS moneda,
                       {$distanceSql} AS distancia_m
                  FROM tenants t
                 WHERE {$whereSql}
                 ORDER BY {$orderSql}
                 LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset;
        // El SELECT repite las condiciones de servicio: se vuelven a enlazar con nombres nuevos.
        $sql = $this->rebindRepeated($sql, $srvSql, $filterParams);
        $rows = $this->fetchAll($sql, $this->params);

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * Sucursales activas y su horario comercial para un grupo de barberías.
     *
     * @param list<int> $tenantIds
     * @return array<int, list<array<string, mixed>>> tenant_id => sucursales (con 'horarios')
     */
    public function branchesFor(array $tenantIds, bool $full = false): array
    {
        if ($tenantIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $tenantIds));
        $cols = $full
            ? 'su.id, su.tenant_id, su.nombre, su.slug, su.direccion, su.referencia, su.distrito, su.provincia, su.departamento,
               su.latitud, su.longitud, su.telefono, su.email, su.zona_horaria, su.es_principal'
            : 'su.id, su.tenant_id, su.nombre, su.distrito, su.provincia, su.latitud, su.longitud, su.zona_horaria, su.es_principal';
        $rows = $this->fetchAll(
            "SELECT {$cols} FROM sucursales su
              WHERE su.tenant_id IN ({$in}) AND su.activo = 1 AND su.deleted_at IS NULL
              ORDER BY su.tenant_id, su.es_principal DESC, su.nombre"
        );
        $hours = $this->fetchAll(
            "SELECT h.sucursal_id, h.dia_semana, TIME_FORMAT(h.hora_apertura, '%H:%i') AS hora_apertura, TIME_FORMAT(h.hora_cierre, '%H:%i') AS hora_cierre
               FROM horarios_sucursales h WHERE h.tenant_id IN ({$in})
              ORDER BY h.sucursal_id, h.dia_semana, h.hora_apertura"
        );
        $byBranch = [];
        foreach ($hours as $h) {
            $byBranch[(int) $h['sucursal_id']][] = ['dia_semana' => (int) $h['dia_semana'], 'hora_apertura' => $h['hora_apertura'], 'hora_cierre' => $h['hora_cierre']];
        }
        $result = [];
        foreach ($rows as $r) {
            $r['horarios'] = $byBranch[(int) $r['id']] ?? [];
            $result[(int) $r['tenant_id']][] = $r;
        }
        return $result;
    }

    /**
     * Tipos de servicio que ofrece cada barbería (para mostrar en las tarjetas).
     *
     * @param list<int> $tenantIds
     * @return array<int, list<string>>
     */
    public function serviceTypesFor(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $tenantIds));
        $rows = $this->fetchAll(
            "SELECT DISTINCT s.tenant_id, ts.codigo, ts.nombre, ts.orden
               FROM servicios s JOIN tipos_servicio ts ON ts.id = s.tipo_servicio_id
              WHERE s.tenant_id IN ({$in}) AND s.activo = 1 AND s.deleted_at IS NULL AND ts.activo = 1
              ORDER BY s.tenant_id, ts.orden"
        );
        $result = [];
        foreach ($rows as $r) {
            $result[(int) $r['tenant_id']][] = ['codigo' => $r['codigo'], 'nombre' => $r['nombre']];
        }
        return $result;
    }

    /** @return array<string, mixed> Opciones reales para los filtros (solo de barberías visibles). */
    public function filters(): array
    {
        $distritos = $this->fetchAll(
            'SELECT su.distrito, su.provincia, su.departamento, COUNT(DISTINCT t.id) AS barberias
               FROM sucursales su JOIN tenants t ON t.id = su.tenant_id
              WHERE ' . self::VISIBLE . ' AND su.activo = 1 AND su.deleted_at IS NULL
              GROUP BY su.departamento, su.provincia, su.distrito
              ORDER BY barberias DESC, su.distrito'
        );
        $tipos = $this->fetchAll(
            'SELECT ts.codigo, ts.nombre, COUNT(DISTINCT t.id) AS barberias
               FROM tipos_servicio ts
               JOIN servicios s ON s.tipo_servicio_id = ts.id AND s.activo = 1 AND s.deleted_at IS NULL
               JOIN tenants t ON t.id = s.tenant_id
              WHERE ts.activo = 1 AND ' . self::VISIBLE . '
                AND EXISTS (SELECT 1 FROM sucursales su WHERE ' . self::SUCURSAL_ACTIVA . ')
              GROUP BY ts.id, ts.codigo, ts.nombre, ts.orden
              ORDER BY ts.orden'
        );
        $precios = $this->fetchOne(
            'SELECT MIN(s.precio) AS minimo, MAX(s.precio) AS maximo
               FROM servicios s JOIN tenants t ON t.id = s.tenant_id
              WHERE s.activo = 1 AND s.deleted_at IS NULL AND ' . self::VISIBLE
        );
        return [
            'distritos' => array_map(static fn (array $d): array => [
                'distrito' => $d['distrito'], 'provincia' => $d['provincia'], 'departamento' => $d['departamento'], 'barberias' => (int) $d['barberias'],
            ], $distritos),
            'tipos_servicio' => array_map(static fn (array $t): array => [
                'codigo' => $t['codigo'], 'nombre' => $t['nombre'], 'barberias' => (int) $t['barberias'],
            ], $tipos),
            'precios' => [
                'minimo' => $precios['minimo'] ?? null,
                'maximo' => $precios['maximo'] ?? null,
                'moneda' => 'PEN',
            ],
            'ordenes' => self::ORDENES,
        ];
    }

    /** @return array<string, mixed>|null Barbería activa por slug (listada o no en el marketplace). */
    public function findActiveBySlug(string $slug): ?array
    {
        return $this->fetchOne(
            "SELECT t.id, t.slug, t.nombre_comercial, t.descripcion, t.logo_url, t.portada_url, t.color_primario, t.color_secundario,
                    t.telefono, t.whatsapp, t.instagram, t.facebook, t.tiktok, t.sitio_web, t.zona_horaria, t.publicado_marketplace
               FROM tenants t
              WHERE t.slug = :slug AND t.estado = 'activo' AND t.deleted_at IS NULL",
            ['slug' => $slug]
        );
    }

    /** @return list<array<string, mixed>> Servicios activos con categoría y tipo. */
    public function services(int $tenantId): array
    {
        return $this->fetchAll(
            'SELECT s.id, s.nombre, s.descripcion, s.duracion_minutos, s.precio, s.moneda, s.imagen_url, s.orden,
                    c.id AS categoria_id, c.nombre AS categoria, c.orden AS categoria_orden,
                    ts.codigo AS tipo, ts.nombre AS tipo_nombre
               FROM servicios s
               LEFT JOIN categorias_servicio c ON c.tenant_id = s.tenant_id AND c.id = s.categoria_id AND c.activo = 1
               LEFT JOIN tipos_servicio ts ON ts.id = s.tipo_servicio_id
              WHERE s.tenant_id = :t AND s.activo = 1 AND s.deleted_at IS NULL
              ORDER BY c.orden IS NULL, c.orden, s.orden, s.nombre',
            ['t' => $tenantId]
        );
    }

    /** @return list<array<string, mixed>> Barberos activos de sucursales activas. */
    public function barbers(int $tenantId): array
    {
        return $this->fetchAll(
            'SELECT b.id, b.nombre_publico, b.biografia, b.foto_url, b.sucursal_id, b.acepta_reservas_online
               FROM barberos b
               JOIN sucursales su ON su.tenant_id = b.tenant_id AND su.id = b.sucursal_id AND su.activo = 1 AND su.deleted_at IS NULL
              WHERE b.tenant_id = :t AND b.activo = 1 AND b.deleted_at IS NULL
              ORDER BY b.orden, b.nombre_publico',
            ['t' => $tenantId]
        );
    }

    /**
     * Servicios que realiza cada barbero, con precio y duración efectivos (override del barbero).
     *
     * @return list<array<string, mixed>>
     */
    public function barberServices(int $tenantId): array
    {
        return $this->fetchAll(
            'SELECT bs.barbero_id, bs.servicio_id,
                    COALESCE(bs.duracion_minutos_override, s.duracion_minutos) AS duracion_minutos,
                    COALESCE(bs.precio_override, s.precio) AS precio
               FROM barbero_servicios bs
               JOIN servicios s ON s.tenant_id = bs.tenant_id AND s.id = bs.servicio_id AND s.activo = 1 AND s.deleted_at IS NULL
               JOIN barberos b ON b.tenant_id = bs.tenant_id AND b.id = bs.barbero_id AND b.activo = 1 AND b.deleted_at IS NULL
              WHERE bs.tenant_id = :t',
            ['t' => $tenantId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function gallery(int $tenantId): array
    {
        return $this->fetchAll(
            "SELECT id, sucursal_id, ruta, ancho, alto, texto_alternativo FROM tenant_fotos
              WHERE tenant_id = :t AND tipo = 'galeria' ORDER BY orden, id",
            ['t' => $tenantId]
        );
    }

    /** @return array<string, mixed>|null Política de reserva por defecto (pública). */
    public function policy(int $tenantId): ?array
    {
        return $this->fetchOne(
            'SELECT cancelacion_limite_minutos, reprogramacion_limite_minutos, anticipacion_minima_minutos, horizonte_maximo_dias,
                    permite_cualquier_barbero, texto_politica_cancelacion
               FROM politicas_reserva WHERE tenant_id = :t AND sucursal_id IS NULL',
            ['t' => $tenantId]
        );
    }

    // ------------------------------------------------------------------ helpers

    /** @param mixed $value */
    private function bind($value): string
    {
        $name = 'p' . count($this->params);
        $this->params[$name] = $value;
        return ':' . $name;
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    /**
     * Con ATTR_EMULATE_PREPARES = false, PDO no permite repetir un mismo parámetro con nombre.
     * Duplica los parámetros del fragmento $fragment que aparecen por segunda vez en $sql.
     *
     * @param array<string, mixed> $filterParams
     */
    private function rebindRepeated(string $sql, string $fragment, array $filterParams): string
    {
        $pos = strpos($sql, $fragment);
        if ($pos === false) {
            return $sql;
        }
        $copy = preg_replace_callback('/:(p\d+)\b/', function (array $m) use ($filterParams): string {
            return $this->bind($filterParams[$m[1]]);
        }, $fragment);
        return substr($sql, 0, $pos) . $copy . substr($sql, $pos + strlen($fragment));
    }
}
