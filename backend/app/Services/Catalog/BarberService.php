<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Helpers\Clock;
use App\Helpers\Validator;
use App\Repositories\AgendaBlockRepository;
use App\Repositories\BarberRepository;
use App\Repositories\BranchRepository;
use App\Repositories\ServiceRepository;
use App\Services\Audit;
use App\Services\Media\ImageStore;
use App\Services\Schedule\WeeklySchedule;
use App\Services\Schedule\WorkingTime;
use DateTimeImmutable;

/**
 * Barberos: ficha, servicios que realiza, jornada semanal, descansos y foto.
 *
 * Toda modificación de la jornada bloquea la fila del barbero (SELECT … FOR UPDATE), el mismo
 * candado que usará el motor de reservas (Fase 5): así un cambio de horario y una reserva
 * nunca se validan a la vez con datos desactualizados.
 */
final class BarberService
{
    /** Días máximos que se pueden consultar de jornada efectiva de una vez. */
    public const MAX_DIAS_JORNADA = 62;

    private BarberRepository $repo;

    public function __construct(private int $tenantId, private string $tenantTimezone = 'America/Lima')
    {
        $this->repo = new BarberRepository($tenantId);
    }

    public function repo(): BarberRepository
    {
        return $this->repo;
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        $b = $this->repo->find($id);
        if ($b === null) {
            throw new NotFoundException('Barbero no encontrado.');
        }
        return $b;
    }

