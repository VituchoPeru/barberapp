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
            'respondido_en' => Clock::nowSql(),
        ];
        $id = Database::transaction(function () use ($row): int {
            $this->lockAndCheck($row['barbero_id'], $row['sucursal_id'], $row['inicio_utc'], $row['fin_utc']);
            return $this->repo->create($row);
        });
        Audit::record('bloqueo.creado', 'exito', $request, null, $this->tenantId, 'bloqueos_agenda', $id, $row);
        return $this->presented($id);
    }

    /**
     * Aprueba una solicitud. Toma el candado de la solicitud y de las agendas afectadas y
     * vuelve a comprobar que no haya reservas activas en el rango.
     *
     * @param array<string, mixed> $input respuesta (opcional)
     * @return array<string, mixed>
     */
    public function approve(int $id, Request $request, array $input = []): array
    {
        $respuesta = $this->answerText($input);
        $user = (array) $request->attribute('auth_user');
        $b = Database::transaction(function () use ($id, $user, $respuesta): array {
            $b = $this->lockPending($id);
            $this->lockAndCheck($b['barbero_id'] === null ? null : (int) $b['barbero_id'], $b['sucursal_id'] === null ? null : (int) $b['sucursal_id'], (string) $b['inicio_utc'], (string) $b['fin_utc']);
            $this->repo->respond($id, 'aprobado', (int) $user['id'], $respuesta, Clock::nowSql());
            return $b;
        });
        Audit::record('bloqueo.aprobado', 'exito', $request, null, $this->tenantId, 'bloqueos_agenda', $id, $respuesta !== null ? ['respuesta' => $respuesta] : null);
        $out = $this->presented($id);
        $this->notifyAnswer($b, $out, true, $respuesta);
        return $out;
    }

    /**
     * @param array<string, mixed> $input respuesta (opcional): se muestra al barbero
     * @return array<string, mixed>
     */
    public function reject(int $id, Request $request, array $input = []): array
    {
        $respuesta = $this->answerText($input);
        $user = (array) $request->attribute('auth_user');
        $b = Database::transaction(function () use ($id, $user, $respuesta): array {
            $b = $this->lockPending($id);
            $this->repo->respond($id, 'rechazado', (int) $user['id'], $respuesta, Clock::nowSql());
            return $b;
        });
        Audit::record('bloqueo.rechazado', 'exito', $request, null, $this->tenantId, 'bloqueos_agenda', $id, $respuesta !== null ? ['respuesta' => $respuesta] : null);
        $out = $this->presented($id);
        $this->notifyAnswer($b, $out, false, $respuesta);
        return $out;
    }

    // ================================================================== solicitudes del barbero (Fase 7)

    /** Tipos que un barbero puede pedir para su propia agenda (los feriados los define la barbería). */
    public const TIPOS_BARBERO = ['vacaciones', 'ausencia', 'descanso', 'bloqueo'];

    /** Máximo de solicitudes vigentes sin atender por barbero (evita llenar la bandeja del encargado). */
    public const MAX_PENDIENTES = 10;

    /**
     * Un barbero pide bloquear SU agenda. Si la barbería exige aprobación (o quien pide no puede
     * aprobar), queda "solicitado" y no cambia la disponibilidad hasta que se apruebe; se avisa a
     * quienes pueden aprobar y se informan (sin bloquear) las citas que ya tiene en ese rango.
     * Si no requiere aprobación, se registra aprobado con las mismas reglas que en el panel
     * (409 si pisa reservas activas).
     *
     * @param array<string, mixed> $input tipo, sucursal_id?, dia_completo, fecha_inicio, fecha_fin, inicio, fin, motivo
     * @return array<string, mixed> bloqueo + advertencias
     */
    public function requestForBarber(int $barberoId, array $input, Request $request, bool $requiereAprobacion): array
    {
        $data = Validator::validate($input, [
            'tipo' => 'required|in:' . implode(',', self::TIPOS_BARBERO),
            'sucursal_id' => 'nullable|integer|min:1',
            'dia_completo' => 'nullable|boolean',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date',
            'inicio' => 'nullable|regex:/^\d{4}-\d{2}-\d{2}[T ][0-2]\d:[0-5]\d$/',
            'fin' => 'nullable|regex:/^\d{4}-\d{2}-\d{2}[T ][0-2]\d:[0-5]\d$/',
            'motivo' => 'nullable|string|max:255',
        ]);
        $sucursalId = isset($data['sucursal_id']) ? (int) $data['sucursal_id'] : null;
        if ($sucursalId !== null && (new BranchRepository($this->tenantId))->find($sucursalId) === null) {
            throw new ValidationException(['sucursal_id' => ['Sucursal no encontrada.']]);
        }
        $motivo = isset($data['motivo']) && trim((string) $data['motivo']) !== '' ? trim((string) $data['motivo']) : null;
        if ($requiereAprobacion && $motivo === null) {
            throw new ValidationException(['motivo' => ['Cuéntale al encargado el motivo de tu solicitud.']]);
        }
        [$ini, $fin] = $this->range($data, $this->timezoneFor($barberoId, $sucursalId));

        $user = (array) $request->attribute('auth_user');
        $userId = (int) $user['id'];
        $now = Clock::nowSql();
        $row = [
            'tipo' => $data['tipo'], 'barbero_id' => $barberoId, 'sucursal_id' => $sucursalId,
            'inicio_utc' => $ini, 'fin_utc' => $fin, 'motivo' => $motivo,
            'estado' => $requiereAprobacion ? 'solicitado' : 'aprobado', 'creado_por' => $userId,
            'aprobado_por' => $requiereAprobacion ? null : $userId, 'respondido_en' => $requiereAprobacion ? null : $now,
        ];
        $barbers = new BarberRepository($this->tenantId);
        $conflictos = [];
        $id = Database::transaction(function () use ($row, $barberoId, $requiereAprobacion, $barbers, $ini, $fin, $now, &$conflictos): int {
            // Candado de la agenda del barbero: serializa sus solicitudes y las reservas que la tocan.
            if (!$barbers->lock($barberoId)) {
                throw new NotFoundException('Barbero no encontrado.');
            }
            $solapes = $this->repo->overlappingForBarber($barberoId, $ini, $fin);
            if ($solapes !== []) {
                $pend = count(array_filter($solapes, static fn (array $s): bool => $s['estado'] === 'solicitado'));
                throw new HttpException(409, 'block_overlaps', $pend > 0
                    ? 'Ya tienes una solicitud pendiente que se cruza con esas fechas. Espera la respuesta o retírala.'
                    : 'Esas fechas ya están bloqueadas en tu agenda.');
            }
            if ($requiereAprobacion) {
                if ($this->repo->pendingCountForBarber($barberoId, $now) >= self::MAX_PENDIENTES) {
                    throw new HttpException(429, 'too_many_requests', 'Tienes ' . self::MAX_PENDIENTES . ' solicitudes sin respuesta. Espera a que el encargado las revise.');
                }
                $conflictos = $this->repo->conflictingBookings($barberoId, $row['sucursal_id'], $ini, $fin);
            } else {
                $this->lockAndCheck($barberoId, $row['sucursal_id'], $ini, $fin);
            }
            return $this->repo->create($row);
        });

        Audit::record($requiereAprobacion ? 'bloqueo.solicitado' : 'bloqueo.creado', 'exito', $request, $userId, $this->tenantId, 'bloqueos_agenda', $id, $row);
        $out = $this->presented($id);
        if ($requiereAprobacion) {
            $barbero = (array) $barbers->find($barberoId);
            $destinos = array_values(array_filter($this->repo->approverUserIds(), static fn (int $u): bool => $u !== $userId));
            (new BlockNotifier($this->tenantId))->requested($id, $destinos, (string) ($barbero['nombre_publico'] ?? 'Un barbero'), self::describe($out));
        }
        $tz = (string) $out['zona_horaria'];
        $out['advertencias'] = [
            // Citas que ya tiene en ese rango: el encargado deberá reprogramarlas antes de aprobar.
            'reservas_en_conflicto' => array_map(static fn (array $r): array => [
                'codigo' => $r['codigo'],
                'inicio' => Clock::utcToLocal((string) $r['inicio_utc'], $tz)->format('Y-m-d H:i'),
                'fin' => Clock::utcToLocal((string) $r['fin_utc'], $tz)->format('Y-m-d H:i'),
            ], $conflictos),
        ];
        return $out;
    }

    /** El barbero retira una solicitud suya que aún no fue atendida. */
    public function withdrawForBarber(int $barberoId, int $id, Request $request): void
    {
        $b = Database::transaction(function () use ($barberoId, $id): array {
            $b = $this->repo->lockRow($id);
            if ($b === null || $b['barbero_id'] === null || (int) $b['barbero_id'] !== $barberoId) {
                throw new NotFoundException('Solicitud no encontrada.');
            }
            if ($b['estado'] !== 'solicitado') {
                throw new HttpException(409, 'block_not_pending', 'Esta solicitud ya fue atendida: pide al encargado que la modifique.');
            }
            $this->repo->delete($id);
            return $b;
        });
        Audit::record('bloqueo.retirado', 'exito', $request, null, $this->tenantId, 'bloqueos_agenda', $id, null, $b);
    }

    /**
     * Bloqueos que ve el barbero: los suyos (cualquier estado) y los generales aprobados
     * (feriados, cierres de sede) que también lo afectan, desde hace 30 días.
     *
     * @param array<string, mixed> $query estado?
     * @return list<array<string, mixed>>
     */
    public function listForBarber(int $barberoId, array $query): array
    {
        $f = Validator::validate($query, ['estado' => 'nullable|in:' . implode(',', AgendaBlockRepository::ESTADOS)]);
        $desde = Clock::nowUtc()->modify('-30 days')->format('Y-m-d H:i:s');
        $rows = $this->repo->search(['barbero_id' => $barberoId, 'desde' => $desde, 'estado' => $f['estado'] ?? null], 300);
        $rows = array_values(array_filter($rows, static fn (array $b): bool => $b['barbero_id'] !== null || $b['estado'] === 'aprobado'));
        return array_map(fn (array $b): array => CatalogPresenter::block($b, $this->timezoneFor($b['barbero_id'] === null ? null : (int) $b['barbero_id'], $b['sucursal_id'] === null ? null : (int) $b['sucursal_id'])), $rows);
    }

    /** Texto corto del rango de un bloqueo presentado (para avisos). @param array<string, mixed> $b */
    public static function describe(array $b): string
    {
        $d = static fn (string $ymd): string => substr($ymd, 8, 2) . '/' . substr($ymd, 5, 2);
        if ($b['dia_completo']) {
            return $b['fecha_inicio'] === $b['fecha_fin']
                ? 'el ' . $d((string) $b['fecha_inicio']) . ' (todo el día)'
                : 'del ' . $d((string) $b['fecha_inicio']) . ' al ' . $d((string) $b['fecha_fin']);
        }
        [$fi, $hi] = explode('T', (string) $b['inicio_local']);
        [$ff, $hf] = explode('T', (string) $b['fin_local']);
        return $fi === $ff ? 'el ' . $d($fi) . " de {$hi} a {$hf}" : 'del ' . $d($fi) . " {$hi} al " . $d($ff) . " {$hf}";
    }

    /** @return array<string, mixed> */
    private function lockPending(int $id): array
    {
        $b = $this->repo->lockRow($id);
        if ($b === null) {
            throw new NotFoundException('Bloqueo no encontrado.');
        }
        if ($b['estado'] !== 'solicitado') {
            throw new HttpException(409, 'block_not_pending', 'Esta solicitud ya fue atendida.');
        }
        return $b;
    }

    /** @param array<string, mixed> $input */
    private function answerText(array $input): ?string
    {
        $data = Validator::validate($input, ['respuesta' => 'nullable|string|max:255']);
        $r = isset($data['respuesta']) ? trim((string) $data['respuesta']) : '';
        return $r === '' ? null : $r;
    }

    /**
     * Avisa al barbero que pidió la ausencia (si su ficha está vinculada a una cuenta).
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $presented
     */
    private function notifyAnswer(array $row, array $presented, bool $aprobado, ?string $respuesta): void
    {
        if ($row['barbero_id'] === null) {
            return;
        }
        $barbero = (new BarberRepository($this->tenantId))->find((int) $row['barbero_id']);
        if ($barbero === null || $barbero['usuario_id'] === null) {
            return;
        }
        (new BlockNotifier($this->tenantId))->answered((int) $row['id'], (int) $barbero['usuario_id'], $aprobado, self::describe($presented), $respuesta);
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
