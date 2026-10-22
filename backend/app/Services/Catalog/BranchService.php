<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Helpers\Clock;
use App\Helpers\Slug;
use App\Helpers\Validator;
use App\Repositories\BranchRepository;
use App\Services\Audit;
use App\Services\Billing\Entitlements;
use App\Services\Schedule\Intervals;
use App\Services\Schedule\WeeklySchedule;
use DateTimeZone;

/** Sucursales y horario comercial (permiso sucursales.gestionar). */
final class BranchService
{
    private BranchRepository $repo;

    public function __construct(private int $tenantId, private string $tenantTimezone = 'America/Lima')
    {
        $this->repo = new BranchRepository($tenantId);
    }

    public function repo(): BranchRepository
    {
        return $this->repo;
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        $b = $this->repo->find($id);
        if ($b === null) {
            throw new NotFoundException('Sucursal no encontrada.');
        }
        return $b;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function create(array $input, Request $request): array
    {
        $data = $this->validate($input, false);
        $data['provincia'] = $data['provincia'] ?? 'Lima';
        $data['departamento'] = $data['departamento'] ?? 'Lima';
        $data['zona_horaria'] = $data['zona_horaria'] ?? $this->tenantTimezone;
        $data['slug'] = $this->uniqueSlug((string) $data['nombre']);
        $principal = !empty($data['es_principal']) || $this->repo->countActive() === 0;
        $data['es_principal'] = false;

        $id = Database::transaction(function () use ($data, $principal): int {
            if (($data['activo'] ?? true) !== false) {
                Entitlements::assertCanAdd($this->tenantId, 'sucursales'); // límite del plan (Fase 10)
            }
            $id = $this->repo->create($data);
            if ($principal) {
                $this->repo->setPrincipal($id);
            }
            return $id;
        });
        Audit::record('sucursal.creada', 'exito', $request, null, $this->tenantId, 'sucursales', $id, $data);
        return $this->get($id);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function update(int $id, array $input, Request $request): array
    {
        $before = $this->get($id);
        $data = $this->validate($input + ['_lat_lng_actual' => [$before['latitud'], $before['longitud']]], true);

        if (array_key_exists('activo', $data) && $data['activo'] === false && (bool) $before['activo']) {
            $this->assertCanDeactivate($id, 'desactivar');
        }
        if (array_key_exists('es_principal', $data) && $data['es_principal'] === false && (bool) $before['es_principal']) {
            throw new ValidationException(['es_principal' => ['Marca otra sucursal como principal en lugar de quitar esta.']]);
        }
        if (!empty($data['es_principal']) && (array_key_exists('activo', $data) ? $data['activo'] === false : !(bool) $before['activo'])) {
            throw new ValidationException(['es_principal' => ['La sucursal principal debe estar activa.']]);
        }

        Database::transaction(function () use ($id, $data, $before): void {
            if (($data['activo'] ?? null) === true && !(bool) $before['activo']) {
                Entitlements::assertCanAdd($this->tenantId, 'sucursales');
            }
            $principal = !empty($data['es_principal']);
            unset($data['es_principal']);
            $this->repo->update($id, $data);
            if ($principal) {
                $this->repo->setPrincipal($id);
            }
        });
        Audit::record('sucursal.actualizada', 'exito', $request, null, $this->tenantId, 'sucursales', $id, $data, array_intersect_key($before, $data));
        return $this->get($id);
    }

    public function delete(int $id, Request $request): void
    {
        $before = $this->get($id);
        $this->assertCanDeactivate($id, 'eliminar');
        if ($this->repo->scheduleCount($id) > 0) {
            throw new HttpException(409, 'branch_in_schedules', 'Hay barberos con jornada en esta sucursal. Quítala de sus horarios antes de eliminarla.');
        }
        Database::transaction(function () use ($id, $before): void {
            $this->repo->softDelete($id);
            if ((bool) $before['es_principal']) {
                // Otra sucursal activa pasa a ser la principal.
                foreach ($this->repo->all() as $s) {
                    if ((bool) $s['activo']) {
                        $this->repo->setPrincipal((int) $s['id']);
                        break;
                    }
                }
            }
        });
        Audit::record('sucursal.eliminada', 'exito', $request, null, $this->tenantId, 'sucursales', $id, null, ['nombre' => $before['nombre']]);
    }

    /**
     * Reemplaza el horario comercial. Devuelve advertencias si algún barbero queda con jornada
     * fuera del nuevo horario (esa parte de su jornada deja de ofrecerse automáticamente).
     *
     * @param array<string, mixed> $input
     * @return array{sucursal: array<string, mixed>, advertencias: list<array<string, mixed>>}
     */
    public function replaceHours(int $id, array $input, Request $request): array
    {
        $before = $this->get($id);
        $tramos = $this->validateHours($input['horario'] ?? null);
        Database::transaction(fn () => $this->repo->replaceHours($id, $tramos));
        Audit::record('sucursal.horario_actualizado', 'exito', $request, null, $this->tenantId, 'sucursales', $id, ['horario' => $tramos], ['horario' => $before['horario']]);

        // Jornadas de barberos que quedan fuera del nuevo horario comercial.
        $abierto = [];
        foreach ($tramos as $t) {
            $abierto[$t['dia_semana']][] = [Intervals::minutes($t['hora_apertura']), Intervals::minutes($t['hora_cierre'])];
        }
        $rows = Database::connection()->prepare(
            "SELECT b.id, b.nombre_publico, h.dia_semana, TIME_FORMAT(h.hora_inicio, '%H:%i') AS hora_inicio, TIME_FORMAT(h.hora_fin, '%H:%i') AS hora_fin
               FROM horarios_barberos h JOIN barberos b ON b.tenant_id = h.tenant_id AND b.id = h.barbero_id AND b.deleted_at IS NULL
              WHERE h.tenant_id = ? AND h.sucursal_id = ? AND h.activo = 1 ORDER BY b.nombre_publico, h.dia_semana, h.hora_inicio"
        );
        $rows->execute([$this->tenantId, $id]);
        $warnings = [];
        foreach ($rows->fetchAll() as $r) {
            if (!Intervals::covers($abierto[(int) $r['dia_semana']] ?? [], Intervals::minutes($r['hora_inicio']), Intervals::minutes($r['hora_fin']))) {
                $warnings[] = [
                    'barbero_id' => (int) $r['id'], 'barbero' => $r['nombre_publico'], 'dia_semana' => (int) $r['dia_semana'],
                    'hora_inicio' => $r['hora_inicio'], 'hora_fin' => $r['hora_fin'],
                ];
            }
        }
        return ['sucursal' => $this->get($id), 'advertencias' => $warnings];
    }

    /**
     * @param mixed $raw
     * @return list<array{dia_semana:int, hora_apertura:string, hora_cierre:string}>
     */
    public function validateHours($raw): array
    {
        if (!is_array($raw) || !WeeklySchedule::isList($raw)) {
            throw new ValidationException(['horario' => ['Envía la lista de tramos del horario (puede estar vacía).']]);
        }
        if (count($raw) > 21) {
            throw new ValidationException(['horario' => ['Máximo 3 tramos por día.']]);
        }
        $errors = [];
        $out = [];
        foreach ($raw as $i => $t) {
            $key = "horario.{$i}";
            $dia = is_array($t) ? filter_var($t['dia_semana'] ?? null, FILTER_VALIDATE_INT) : false;
            $a = is_array($t) && is_string($t['hora_apertura'] ?? null) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t['hora_apertura']) ? $t['hora_apertura'] : null;
            $c = is_array($t) && is_string($t['hora_cierre'] ?? null) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t['hora_cierre']) ? $t['hora_cierre'] : null;
            if ($dia === false || $dia < 1 || $dia > 7) {
                $errors[$key][] = 'Día inválido (1 = lunes … 7 = domingo).';
            } elseif ($a === null || $c === null) {
                $errors[$key][] = 'Indica la hora de apertura y cierre (HH:MM).';
            } elseif ($c <= $a) {
                $errors[$key][] = 'La hora de cierre debe ser posterior a la de apertura.';
            } else {
                foreach ($out as $prev) {
                    if ($prev['dia_semana'] === $dia && Intervals::overlaps(Intervals::minutes($prev['hora_apertura']), Intervals::minutes($prev['hora_cierre']), Intervals::minutes($a), Intervals::minutes($c))) {
                        $errors[$key][] = "Se cruza con el tramo {$prev['hora_apertura']}–{$prev['hora_cierre']} del mismo día.";
                        continue 2;
                    }
                }
                $out[] = ['dia_semana' => (int) $dia, 'hora_apertura' => $a, 'hora_cierre' => $c];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        usort($out, static fn (array $x, array $y): int => [$x['dia_semana'], $x['hora_apertura']] <=> [$y['dia_semana'], $y['hora_apertura']]);
        return $out;
    }

    private function assertCanDeactivate(int $id, string $accion): void
    {
        $barberos = $this->repo->barbersCount($id);
        if ($barberos > 0) {
            throw new HttpException(409, 'branch_has_barbers', "No puedes {$accion} la sucursal: tiene {$barberos} barbero(s) activos asignados. Muévelos a otra sucursal o desactívalos primero.");
        }
        $reservas = $this->repo->futureBookingsCount($id, Clock::nowSql());
        if ($reservas > 0) {
            throw new HttpException(409, 'branch_has_bookings', "No puedes {$accion} la sucursal: tiene {$reservas} reserva(s) futuras activas.", ['reservas' => $reservas]);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input, bool $partial): array
    {
        foreach (['telefono'] as $k) {
            if (isset($input[$k]) && is_string($input[$k])) {
                $input[$k] = preg_replace('/[\s\-().]/', '', $input[$k]);
            }
        }
        $req = $partial ? '' : 'required|';
        $data = Validator::validate($input, [
            'nombre' => $req . 'string|min:2|max:120',
            'direccion' => $req . 'string|min:3|max:255',
            'referencia' => 'nullable|string|max:255',
            'distrito' => $req . 'string|min:2|max:80',
            'provincia' => 'nullable|string|min:2|max:80',
            'departamento' => 'nullable|string|min:2|max:80',
            'ubigeo' => 'nullable|regex:/^\d{6}$/',
            'telefono' => 'nullable|regex:/^\+?[0-9]{6,15}$/',
            'email' => 'nullable|email',
            'zona_horaria' => 'nullable|string|max:64',
            'es_principal' => 'nullable|boolean',
            'activo' => 'nullable|boolean',
        ]);
        foreach (['es_principal', 'activo', 'provincia', 'departamento', 'zona_horaria'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] === null) {
                unset($data[$k]);
            }
        }
        if (isset($data['zona_horaria']) && !in_array($data['zona_horaria'], DateTimeZone::listIdentifiers(), true)) {
            throw new ValidationException(['zona_horaria' => ['Zona horaria no reconocida (p. ej. America/Lima).']]);
        }

        // Coordenadas: ambas o ninguna, en rango y con hasta 6 decimales.
        $hasLat = array_key_exists('latitud', $input);
        $hasLng = array_key_exists('longitud', $input);
        if ($hasLat || $hasLng) {
            $lat = $hasLat ? $input['latitud'] : ($input['_lat_lng_actual'][0] ?? null);
            $lng = $hasLng ? $input['longitud'] : ($input['_lat_lng_actual'][1] ?? null);
            $lat = $lat === '' ? null : $lat;
            $lng = $lng === '' ? null : $lng;
            if (($lat === null) !== ($lng === null)) {
                throw new ValidationException([($lat === null ? 'latitud' : 'longitud') => ['Indica latitud y longitud, o deja ambas vacías.']]);
            }
            if ($lat !== null) {
                $errors = [];
                if (!is_numeric($lat) || (float) $lat < -90 || (float) $lat > 90) {
                    $errors['latitud'][] = 'Latitud inválida (entre -90 y 90).';
                }
                if (!is_numeric($lng) || (float) $lng < -180 || (float) $lng > 180) {
                    $errors['longitud'][] = 'Longitud inválida (entre -180 y 180).';
                }
                if ($errors !== []) {
                    throw new ValidationException($errors);
                }
                $data['latitud'] = round((float) $lat, 6);
                $data['longitud'] = round((float) $lng, 6);
            } else {
                $data['latitud'] = null;
                $data['longitud'] = null;
            }
        }
        return $data;
    }

    private function uniqueSlug(string $nombre): string
    {
        $base = Slug::from($nombre, 70);
        if (strlen($base) < 2) {
            $base = 'sede';
        }
        $slug = $base;
        for ($n = 2; $this->repo->slugExists($slug); $n++) {
            $slug = $base . '-' . $n;
        }
        return $slug;
    }
}