    /** @return array<string, mixed> ficha completa para el panel */
    public function detail(int $id): array
    {
        $b = CatalogPresenter::barber($this->get($id));
        $b['servicios'] = array_map([CatalogPresenter::class, 'barberService'], $this->repo->services($id));
        $b['horario'] = array_map([CatalogPresenter::class, 'shift'], $this->repo->shifts($id));
        $b['descansos'] = array_map([CatalogPresenter::class, 'rest'], $this->repo->breaks($id));
        return $b;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function create(array $input, Request $request): array
    {
        $data = $this->validate($input, false);
        $data['orden'] = $data['orden'] ?? $this->repo->nextOrder();
        $id = $this->repo->create($data);
        Audit::record('barbero.creado', 'exito', $request, null, $this->tenantId, 'barberos', $id, $data);
        return $this->detail($id);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function update(int $id, array $input, Request $request): array
    {
        $data = $this->validate($input, true, $id);
        $before = Database::transaction(function () use ($id, $data): array {
            $this->repo->lock($id);
            $before = $this->get($id);
            // Las reservas ya tomadas deben resolverse antes de desactivar (dejar de recibir
            // reservas online, en cambio, no afecta a las existentes).
            if (array_key_exists('activo', $data) && $data['activo'] === false && (bool) $before['activo']) {
                $this->assertNoFutureBookings($id, 'desactivar');
            }
            $this->repo->update($id, $data);
            return $before;
        });
        Audit::record('barbero.actualizado', 'exito', $request, null, $this->tenantId, 'barberos', $id, $data, array_intersect_key($before, $data));
        return $this->detail($id);
    }

    public function delete(int $id, Request $request): void
    {
        $before = Database::transaction(function () use ($id): array {
            $this->repo->lock($id);
            $before = $this->get($id);
            $this->assertNoFutureBookings($id, 'eliminar');
            $this->repo->softDelete($id);
            return $before;
        });
        if ($before['foto_url'] !== null) {
            (new ImageStore())->delete((string) $before['foto_url']);
        }
        Audit::record('barbero.eliminado', 'exito', $request, null, $this->tenantId, 'barberos', $id, null, ['nombre_publico' => $before['nombre_publico']]);
    }

    /**
     * Reemplaza los servicios que realiza el barbero, con duración/precio propios opcionales.
     *
     * @param array<string, mixed> $input {servicios: [{servicio_id, duracion_minutos?, precio?}]}
     * @return array<string, mixed>
     */
    public function replaceServices(int $id, array $input, Request $request): array
    {
        $raw = $input['servicios'] ?? null;
        if (!is_array($raw) || !WeeklySchedule::isList($raw)) {
            throw new ValidationException(['servicios' => ['Envía la lista de servicios (puede estar vacía).']]);
        }
        if (count($raw) > 200) {
            throw new ValidationException(['servicios' => ['Demasiados servicios.']]);
        }
        $ids = [];
        foreach ($raw as $it) {
            $ids[] = is_array($it) ? (int) filter_var($it['servicio_id'] ?? 0, FILTER_VALIDATE_INT) : 0;
        }
        $known = (new ServiceRepository($this->tenantId))->findMany(array_values(array_filter($ids)));
        $errors = [];
        $items = [];
        $seen = [];
        foreach ($raw as $i => $it) {
            $key = "servicios.{$i}";
            $sid = $ids[$i];
            if ($sid <= 0 || !isset($known[$sid])) {
                $errors[$key][] = 'Servicio no encontrado.';
                continue;
            }
            if (isset($seen[$sid])) {
                $errors[$key][] = 'Servicio repetido.';
                continue;
            }
            $seen[$sid] = true;
            $dur = $it['duracion_minutos'] ?? null;
            $precio = $it['precio'] ?? null;
            if ($dur !== null && $dur !== '' && ($msg = ServiceCatalog::durationError($dur)) !== null) {
                $errors[$key][] = $msg;
                continue;
            }
            if ($precio !== null && $precio !== '' && ($msg = ServiceCatalog::priceError($precio)) !== null) {
                $errors[$key][] = $msg;
                continue;
            }
            $items[] = [
                'servicio_id' => $sid,
                'duracion_minutos' => $dur === null || $dur === '' ? null : (int) $dur,
                'precio' => $precio === null || $precio === '' ? null : number_format((float) $precio, 2, '.', ''),
            ];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $before = Database::transaction(function () use ($id, $items): array {
            if (!$this->repo->lock($id)) {
                throw new NotFoundException('Barbero no encontrado.');
            }
            $before = $this->repo->services($id);
            $this->repo->replaceServices($id, $items);
            return $before;
        });
        Audit::record('barbero.servicios_actualizados', 'exito', $request, null, $this->tenantId, 'barberos', $id, ['servicios' => $items], ['servicios' => array_column($before, 'servicio_id')]);
        return $this->detail($id);
    }

    /**
     * Reemplaza la jornada semanal y los descansos.
     *
     * @param array<string, mixed> $input {tramos: [...], descansos: [...]}
     * @return array{barbero: array<string, mixed>, advertencias: array<string, mixed>}
     */
    public function replaceSchedule(int $id, array $input, Request $request): array
    {
        $branchRepo = new BranchRepository($this->tenantId);
        [$before, $fuera] = Database::transaction(function () use ($id, $input, $branchRepo): array {
            if (!$this->repo->lock($id)) {
                throw new NotFoundException('Barbero no encontrado.');
            }
            $schedule = WeeklySchedule::normalize($input, $branchRepo->scheduleMap());
            $before = ['tramos' => $this->repo->shifts($id), 'descansos' => $this->repo->breaks($id)];
            $this->repo->replaceSchedule($id, $schedule['tramos'], $schedule['descansos']);
            // Reservas ya tomadas que quedan fuera de la nueva jornada: se respetan, pero se avisa.
            return [$before, $this->bookingsOutsideSchedule($id, $branchRepo)];
        });
        Audit::record('barbero.horario_actualizado', 'exito', $request, null, $this->tenantId, 'barberos', $id, $input, $before);
        return ['barbero' => $this->detail($id), 'advertencias' => ['reservas_fuera_de_jornada' => $fuera]];
    }

    /**
     * Jornada efectiva (lo que realmente se ofrecerá) entre dos fechas locales.
     *
     * @return array<string, mixed>
     */
    public function effectiveSchedule(int $id, string $desde, string $hasta): array
    {
        $this->get($id);
        $days = (int) (new DateTimeImmutable($desde))->diff(new DateTimeImmutable($hasta))->format('%r%a');
        if ($days < 0) {
            throw new ValidationException(['hasta' => ['La fecha final debe ser igual o posterior a la inicial.']]);
        }
        if ($days >= self::MAX_DIAS_JORNADA) {
            throw new ValidationException(['hasta' => ['Consulta como máximo ' . self::MAX_DIAS_JORNADA . ' días.']]);
        }
        $branches = (new BranchRepository($this->tenantId))->scheduleMap(true);
        // Margen de un día a cada lado para cubrir diferencias de zona horaria.
        $desdeUtc = (new DateTimeImmutable($desde . ' 00:00:00'))->modify('-1 day')->format('Y-m-d H:i:s');
        $hastaUtc = (new DateTimeImmutable($hasta . ' 00:00:00'))->modify('+2 day')->format('Y-m-d H:i:s');
        $blocks = (new AgendaBlockRepository($this->tenantId))->approvedFor($id, $desdeUtc, $hastaUtc);
        $windows = WorkingTime::windows($this->repo->shifts($id), $this->repo->breaks($id), $branches, $blocks, $desde, $hasta);
        $names = array_map(static fn (array $b): string => $b['nombre'], $branches);
        foreach ($windows as &$w) {
            $w['sucursal'] = $names[$w['sucursal_id']] ?? null;
        }
        unset($w);
        return [
            'desde' => $desde,
            'hasta' => $hasta,
            'ventanas' => $windows,
            'minutos_totales' => array_sum(array_column($windows, 'minutos')),
            'bloqueos' => array_map(static fn (array $b): array => [
                'tipo' => $b['tipo'], 'motivo' => $b['motivo'], 'sucursal_id' => $b['sucursal_id'] === null ? null : (int) $b['sucursal_id'],
                'inicio_utc' => $b['inicio_utc'], 'fin_utc' => $b['fin_utc'],
            ], $blocks),
        ];
    }

    /** @return array<string, mixed> */
    public function uploadPhoto(int $id, string $binary, Request $request): array
    {
        $before = $this->get($id);
        $store = new ImageStore();
        $img = $store->store($this->tenantId, $binary, 'barbero');
        try {
            $this->repo->setPhoto($id, $img['ruta']);
        } catch (\Throwable $e) {
            $store->delete($img['ruta']);
            throw $e;
        }
        if ($before['foto_url'] !== null) {
            $store->delete((string) $before['foto_url']);
        }
        Audit::record('barbero.foto_subida', 'exito', $request, null, $this->tenantId, 'barberos', $id, ['bytes' => $img['bytes']]);
        return $this->detail($id);
    }

    /** @return array<string, mixed> */
    public function deletePhoto(int $id, Request $request): array
    {
        $before = $this->get($id);
        if ($before['foto_url'] !== null) {
            $this->repo->setPhoto($id, null);
            (new ImageStore())->delete((string) $before['foto_url']);
            Audit::record('barbero.foto_eliminada', 'exito', $request, null, $this->tenantId, 'barberos', $id);
        }
        return $this->detail($id);
    }

    /**
     * Reservas activas futuras del barbero que no caben en su jornada efectiva actual.
     *
     * @return list<array<string, mixed>>
     */
    public function bookingsOutsideSchedule(int $id, ?BranchRepository $branchRepo = null): array
    {
        $bookings = $this->repo->activeBookings($id, Clock::nowSql());
        if ($bookings === []) {
            return [];
        }
        $branchRepo = $branchRepo ?? new BranchRepository($this->tenantId);
        $branches = $branchRepo->scheduleMap(true);
        $shifts = $this->repo->shifts($id);
        $breaks = $this->repo->breaks($id);
        $blocks = new AgendaBlockRepository($this->tenantId);
        $out = [];
        foreach ($bookings as $r) {
            $tz = WorkingTime::zone($branches[(int) $r['sucursal_id']]['zona_horaria'] ?? $this->tenantTimezone);
            $fecha = Clock::utcToLocal((string) $r['inicio_utc'], $tz->getName())->format('Y-m-d');
            $ventanas = WorkingTime::windows($shifts, $breaks, $branches, $blocks->approvedFor($id, (string) $r['inicio_utc'], (string) $r['fin_utc']), $fecha, $fecha);
            $cabe = false;
            foreach ($ventanas as $v) {
                if ($v['sucursal_id'] === (int) $r['sucursal_id'] && $v['inicio_utc'] <= $r['inicio_utc'] && $r['fin_utc'] <= $v['fin_utc']) {
                    $cabe = true;
                    break;
                }
            }
            if (!$cabe) {
                $out[] = ['codigo' => $r['codigo'], 'inicio_utc' => $r['inicio_utc'], 'fin_utc' => $r['fin_utc'], 'estado' => $r['estado']];
            }
        }
        return $out;
    }

    private function assertNoFutureBookings(int $id, string $accion): void
    {
        $futuras = $this->repo->activeBookings($id, Clock::nowSql());
        if ($futuras !== []) {
            $n = count($futuras);
            throw new HttpException(409, 'barber_has_bookings', "No puedes {$accion} al barbero: tiene {$n} reserva(s) futuras activas. Reprográmalas o cancélalas primero.", [
                'reservas' => array_map(static fn (array $r): string => (string) $r['codigo'], array_slice($futuras, 0, 20)),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input, bool $partial, ?int $id = null): array
    {
        $req = $partial ? '' : 'required|';
        $data = Validator::validate($input, [
            'nombre_publico' => $req . 'string|min:2|max:100',
            'especialidad' => 'nullable|string|max:120',
            'biografia' => 'nullable|string|max:1000',
            'sucursal_id' => $req . 'integer|min:1',
            'usuario_id' => 'nullable|integer|min:1',
            'activo' => 'nullable|boolean',
            'acepta_reservas_online' => 'nullable|boolean',
            'orden' => 'nullable|integer|min:0|max:9999',
        ]);
        foreach (['activo', 'acepta_reservas_online', 'orden', 'sucursal_id'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] === null) {
                unset($data[$k]);
            }
        }
        if (isset($data['sucursal_id'])) {
            $suc = (new BranchRepository($this->tenantId))->find((int) $data['sucursal_id']);
            if ($suc === null) {
                throw new ValidationException(['sucursal_id' => ['Sucursal no encontrada.']]);
            }
            if (!(bool) $suc['activo']) {
                throw new ValidationException(['sucursal_id' => ['La sucursal está desactivada.']]);
            }
        }
        if (isset($data['usuario_id'])) {
            $uid = (int) $data['usuario_id'];
            if (!$this->repo->userIsBarberMember($uid)) {
                throw new ValidationException(['usuario_id' => ['La cuenta debe pertenecer a tu equipo con rol de barbero (invítala desde Personal).']]);
            }
            if ($this->repo->userLinked($uid, $id)) {
                throw new ValidationException(['usuario_id' => ['Esa cuenta ya está vinculada a otro barbero.']]);
            }
        }
        return $data;
    }
}
