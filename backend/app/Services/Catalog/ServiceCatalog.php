<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Helpers\Clock;
use App\Helpers\Validator;
use App\Repositories\ServiceRepository;
use App\Services\Audit;

/**
 * Servicios y categorías (permiso servicios.gestionar).
 *
 * Reglas: duración de 5 a 600 minutos en múltiplos de 5 (nunca cero), precio ≥ 0 con dos
 * decimales, nombre único entre los servicios vigentes. Un servicio inactivo no se muestra en
 * el marketplace ni se puede reservar, pero conserva su configuración para reactivarlo.
 */
final class ServiceCatalog
{
    public const DURACION_MIN = 5;
    public const DURACION_MAX = 600;
    public const PRECIO_MAX = '99999.99';

    private ServiceRepository $repo;

    public function __construct(private int $tenantId)
    {
        $this->repo = new ServiceRepository($tenantId);
    }

    public function repo(): ServiceRepository
    {
        return $this->repo;
    }

    // ------------------------------------------------------------------ servicios

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        $s = $this->repo->find($id);
        if ($s === null) {
            throw new NotFoundException('Servicio no encontrado.');
        }
        return $s;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function create(array $input, Request $request): array
    {
        $data = $this->validate($input, false);
        $data['orden'] = $data['orden'] ?? $this->repo->nextOrder();
        $id = $this->repo->create($data);
        Audit::record('servicio.creado', 'exito', $request, null, $this->tenantId, 'servicios', $id, $data);
        return $this->get($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{servicio: array<string, mixed>, reservas_futuras: int}
     */
    public function update(int $id, array $input, Request $request): array
    {
        $before = $this->get($id);
        $data = $this->validate($input, true, $id);
        $this->repo->update($id, $data);
        Audit::record('servicio.actualizado', 'exito', $request, null, $this->tenantId, 'servicios', $id, $data, array_intersect_key($before, $data));
        // Las reservas ya hechas conservan precio y duración congelados: solo se informa.
        $futuras = (isset($data['activo']) && !$data['activo']) || isset($data['precio']) || isset($data['duracion_minutos'])
            ? $this->repo->futureBookingsCount($id, Clock::nowSql()) : 0;
        return ['servicio' => $this->get($id), 'reservas_futuras' => $futuras];
    }

    public function delete(int $id, Request $request): int
    {
        $before = $this->get($id);
        $futuras = $this->repo->futureBookingsCount($id, Clock::nowSql());
        $this->repo->softDelete($id);
        Audit::record('servicio.eliminado', 'exito', $request, null, $this->tenantId, 'servicios', $id, null, ['nombre' => $before['nombre'], 'precio' => $before['precio']]);
        return $futuras;
    }

    /**
     * Regla común de duración (también para la duración propia de un barbero).
     *
     * @param mixed $value
     */
    public static function durationError($value): ?string
    {
        $v = filter_var($value, FILTER_VALIDATE_INT);
        if ($v === false || is_bool($value)) {
            return 'La duración debe ser un número entero de minutos.';
        }
        if ($v < self::DURACION_MIN || $v > self::DURACION_MAX) {
            return 'La duración debe estar entre ' . self::DURACION_MIN . ' y ' . self::DURACION_MAX . ' minutos.';
        }
        if ($v % 5 !== 0) {
            return 'La duración debe ser múltiplo de 5 minutos.';
        }
        return null;
    }

    /**
     * Regla común de precio. Devuelve el precio normalizado ("25.00") o lanza la validación.
     *
     * @param mixed $value
     */
    public static function priceError($value): ?string
    {
        if (!(is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?\d{1,8}(\.\d{1,2})?$/', trim($value))))) {
            return 'Indica un precio válido (máximo 2 decimales).';
        }
        if ((float) $value < 0) {
            return 'El precio no puede ser negativo.';
        }
        if ((float) $value > (float) self::PRECIO_MAX) {
            return 'El precio máximo es S/ ' . self::PRECIO_MAX . '.';
        }
        if (is_float($value) && round($value, 2) !== $value) {
            return 'Indica un precio válido (máximo 2 decimales).';
        }
        return null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input, bool $partial, ?int $id = null): array
    {
        $req = $partial ? '' : 'required|';
        $errors = [];
        // Duración y precio se validan aparte para dar mensajes claros (cero, negativos, múltiplos).
        foreach (['duracion_minutos' => 'durationError', 'precio' => 'priceError'] as $k => $fn) {
            if (!array_key_exists($k, $input) || $input[$k] === null || $input[$k] === '') {
                if (!$partial) {
                    $errors[$k][] = 'Este campo es obligatorio.';
                }
                continue;
            }
            $msg = self::$fn($input[$k]);
            if ($msg !== null) {
                $errors[$k][] = $msg;
            }
        }
        try {
            $data = Validator::validate($input, [
                'nombre' => $req . 'string|min:2|max:120',
                'descripcion' => 'nullable|string|max:1000',
                'categoria_id' => 'nullable|integer|min:1',
                'tipo' => 'nullable|string|max:40',
                'activo' => 'nullable|boolean',
                'orden' => 'nullable|integer|min:0|max:9999',
            ]);
        } catch (ValidationException $e) {
            $errors = array_merge_recursive($errors, (array) ($e->details()['fields'] ?? []));
            $data = [];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if (array_key_exists('duracion_minutos', $input) && $input['duracion_minutos'] !== null && $input['duracion_minutos'] !== '') {
            $data['duracion_minutos'] = (int) $input['duracion_minutos'];
        }
        if (array_key_exists('precio', $input) && $input['precio'] !== null && $input['precio'] !== '') {
            $data['precio'] = number_format((float) $input['precio'], 2, '.', '');
        }
        foreach (['activo', 'orden'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] === null) {
                unset($data[$k]);
            }
        }
        if (isset($data['nombre']) && $this->repo->nameExists($data['nombre'], $id)) {
            throw new ValidationException(['nombre' => ['Ya tienes un servicio con ese nombre.']]);
        }
        if (array_key_exists('categoria_id', $data) && $data['categoria_id'] !== null && $this->repo->findCategory((int) $data['categoria_id']) === null) {
            throw new ValidationException(['categoria_id' => ['Categoría no encontrada.']]);
        }
        if (array_key_exists('tipo', $data)) {
            $data['tipo_servicio_id'] = $data['tipo'] === null ? null : $this->repo->tipoId((string) $data['tipo']);
            if ($data['tipo'] !== null && $data['tipo_servicio_id'] === null) {
                throw new ValidationException(['tipo' => ['Tipo de servicio no reconocido.']]);
            }
            unset($data['tipo']);
        }
        return $data;
    }

    // ------------------------------------------------------------------ categorías

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function createCategory(array $input, Request $request): array
    {
        $data = Validator::validate($input, ['nombre' => 'required|string|min:2|max:80', 'orden' => 'nullable|integer|min:0|max:9999', 'activo' => 'nullable|boolean']);
        if ($this->repo->categoryNameExists($data['nombre'])) {
            throw new ValidationException(['nombre' => ['Ya existe una categoría con ese nombre.']]);
        }
        $id = $this->repo->createCategory($data['nombre'], $data['orden'] ?? $this->repo->nextCategoryOrder(), $data['activo'] ?? true);
        Audit::record('categoria_servicio.creada', 'exito', $request, null, $this->tenantId, 'categorias_servicio', $id, $data);
        return (array) $this->repo->findCategory($id);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function updateCategory(int $id, array $input, Request $request): array
    {
        $before = $this->repo->findCategory($id);
        if ($before === null) {
            throw new NotFoundException('Categoría no encontrada.');
        }
        $data = Validator::validate($input, ['nombre' => 'nullable|string|min:2|max:80', 'orden' => 'nullable|integer|min:0|max:9999', 'activo' => 'nullable|boolean']);
        $data = array_filter($data, static fn ($v): bool => $v !== null);
        if (isset($data['nombre']) && $this->repo->categoryNameExists($data['nombre'], $id)) {
            throw new ValidationException(['nombre' => ['Ya existe una categoría con ese nombre.']]);
        }
        $this->repo->updateCategory($id, $data);
        Audit::record('categoria_servicio.actualizada', 'exito', $request, null, $this->tenantId, 'categorias_servicio', $id, $data, array_intersect_key($before, $data));
        return (array) $this->repo->findCategory($id);
    }

    public function deleteCategory(int $id, Request $request): void
    {
        $cat = null;
        foreach ($this->repo->categories() as $c) {
            if ((int) $c['id'] === $id) {
                $cat = $c;
            }
        }
        if ($cat === null) {
            throw new NotFoundException('Categoría no encontrada.');
        }
        if ((int) $cat['servicios'] > 0) {
            throw new HttpException(409, 'category_in_use', "La categoría tiene {$cat['servicios']} servicio(s). Muévelos a otra categoría antes de eliminarla.");
        }
        $this->repo->deleteCategory($id);
        Audit::record('categoria_servicio.eliminada', 'exito', $request, null, $this->tenantId, 'categorias_servicio', $id, null, ['nombre' => $cat['nombre']]);
    }
}
