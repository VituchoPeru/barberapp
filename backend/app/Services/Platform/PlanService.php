<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Helpers\Clock;
use App\Helpers\Validator;
use App\Repositories\BaseRepository;
use App\Services\Audit;

/**
 * Planes comerciales del SaaS (Fase 9). Se crean y editan desde el panel de la plataforma; no
 * se eliminan (se desactivan) para no romper suscripciones ni pagos históricos. El precio de un
 * plan aplica a suscripciones nuevas: las existentes conservan su precio pactado.
 * Los límites y funcionalidades se aplican a cada barbería con Billing\Entitlements (Fase 10).
 */
final class PlanService extends BaseRepository
{
    public const CANALES = ['push', 'interna', 'email', 'whatsapp', 'sms'];

    private const RULES = [
        'nombre' => 'string|max:80',
        'descripcion' => 'nullable|string|max:500',
        'precio_mensual' => 'decimal|min:0|max:99999.99',
        'precio_anual' => 'nullable|decimal|min:0|max:999999.99',
        'moneda' => 'string|in:PEN,USD',
        'dias_prueba' => 'integer|min:0|max:90',
        'max_sucursales' => 'nullable|integer|min:1|max:1000',
        'max_barberos' => 'nullable|integer|min:1|max:10000',
        'publico' => 'boolean',
        'activo' => 'boolean',
        'orden' => 'integer|min:0|max:999',
    ];

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $now = Clock::nowSql();
        $rows = $this->fetchAll(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM suscripciones s WHERE s.plan_id = p.id AND s.estado = 'activa' AND s.periodo_fin_en > :n1) activas,
                    (SELECT COUNT(*) FROM suscripciones s WHERE s.plan_id = p.id AND s.estado = 'prueba' AND s.periodo_fin_en > :n2) en_prueba,
                    (SELECT COUNT(*) FROM suscripciones s WHERE s.plan_id = p.id) historicas
               FROM planes p ORDER BY p.orden, p.id",
            ['n1' => $now, 'n2' => $now]
        );
        return array_map([self::class, 'present'], $rows);
    }

    /** @return array<string, mixed> */
    public function show(int $id): array
    {
        foreach ($this->list() as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        throw new NotFoundException('Plan no encontrado.');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input, Request $request): array
    {
        $rules = self::RULES;
        foreach (['nombre', 'precio_mensual'] as $k) {
            $rules[$k] = 'required|' . $rules[$k];
        }
        $d = Validator::validate($input, ['codigo' => 'required|slug|min:3|max:40'] + $rules);
        $d['caracteristicas'] = self::features($input['caracteristicas'] ?? []);
        self::checkPrices($d);
        if ($this->fetchOne('SELECT id FROM planes WHERE codigo = :c', ['c' => $d['codigo']]) !== null) {
            throw new ValidationException(['codigo' => ['Ya existe un plan con ese código.']]);
        }
        $d += ['moneda' => (string) PlatformSettings::all()['plataforma.moneda'], 'dias_prueba' => 14, 'publico' => true, 'activo' => true,
            'orden' => (int) ($this->fetchOne('SELECT COALESCE(MAX(orden), 0) + 1 n FROM planes')['n'] ?? 1)];
        $id = $this->insert(
            'INSERT INTO planes (codigo, nombre, descripcion, precio_mensual, precio_anual, moneda, dias_prueba, max_sucursales, max_barberos, caracteristicas, publico, activo, orden)
             VALUES (:codigo, :nombre, :descripcion, :pm, :pa, :moneda, :dias, :ms, :mb, :car, :pub, :act, :orden)',
            ['codigo' => $d['codigo'], 'nombre' => $d['nombre'], 'descripcion' => $d['descripcion'] ?? null, 'pm' => (string) $d['precio_mensual'],
                'pa' => isset($d['precio_anual']) ? (string) $d['precio_anual'] : null, 'moneda' => $d['moneda'], 'dias' => (int) $d['dias_prueba'],
                'ms' => $d['max_sucursales'] ?? null, 'mb' => $d['max_barberos'] ?? null, 'car' => json_encode($d['caracteristicas'], JSON_UNESCAPED_UNICODE),
                'pub' => (int) (bool) $d['publico'], 'act' => (int) (bool) $d['activo'], 'orden' => (int) $d['orden']]
        );
        Audit::record('plataforma.plan.creado', 'exito', $request, null, null, 'planes', $id, ['codigo' => $d['codigo'], 'precio_mensual' => (string) $d['precio_mensual']]);
        return $this->show($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $id, array $input, Request $request): array
    {
        $before = $this->fetchOne('SELECT * FROM planes WHERE id = :id', ['id' => $id]);
        if ($before === null) {
            throw new NotFoundException('Plan no encontrado.');
        }
        if (array_key_exists('codigo', $input) && $input['codigo'] !== $before['codigo']) {
            throw new ValidationException(['codigo' => ['El código de un plan no se puede cambiar.']]);
        }
        $d = Validator::validate($input, self::RULES);
        if (array_key_exists('caracteristicas', $input)) {
            $d['caracteristicas'] = json_encode(self::features($input['caracteristicas']), JSON_UNESCAPED_UNICODE);
        }
        $merged = $d + $before;
        self::checkPrices($merged);
        if (array_key_exists('activo', $d) && !(bool) $d['activo'] && (bool) $before['activo']) {
            $publicos = (int) ($this->fetchOne('SELECT COUNT(*) n FROM planes WHERE activo = 1 AND id <> :id', ['id' => $id])['n'] ?? 0);
            if ($publicos === 0) {
                throw new HttpException(409, 'last_active_plan', 'Debe quedar al menos un plan activo.');
            }
        }
        $changes = [];
        foreach ($d as $k => $v) {
            $old = $before[$k];
            if ($k === 'caracteristicas' && json_decode((string) $old, true) == json_decode((string) $v, true)) {
                continue;
            }
            $norm = is_bool($v) ? (int) $v : $v;
            if ((string) ($old ?? "\0") !== (string) ($norm ?? "\0")) {
                $changes[$k] = $norm;
            }
        }
        if ($changes !== []) {
            $sets = implode(', ', array_map(static fn (string $k): string => "{$k} = :{$k}", array_keys($changes)));
            $this->execute("UPDATE planes SET {$sets} WHERE id = :id", $changes + ['id' => $id]);
            \App\Services\Billing\Entitlements::flush();
            Audit::record('plataforma.plan.actualizado', 'exito', $request, null, null, 'planes', $id, $changes, array_intersect_key($before, $changes));
        }
        $out = $this->show($id);
        if (isset($changes['precio_mensual']) || isset($changes['precio_anual'])) {
            $out['aviso'] = 'El nuevo precio aplica a suscripciones nuevas. Las vigentes conservan su precio pactado hasta que cambies su plan.';
        }
        return $out;
    }

    /**
     * @param mixed $in
     * @return array{reportes_avanzados:bool, personalizacion:bool, canales:list<string>}
     */
    public static function features($in): array
    {
        if (!is_array($in)) {
            throw new ValidationException(['caracteristicas' => ['Formato inválido.']]);
        }
        $canales = $in['canales'] ?? ['push', 'interna'];
        if (!is_array($canales) || array_diff($canales, self::CANALES) !== []) {
            throw new ValidationException(['caracteristicas.canales' => ['Canales permitidos: ' . implode(', ', self::CANALES) . '.']]);
        }
        $canales = array_values(array_unique(array_merge(['interna'], array_map('strval', $canales))));
        $ordered = array_values(array_intersect(self::CANALES, $canales));
        return [
            'reportes_avanzados' => (bool) ($in['reportes_avanzados'] ?? false),
            'personalizacion' => (bool) ($in['personalizacion'] ?? false),
            'canales' => $ordered,
        ];
    }

    /** @param array<string, mixed> $d */
    private static function checkPrices(array $d): void
    {
        if (isset($d['precio_anual']) && $d['precio_anual'] !== null && (float) $d['precio_anual'] > (float) $d['precio_mensual'] * 12) {
            throw new ValidationException(['precio_anual' => ['El precio anual no puede superar 12 meses del precio mensual.']]);
        }
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public static function present(array $r): array
    {
        $car = json_decode((string) ($r['caracteristicas'] ?? 'null'), true);
        return [
            'id' => (int) $r['id'],
            'codigo' => (string) $r['codigo'],
            'nombre' => (string) $r['nombre'],
            'descripcion' => $r['descripcion'],
            'precio_mensual' => (string) $r['precio_mensual'],
            'precio_anual' => $r['precio_anual'] !== null ? (string) $r['precio_anual'] : null,
            'moneda' => (string) $r['moneda'],
            'dias_prueba' => (int) $r['dias_prueba'],
            'max_sucursales' => $r['max_sucursales'] !== null ? (int) $r['max_sucursales'] : null,
            'max_barberos' => $r['max_barberos'] !== null ? (int) $r['max_barberos'] : null,
            'caracteristicas' => is_array($car) ? self::features($car) : self::features([]),
            'publico' => (bool) $r['publico'],
            'activo' => (bool) $r['activo'],
            'orden' => (int) $r['orden'],
            'suscripciones' => ['activas' => (int) ($r['activas'] ?? 0), 'en_prueba' => (int) ($r['en_prueba'] ?? 0), 'historicas' => (int) ($r['historicas'] ?? 0)],
        ];
    }
}
