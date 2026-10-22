<?php

declare(strict_types=1);

namespace App\Services\Panel;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Helpers\Clock;
use App\Helpers\Ip;
use App\Helpers\Validator;
use App\Repositories\AgendaBlockRepository;
use App\Repositories\AgendaRepository;
use App\Repositories\BarberRepository;
use App\Repositories\BookingRepository;
use App\Repositories\BranchRepository;
use App\Repositories\PanelClientRepository;
use App\Services\Audit;
use App\Services\Booking\Availability;
use App\Services\Booking\BookingNotifier;
use App\Services\Booking\BookingService;
use App\Services\Schedule\WorkingTime;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Agenda del panel de la barbería (Fase 6): vista diaria/semanal, alta manual, confirmación,
 * atención (inicio, atendida, no asistió), notas internas y cobros.
 *
 * Alcance: quien tiene reservas.ver_todas ve toda la barbería; quien solo tiene
 * reservas.ver_propias (barbero) ve y opera únicamente las citas del barbero vinculado a su cuenta.
 * Crear, cancelar y reprogramar pasan por BookingService (mismos candados que en línea).
 */
final class AgendaService
{
    public const MAX_DIAS = 7;

    private AgendaRepository $repo;

    /**
     * @param list<string> $perms      permisos de quien opera
     * @param int|null     $barberOnly fija el alcance a un barbero aunque tenga reservas.ver_todas
     *                                 (panel del barbero, Fase 7: el propietario que también atiende ve solo lo suyo)
     */
    public function __construct(private int $tenantId, private array $perms, private int $userId, private ?int $barberOnly = null)
    {
        $this->repo = new AgendaRepository($tenantId);
    }

    public function repo(): AgendaRepository
    {
        return $this->repo;
    }

    public function can(string $perm): bool
    {
        return in_array($perm, $this->perms, true);
    }

    /**
     * null = toda la barbería; un id = solo ese barbero (cuenta de barbero sin reservas.ver_todas).
     * Un integrante sin ninguno de los dos permisos no llega aquí (lo impide la ruta).
     */
    public function ownBarberScope(): ?int
    {
        if ($this->barberOnly !== null) {
            return $this->barberOnly;
        }
        if ($this->can('reservas.ver_todas')) {
            return null;
        }
        $id = $this->repo->barberIdForUser($this->userId);
        if ($id === null) {
            throw new HttpException(403, 'no_barber_profile', 'Tu cuenta no está vinculada a un barbero de esta barbería. Pide al propietario que la vincule.');
        }
        return $id;
    }

    // ================================================================== agenda

