<?php

declare(strict_types=1);

namespace App\Services\Schedule;

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
use App\Services\Audit;
use App\Services\Catalog\CatalogPresenter;
use DateTimeImmutable;

/**
 * Vacaciones, ausencias, feriados, descansos puntuales y bloqueos de agenda (bloqueos.gestionar).
 *
 * El panel envía fechas y horas LOCALES de la sucursal (o de la sucursal principal del barbero,
 * o de la barbería si el bloqueo es general) y se guardan en UTC.
 * Un bloqueo aprobado deja de ofrecer esos intervalos. No se permite aprobar un bloqueo que
 * se cruce con reservas activas: primero hay que reprogramarlas o cancelarlas (Fases 5 y 6),
 * para que ningún cliente se quede con una cita dentro de unas vacaciones.
 */
final class AgendaBlockService
{
    public const MAX_DIAS = 366;

    private AgendaBlockRepository $repo;

    /** @var array<int, array<string, mixed>>|null caché de sucursales para convertir zonas horarias */
    private ?array $branches = null;

    /** @var array<int, ?int> barbero → sucursal principal */
    private array $barberBranch = [];

    public function __construct(private int $tenantId, private string $tenantTimezone = 'America/Lima')
    {
        $this->repo = new AgendaBlockRepository($tenantId);
    }

    /**
     * @param array<string, mixed> $query desde/hasta (AAAA-MM-DD locales), barbero_id, sucursal_id, estado, tipo
     * @return list<array<string, mixed>>
     */
    public function list(array $query): array
    {
        $f = Validator::validate($query, [
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date',
            'barbero_id' => 'nullable|integer|min:1',
            'sucursal_id' => 'nullable|integer|min:1',
            'estado' => 'nullable|in:' . implode(',', AgendaBlockRepository::ESTADOS),
            'tipo' => 'nullable|in:' . implode(',', AgendaBlockRepository::TIPOS),
        ]);
        $tz = $this->tenantTimezone;
        // Por defecto: bloqueos vigentes o futuros.
        $f['desde'] = isset($f['desde']) ? Clock::localToUtc($f['desde'] . ' 00:00:00', $tz)->format('Y-m-d H:i:s') : Clock::nowSql();
        $f['hasta'] = isset($f['hasta']) ? Clock::localToUtc($f['hasta'] . ' 00:00:00', $tz)->modify('+1 day')->format('Y-m-d H:i:s') : null;
        return array_map(fn (array $b): array => CatalogPresenter::block($b, $this->timezoneFor($b['barbero_id'] === null ? null : (int) $b['barbero_id'], $b['sucursal_id'] === null ? null : (int) $b['sucursal_id'])), $this->repo->search($f));
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function create(array $input, Request $request): array
    {
        $data = Validator::validate($input, [
            'tipo' => 'required|in:' . implode(',', AgendaBlockRepository::TIPOS),
            'barbero_id' => 'nullable|integer|min:1',
            'sucursal_id' => 'nullable|integer|min:1',
            'dia_completo' => 'nullable|boolean',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
            // Fecha y hora local "AAAA-MM-DDTHH:MM" (formato de <input type="datetime-local">).
            'inicio' => 'nullable|regex:/^\d{4}-\d{2}-\d{2}[T ][0-2]\d:[0-5]\d$/',
            'fin' => 'nullable|regex:/^\d{4}-\d{2}-\d{2}[T ][0-2]\d:[0-5]\d$/',
            'motivo' => 'nullable|string|max:255',
        ]);
        $barberoId = isset($data['barbero_id']) ? (int) $data['barbero_id'] : null;
        $sucursalId = isset($data['sucursal_id']) ? (int) $data['sucursal_id'] : null;
        if ($barberoId !== null && (new BarberRepository($this->tenantId))->find($barberoId) === null) {
            throw new ValidationException(['barbero_id' => ['Barbero no encontrado.']]);
        }
        if ($sucursalId !== null && (new BranchRepository($this->tenantId))->find($sucursalId) === null) {
            throw new ValidationException(['sucursal_id' => ['Sucursal no encontrada.']]);
        }
        $tz = $this->timezoneFor($barberoId, $sucursalId);
        [$ini, $fin] = $this->range($data, $tz);

        $user = (array) $request->attribute('auth_user');
        $row = [
            'tipo' => $data['tipo'], 'barbero_id' => $barberoId, 'sucursal_id' => $sucursalId,
            'inicio_utc' => $ini, 'fin_utc' => $fin, 'motivo' => $data['motivo'] ?? null,
            'estado' => 'aprobado', 'creado_por' => (int) $user['id'], 'aprobado_por' => (int) $user['id'],
        ];
        $id = Database::transaction(function () use ($row): int {
            $this->lockAndCheck($row['barbero_id'], $row['sucursal_id'], $row['inicio_utc'], $row['fin_utc']);
            return $this->repo->create($row);
        });
        Audit::record('bloqueo.creado', 'exito', $request, null, $this->tenantId, 'bloqueos_agenda', $id, $row);
        return $this->presented($id);
    }

    /** @return array<string, mixed> */
    public function approve(int $id, Request $request): array
    {
        $user = (array) $request->attribute('auth_user');
        Database::transaction(function () use ($id, $user): void {
            $b = $this->find($id);
            if ($b['estado'] !== 'solicitado') {
                throw new HttpException(409, 'block_not_pending', 'Esta solicitud ya fue atendida.');
            }
            $this->lockAndCheck($b['barbero_id'] === null ? null : (int) $b['barbero_id'], $b['sucursal_id'] === null ? null : (int) $b['sucursal_id'], (string) $b['inicio_utc'], (string) $b['fin_utc']);
            $this->repo->setEstado($id, 'aprobado', (int) $user['id']);
        });
        Audit::record('bloqueo.aprobado', 'exito', $request, null, $this->tenantId, 'bloqueos_agenda', $id);
        return $this->presented($id);
    }

    /** @return array<string, mixed> */
    public function reject(int $id, Request $request): array
    {
        $b = $this->find($id);
        if ($b['estado'] !== 'solicitado') {
            throw new HttpException(409, 'block_not_pending', 'Esta solicitud ya fue atendida.');
        }
        $this->repo->setEstado($id, 'rechazado', (int) ((array) $request->attribute('auth_user'))['id']);
        Audit::record('bloqueo.rechazado', 'exito', $request, null, $this->tenantId, 'bloqueos_agenda', $id);
        return $this->presented($id);
    }

    public function delete(int $id, Request $request): void
    {
        $b = $this->find($id);
        $this->repo->delete($id);
        // Al eliminar un bloqueo aprobado, el intervalo vuelve a ofrecerse de inmediato.
        Audit::record('bloqueo.eliminado', 'exito', $request, null, $this->tenantId, 'bloqueos_agenda', $id, null, $b);
    }

    /** @return array<string, mixed> */
    private function find(int $id): array
    {
        $b = $this->repo->find($id);
        if ($b === null) {
            throw new NotFoundException('Bloqueo no encontrado.');
        }
        return $b;
    }

    /** @return array<string, mixed> */
    private function presented(int $id): array
    {
        $b = $this->repo->search(['id' => $id], 1)[0] ?? null;
        if ($b === null) {
            throw new NotFoundException('Bloqueo no encontrado.');
        }
        return CatalogPresenter::block($b, $this->timezoneFor($b['barbero_id'] === null ? null : (int) $b['barbero_id'], $b['sucursal_id'] === null ? null : (int) $b['sucursal_id']));
    }

    /**
     * Bloquea las agendas afectadas (orden fijo) y rechaza si hay reservas activas en el rango.
     */
    private function lockAndCheck(?int $barberoId, ?int $sucursalId, string $ini, string $fin): void
    {
        (new BarberRepository($this->tenantId))->lockMany($this->repo->affectedBarberIds($barberoId, $sucursalId));
        $conflicts = $this->repo->conflictingBookings($barberoId, $sucursalId, $ini, $fin);
        if ($conflicts !== []) {
            throw new HttpException(409, 'block_conflicts_bookings', 'Hay ' . count($conflicts) . ' reserva(s) activas en ese periodo. Reprográmalas o cancélalas antes de bloquear la agenda.', [
                'reservas' => array_map(static fn (array $r): array => [
                    'codigo' => $r['codigo'], 'barbero' => $r['barbero'], 'inicio_utc' => $r['inicio_utc'], 'fin_utc' => $r['fin_utc'],
                ], $conflicts),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0:string, 1:string} inicio y fin en UTC (Y-m-d H:i:s)
     */
    private function range(array $data, string $tz): array
    {
        if (!empty($data['dia_completo'])) {
            if (empty($data['fecha_inicio'])) {
                throw new ValidationException(['fecha_inicio' => ['Indica la fecha de inicio.']]);
            }
            $desde = (string) $data['fecha_inicio'];
            $hasta = (string) ($data['fecha_fin'] ?? $desde);
            if ($hasta < $desde) {
                throw new ValidationException(['fecha_fin' => ['La fecha final debe ser igual o posterior a la inicial.']]);
            }
            $ini = Clock::localToUtc($desde . ' 00:00:00', $tz);
            $fin = Clock::localToUtc((new DateTimeImmutable($hasta))->modify('+1 day')->format('Y-m-d') . ' 00:00:00', $tz);
        } else {
            if (empty($data['inicio']) || empty($data['fin'])) {
                throw new ValidationException(array_filter([
                    'inicio' => empty($data['inicio']) ? ['Indica la fecha y hora de inicio.'] : null,
                    'fin' => empty($data['fin']) ? ['Indica la fecha y hora de fin.'] : null,
                ]));
            }
            $ini = $this->localDateTime((string) $data['inicio'], $tz, 'inicio');
            $fin = $this->localDateTime((string) $data['fin'], $tz, 'fin');
            if ($fin <= $ini) {
                throw new ValidationException(['fin' => ['El fin debe ser posterior al inicio.']]);
            }
        }
        if ($fin <= Clock::nowUtc()) {
            throw new ValidationException(['fin' => ['El bloqueo ya terminó: solo se pueden registrar bloqueos vigentes o futuros.']]);
        }
        if ($ini->diff($fin)->days > self::MAX_DIAS) {
            throw new ValidationException(['fin' => ['Un bloqueo puede durar como máximo ' . self::MAX_DIAS . ' días.']]);
        }
        return [$ini->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s')];
    }

    private function localDateTime(string $value, string $tz, string $field): DateTimeImmutable
    {
        $value = str_replace('T', ' ', $value);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, new \DateTimeZone($tz));
        if ($d === false || $d->format('Y-m-d H:i') !== $value) {
            throw new ValidationException([$field => ['Fecha u hora inválida.']]);
        }
        return $d->setTimezone(new \DateTimeZone('UTC'));
    }

    /** Zona horaria con la que se interpretan las horas del bloqueo. */
    private function timezoneFor(?int $barberoId, ?int $sucursalId): string
    {
        $this->branches ??= (new BranchRepository($this->tenantId))->scheduleMap();
        if ($sucursalId !== null && isset($this->branches[$sucursalId])) {
            return $this->branches[$sucursalId]['zona_horaria'];
        }
        if ($barberoId !== null) {
            if (!array_key_exists($barberoId, $this->barberBranch)) {
                $this->barberBranch[$barberoId] = (new BarberRepository($this->tenantId))->branchOf($barberoId);
            }
            $suc = $this->barberBranch[$barberoId];
            if ($suc !== null && isset($this->branches[$suc])) {
                return $this->branches[$suc]['zona_horaria'];
            }
        }
        return $this->tenantTimezone;
    }
}