    /**
     * @param array<string, mixed> $query fecha | desde+hasta, sucursal_id, barbero_id, vista=dia|semana
     * @return array<string, mixed>
     */
    public function agenda(array $query): array
    {
        $q = Validator::validate($query, [
            'fecha' => 'nullable|date', 'desde' => 'nullable|date', 'hasta' => 'nullable|date',
            'sucursal_id' => 'nullable|integer|min:1', 'barbero_id' => 'nullable|integer|min:1', 'vista' => 'nullable|in:dia,semana',
        ]);
        $branches = (new BranchRepository($this->tenantId))->scheduleMap(false);
        $activeBranches = array_filter($branches, static fn (array $b): bool => $b['activo']);
        $sucursalId = isset($q['sucursal_id']) ? (int) $q['sucursal_id'] : (int) (array_key_first($activeBranches) ?? array_key_first($branches) ?? 0);
        if (!isset($branches[$sucursalId])) {
            throw new NotFoundException('Sucursal no encontrada.');
        }
        $tzName = WorkingTime::zone($branches[$sucursalId]['zona_horaria'])->getName();
        $tz = new DateTimeZone($tzName);
        $hoy = Clock::nowUtc()->setTimezone($tz)->format('Y-m-d');

        [$desde, $hasta] = $this->range($q, $hoy);
        $scope = $this->ownBarberScope();
        $barberoId = $scope ?? (isset($q['barbero_id']) ? (int) $q['barbero_id'] : null);

        $desdeUtc = (new DateTimeImmutable($desde . ' 00:00:00', $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $hastaUtc = (new DateTimeImmutable($hasta . ' 00:00:00', $tz))->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $rows = $this->repo->range($sucursalId, $barberoId, $desdeUtc, $hastaUtc);
        $services = $this->repo->servicesFor(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $reservas = array_map(fn (array $r): array => PanelBookingPresenter::present($r, $services[(int) $r['id']] ?? [], $this->perms), $rows);

        $barberRows = $this->repo->agendaBarbers($sucursalId, array_map(static fn (array $r): int => (int) $r['barbero_id'], $rows));
        if ($barberoId !== null) {
            $barberRows = array_values(array_filter($barberRows, static fn (array $b): bool => (int) $b['id'] === $barberoId));
        }

        // Jornada efectiva de cada barbero en esta sucursal (zonas de trabajo en la agenda).
        $barbersRepo = new BarberRepository($this->tenantId);
        $blocksRepo = new AgendaBlockRepository($this->tenantId);
        $only = [$sucursalId => $branches[$sucursalId]];
        $jornadas = [];
        foreach ($barberRows as $b) {
            $bid = (int) $b['id'];
            foreach (WorkingTime::windows($barbersRepo->shifts($bid), $barbersRepo->breaks($bid), $only, $blocksRepo->approvedFor($bid, $desdeUtc, $hastaUtc), $desde, $hasta) as $w) {
                $jornadas[] = ['barbero_id' => $bid, 'fecha' => $w['fecha'], 'inicio' => $w['inicio_local'], 'fin' => $w['fin_local']];
            }
        }

        $bloqueos = [];
        foreach ($this->repo->blocks($sucursalId, $desdeUtc, $hastaUtc) as $bl) {
            if ($barberoId !== null && $bl['barbero_id'] !== null && (int) $bl['barbero_id'] !== $barberoId) {
                continue;
            }
            $bloqueos[] = [
                'id' => (int) $bl['id'], 'tipo' => (string) $bl['tipo'], 'estado' => (string) $bl['estado'],
                'barbero_id' => $bl['barbero_id'] === null ? null : (int) $bl['barbero_id'],
                'inicio' => Clock::utcToLocal((string) $bl['inicio_utc'], $tzName)->format('Y-m-d H:i'),
                'fin' => Clock::utcToLocal((string) $bl['fin_utc'], $tzName)->format('Y-m-d H:i'),
                'motivo' => $bl['motivo'],
            ];
        }

        $resumen = ['total' => 0, 'pendiente' => 0, 'confirmada' => 0, 'completada' => 0, 'cancelada' => 0, 'no_asistio' => 0, 'en_atencion' => 0];
        foreach ($reservas as $r) {
            $resumen[$r['estado']]++;
            $resumen['total'] += $r['estado'] !== 'cancelada' ? 1 : 0;
            $resumen['en_atencion'] += $r['en_atencion'] ? 1 : 0;
        }

        return [
            'sucursal' => ['id' => $sucursalId, 'nombre' => $branches[$sucursalId]['nombre'], 'zona_horaria' => $tzName],
            'sucursales' => array_map(static fn (int $id, array $b): array => ['id' => $id, 'nombre' => $b['nombre'], 'activo' => $b['activo']], array_keys($branches), $branches),
            'hoy' => $hoy,
            'desde' => $desde,
            'hasta' => $hasta,
            'alcance' => $scope === null ? 'barberia' : 'propia',
            'barberos' => array_map(static fn (array $b): array => [
                'id' => (int) $b['id'], 'nombre' => (string) $b['nombre_publico'], 'foto_url' => \App\Helpers\Media::url($b['foto_url']), 'activo' => (bool) $b['activo'],
            ], $barberRows),
            'jornadas' => $jornadas,
            'bloqueos' => $bloqueos,
            'reservas' => $reservas,
            'resumen' => $resumen,
            'actualizado_en' => gmdate('Y-m-d\TH:i:s\Z', Clock::nowUtc()->getTimestamp()),
        ];
    }

    /** @param array<string, mixed> $q @return array{0:string, 1:string} */
    private function range(array $q, string $hoy): array
    {
        if (!empty($q['desde'])) {
            $desde = (string) $q['desde'];
            $hasta = (string) ($q['hasta'] ?? $desde);
        } else {
            $fecha = (string) ($q['fecha'] ?? $hoy);
            if (($q['vista'] ?? 'dia') === 'semana') {
                $d = new DateTimeImmutable($fecha);
                $desde = $d->modify('-' . ((int) $d->format('N') - 1) . ' day')->format('Y-m-d');
                $hasta = (new DateTimeImmutable($desde))->modify('+6 day')->format('Y-m-d');
            } else {
                $desde = $hasta = $fecha;
            }
        }
        if ($hasta < $desde) {
            throw new ValidationException(['hasta' => ['La fecha final debe ser igual o posterior a la inicial.']]);
        }
        if ((new DateTimeImmutable($desde))->diff(new DateTimeImmutable($hasta))->days >= self::MAX_DIAS) {
            throw new ValidationException(['hasta' => ['La agenda muestra como máximo ' . self::MAX_DIAS . ' días.']]);
        }
        return [$desde, $hasta];
    }

    // ================================================================== detalle

    /** Cita de la barbería visible para quien consulta (404 si no existe o no le corresponde). @return array<string, mixed> */
    public function visible(int $id): array
    {
        $r = $this->repo->find($id);
        $scope = $this->ownBarberScope();
        if ($r === null || ($scope !== null && (int) $r['barbero_id'] !== $scope)) {
            throw new NotFoundException('Cita no encontrada.');
        }
        return $r;
    }

    /** @return array<string, mixed> */
    public function presented(int $id, bool $withDetail = false): array
    {
        $r = $this->visible($id);
        $out = PanelBookingPresenter::present($r, $this->repo->servicesFor([$id])[$id] ?? [], $this->perms);
        if ($withDetail) {
            $events = $this->repo->events($id);
            $names = (new BookingRepository($this->tenantId))->barberNames(array_merge(array_column($events, 'barbero_anterior_id'), array_column($events, 'barbero_nuevo_id')));
            $out['historial'] = PanelBookingPresenter::history($events, (string) $r['zona_horaria'], $names);
            $out['cobros'] = $this->can('reservas.cobrar') || $this->can('reportes.financieros')
                ? array_map(static fn (array $p): array => [
                    'id' => (int) $p['id'], 'monto' => (string) $p['monto'], 'moneda' => (string) $p['moneda'], 'metodo' => (string) $p['metodo'],
                    'referencia' => $p['referencia'], 'pagado_en' => \App\Services\Booking\BookingPresenter::iso((string) $p['pagado_en']),
                    'registrado_por' => $p['registrado_por'] ?: null, 'anulado' => $p['anulado_en'] !== null, 'motivo_anulacion' => $p['motivo_anulacion'],
                ], $this->repo->payments($id))
                : null;
        }
        return $out;
    }

    // ================================================================== alta manual

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $user, array $input, ?Request $request = null): array
    {
        if ($this->ownBarberScope() !== null) {
            throw new HttpException(403, 'forbidden', 'No tienes permiso para registrar citas de toda la barbería.');
        }
        $clients = new PanelClientRepository($this->tenantId);
        $nuevo = null;
        $clienteId = null;
        if (isset($input['cliente_id']) && $input['cliente_id'] !== null && $input['cliente_id'] !== '') {
            $v = Validator::validate($input, ['cliente_id' => 'required|integer|min:1']);
            $clienteId = (int) $v['cliente_id'];
        } elseif (is_array($input['cliente'] ?? null)) {
            $nuevo = ClientInput::validate((array) $input['cliente']);
        } else {
            throw new ValidationException(['cliente_id' => ['Elige un cliente o registra uno nuevo.']]);
        }

        $resolve = function () use ($clients, $clienteId, $nuevo, $request): int {
            if ($clienteId === null) {
                $clienteId = $clients->findByContact($nuevo['telefono'], $nuevo['email']);
                if ($clienteId === null) {
                    $clienteId = $clients->createCounterClient($nuevo);
                    Audit::record('cliente.registrado_mostrador', 'exito', $request, $this->userId, $this->tenantId, 'clientes', $clienteId);
                }
            }
            if ($clients->lockLink($clienteId) === null) {
                throw new ValidationException(['cliente_id' => ['Ese cliente no está registrado en tu barbería.']]);
            }
            return $clienteId;
        };
        $out = (new BookingService($this->tenantId))->createByStaff($user, $input, $resolve, $request);
        return $this->presented((int) $out['id']);
    }

    // ================================================================== estado y atención

    /**
     * confirmar | iniciar | completar | no_asistio. Toma el candado de la reserva (y de su barbero)
     * y valida el estado dentro de la transacción.
     *
     * @return array<string, mixed>
     */
    public function transition(int $id, string $accion, ?Request $request = null): array
    {
        $this->visible($id);
        $scope = $this->ownBarberScope();
        $engine = new BookingService($this->tenantId);
        $bookings = $engine->repo();

        Database::transaction(function () use ($id, $accion, $scope, $engine, $bookings, $request): void {
            $r = $engine->lockBooking($id);
            if ($scope !== null && (int) $r['barbero_id'] !== $scope) {
                throw new NotFoundException('Cita no encontrada.');
            }
            $full = (array) $this->repo->find($id);
            $now = Clock::nowUtc()->getTimestamp();
            $ini = Availability::ts((string) $r['inicio_utc']);
            $fin = Availability::ts((string) $r['fin_utc']);
            $activa = in_array($r['estado'], ['pendiente', 'confirmada'], true);
            $reciente = $ini >= $now - PanelBookingPresenter::DIAS_CORRECCION * 86400;
            $temprano = $now < $ini - PanelBookingPresenter::ATENCION_ANTICIPADA_MIN * 60;
            $nowSql = Clock::nowSql();
            $ip = $request !== null ? Ip::toBinary($request->ip()) : null;
            $event = ['actor_tipo' => 'personal', 'actor_usuario_id' => $this->userId, 'ip' => $ip, 'estado_anterior' => $r['estado']];
            $changes = [];
            $correccion = false;

            switch ($accion) {
                case 'confirmar':
                    if ($r['estado'] === 'confirmada') {
                        return;
                    }
                    if ($r['estado'] !== 'pendiente' || $fin <= $now) {
                        throw new HttpException(409, 'booking_not_active', 'Solo se confirma una cita pendiente que aún no termina.');
                    }
                    $changes = ['estado' => 'confirmada', 'confirmada_en' => $nowSql];
                    $event += ['tipo' => 'confirmada', 'estado_nuevo' => 'confirmada'];
                    break;

                case 'iniciar':
                    if ($full['atencion_iniciada_en'] !== null && $activa) {
                        return;
                    }
                    if (!$activa) {
                        throw new HttpException(409, 'booking_not_active', 'La cita no está activa.');
                    }
                    if ($temprano) {
                        throw new HttpException(422, 'too_early', 'Aún falta para esta cita: puedes iniciar la atención desde ' . PanelBookingPresenter::ATENCION_ANTICIPADA_MIN . ' minutos antes.');
                    }
                    $changes = ['atencion_iniciada_en' => $nowSql] + ($r['estado'] === 'pendiente' ? ['estado' => 'confirmada', 'confirmada_en' => $nowSql] : []);
                    $event += ['tipo' => 'atencion_iniciada', 'estado_nuevo' => 'confirmada'];
                    break;

                case 'completar':
                    if ($r['estado'] === 'completada') {
                        return;
                    }
                    if ($activa) {
                        if ($temprano) {
                            throw new HttpException(422, 'too_early', 'Aún falta para esta cita: no se puede marcar como atendida.');
                        }
                    } elseif ($r['estado'] === 'no_asistio' && $reciente) {
                        $correccion = true;
                    } else {
                        throw new HttpException(409, 'booking_not_active', 'Esta cita no se puede marcar como atendida.');
                    }
                    $changes = [
                        'estado' => 'completada',
                        'atencion_iniciada_en' => $full['atencion_iniciada_en'] ?? ($correccion ? $r['inicio_utc'] : min($nowSql, (string) $r['inicio_utc'])),
                        'atencion_finalizada_en' => $correccion ? $r['fin_utc'] : $nowSql,
                    ];
                    if ($full['confirmada_en'] === null) {
                        $changes['confirmada_en'] = $nowSql;
                    }
                    $event += ['tipo' => 'completada', 'estado_nuevo' => 'completada', 'datos' => $correccion ? ['correccion' => true] : null];
                    break;

                case 'no_asistio':
                    if ($r['estado'] === 'no_asistio') {
                        return;
                    }
                    if ($activa) {
                        if ($now < $ini) {
                            throw new HttpException(422, 'too_early', 'La inasistencia se registra cuando ya pasó la hora de la cita.');
                        }
                        if ($full['atencion_iniciada_en'] !== null) {
                            throw new HttpException(409, 'attention_started', 'La atención ya se inició: marca la cita como atendida.');
                        }
                    } elseif ($r['estado'] === 'completada' && $reciente) {
                        if ((float) $this->repo->paidTotal($id) > 0) {
                            throw new HttpException(409, 'booking_has_payments', 'La cita tiene cobros registrados. Anúlalos antes de marcar la inasistencia.');
                        }
                        $correccion = true;
                    } else {
                        throw new HttpException(409, 'booking_not_active', 'Esta cita no se puede marcar como inasistencia.');
                    }
                    $changes = ['estado' => 'no_asistio', 'atencion_iniciada_en' => null, 'atencion_finalizada_en' => null];
                    $event += ['tipo' => 'no_asistio', 'estado_nuevo' => 'no_asistio', 'datos' => $correccion ? ['correccion' => true] : null];
                    break;

                default:
                    throw new NotFoundException('Acción no encontrada.');
            }

            if (!$bookings->update($id, (int) $r['version'], $changes)) {
                throw new HttpException(409, 'booking_changed', 'La cita cambió mientras la procesábamos. Actualiza e intenta de nuevo.');
            }
            $bookings->addEvent($id, $event);
            if (in_array($accion, ['completar', 'no_asistio'], true)) {
                $this->repo->recomputeClientStats((int) $r['cliente_id']);
                // La cita deja de ocupar la agenda: los recordatorios pendientes ya no aplican.
                (new BookingNotifier($this->tenantId))->cancelPending($id);
            }
            if ($accion === 'confirmar') {
                (new BookingNotifier($this->tenantId))->notify((array) $bookings->find($id), 'confirmada', 'personal');
            }
            $auditAction = ['confirmar' => 'reserva.confirmada', 'iniciar' => 'reserva.atencion_iniciada', 'completar' => 'reserva.completada', 'no_asistio' => 'reserva.no_asistio'][$accion];
            Audit::record($auditAction, 'exito', $request, $this->userId, $this->tenantId, 'reservas', $id,
                ['estado' => $changes['estado'] ?? $r['estado'], 'correccion' => $correccion], ['estado' => $r['estado']]);
        }, Database::READ_COMMITTED);

        return $this->presented($id, true);
    }

    // ================================================================== notas internas

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function notes(int $id, array $input, ?Request $request = null): array
    {
        $this->visible($id);
        $data = Validator::validate($input, ['notas_internas' => 'nullable|string|max:1000']);
        $notas = isset($data['notas_internas']) && trim((string) $data['notas_internas']) !== '' ? trim((string) $data['notas_internas']) : null;
        Database::transaction(function () use ($id, $notas, $request): void {
            $this->repo->updateNotes($id, $notas);
            (new BookingRepository($this->tenantId))->addEvent($id, [
                'tipo' => 'nota', 'actor_tipo' => 'personal', 'actor_usuario_id' => $this->userId,
                'ip' => $request !== null ? Ip::toBinary($request->ip()) : null,
            ]);
        });
        return $this->presented($id, true);
    }

    // ================================================================== cobros

    public const METODOS_PAGO = ['efectivo', 'tarjeta', 'yape', 'plin', 'transferencia', 'otro'];

    /**
     * Registra un cobro. Si el total cobrado superaría el importe de la cita pide confirmarlo
     * (evita errores de digitación como 400 en lugar de 40.00).
     *
     * @param array<string, mixed> $input monto, metodo, referencia, confirmar_excedente
     * @return array<string, mixed>
     */
    public function pay(int $id, array $input, ?Request $request = null): array
    {
        $this->visible($id);
        $data = Validator::validate($input, [
            'monto' => 'required|decimal|min:0.01|max:99999.99',
            'metodo' => 'required|in:' . implode(',', self::METODOS_PAGO),
            'referencia' => 'nullable|string|max:100',
            'confirmar_excedente' => 'nullable|boolean',
        ]);
        $monto = number_format((float) $data['monto'], 2, '.', '');
        if ((float) $monto <= 0) {
            throw new ValidationException(['monto' => ['El monto debe ser mayor que cero.']]);
        }
        $bookings = new BookingRepository($this->tenantId);
        Database::transaction(function () use ($id, $data, $monto, $bookings, $request): void {
            $r = $bookings->lock($id);
            if ($r === null) {
                throw new NotFoundException('Cita no encontrada.');
            }
            if (!in_array($r['estado'], ['pendiente', 'confirmada', 'completada'], true)) {
                throw new HttpException(409, 'booking_not_payable', 'No se registran cobros de citas canceladas o con inasistencia.');
            }
            $full = (array) $this->repo->find($id);
            $pagado = (float) $this->repo->paidTotal($id);
            if (round($pagado + (float) $monto, 2) > (float) $full['precio_total'] && empty($data['confirmar_excedente'])) {
                throw new HttpException(422, 'payment_exceeds_total', 'El cobro supera el importe de la cita (S/ ' . $full['precio_total'] . '; ya cobrado S/ '
                    . number_format($pagado, 2, '.', '') . '). Si es correcto (propina o venta adicional), confírmalo.', ['precio_total' => $full['precio_total'], 'pagado' => number_format($pagado, 2, '.', '')]);
            }
            $ref = isset($data['referencia']) && trim((string) $data['referencia']) !== '' ? trim((string) $data['referencia']) : null;
            $pagoId = $this->repo->addPayment($id, [
                'monto' => $monto, 'moneda' => (string) $full['moneda'], 'metodo' => $data['metodo'], 'referencia' => $ref,
                'pagado_en' => Clock::nowSql(), 'registrado_por' => $this->userId,
            ]);
            $bookings->addEvent($id, [
                'tipo' => 'pago_registrado', 'actor_tipo' => 'personal', 'actor_usuario_id' => $this->userId,
                'ip' => $request !== null ? Ip::toBinary($request->ip()) : null, 'datos' => ['pago_id' => $pagoId, 'monto' => $monto, 'metodo' => $data['metodo']],
            ]);
            Audit::record('reserva.cobro_registrado', 'exito', $request, $this->userId, $this->tenantId, 'reserva_pagos', $pagoId, ['reserva_id' => $id, 'monto' => $monto, 'metodo' => $data['metodo']]);
        }, Database::READ_COMMITTED);
        return $this->presented($id, true);
    }

    /** @param array<string, mixed> $input motivo @return array<string, mixed> */
    public function voidPayment(int $id, int $pagoId, array $input, ?Request $request = null): array
    {
        $this->visible($id);
        $data = Validator::validate($input, ['motivo' => 'required|string|min:3|max:255']);
        $motivo = trim((string) $data['motivo']);
        $bookings = new BookingRepository($this->tenantId);
        Database::transaction(function () use ($id, $pagoId, $motivo, $bookings, $request): void {
            $bookings->lock($id);
            $p = $this->repo->payment($id, $pagoId);
            if ($p === null) {
                throw new NotFoundException('Cobro no encontrado.');
            }
            if ($p['anulado_en'] !== null) {
                return;
            }
            $this->repo->voidPayment($pagoId, $this->userId, $motivo, Clock::nowSql());
            $bookings->addEvent($id, [
                'tipo' => 'pago_anulado', 'actor_tipo' => 'personal', 'actor_usuario_id' => $this->userId,
                'ip' => $request !== null ? Ip::toBinary($request->ip()) : null, 'datos' => ['pago_id' => $pagoId, 'monto' => (string) $p['monto'], 'motivo' => $motivo],
            ]);
            Audit::record('reserva.cobro_anulado', 'exito', $request, $this->userId, $this->tenantId, 'reserva_pagos', $pagoId, ['reserva_id' => $id, 'motivo' => $motivo]);
        }, Database::READ_COMMITTED);
        return $this->presented($id, true);
    }
}
