<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Helpers\Clock;
use App\Helpers\Codes;
use App\Helpers\Ip;
use App\Helpers\Validator;
use App\Repositories\BarberRepository;
use App\Repositories\BookingRepository;
use App\Repositories\ClientRepository;
use App\Services\Audit;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;

/**
 * Motor de reservas: crear, cancelar, reprogramar y confirmar asistencia.
 *
 * Prevención de dobles reservas (en este orden, dentro de una transacción READ COMMITTED):
 *   1. Bloquea el vínculo cliente–barbería (límite de citas activas, sin carreras del mismo cliente).
 *   2. Bloquea la fila de cada barbero candidato en orden de id (SELECT … FOR UPDATE).
 *      Es el mismo candado que usan los cambios de jornada y los bloqueos de agenda (Fase 4).
 *   3. Con los candados tomados, recalcula la disponibilidad con datos frescos y verifica que el
 *      horario siga libre (jornada, descansos, bloqueos, reservas activas ± margen).
 *   4. Escribe la reserva, sus servicios consecutivos, el evento y los avisos, y confirma.
 * Una segunda transacción que pida el mismo barbero espera en el paso 2 y, al continuar, ve la
 * reserva recién confirmada: recibe 409 slot_unavailable. Cancelar libera el intervalo al confirmar.
 */
final class BookingService
{
    /** Solo pruebas de concurrencia: se ejecuta tras validar y antes de escribir (con los candados tomados). */
    public static ?\Closure $beforeWrite = null;

    public const CANALES = ['marketplace', 'enlace', 'qr', 'app'];

    private BookingRepository $repo;
    private AvailabilityService $availability;
    private BarberRepository $barbers;

    public function __construct(private int $tenantId)
    {
        $this->repo = new BookingRepository($tenantId);
        $this->availability = new AvailabilityService($tenantId);
        $this->barbers = new BarberRepository($tenantId);
    }

    public function repo(): BookingRepository
    {
        return $this->repo;
    }

    // ================================================================== crear

    /**
     * @param array<string, mixed> $user  usuario autenticado (auth_user)
     * @param array<string, mixed> $input sucursal_id, servicio_ids, barbero_id|null, fecha, hora, notas, canal
     * @return array<string, mixed> comprobante
     */
    public function create(array $user, array $input, ?Request $request = null): array
    {
        $data = Validator::validate($input, [
            'sucursal_id' => 'required|integer|min:1',
            'barbero_id' => 'nullable|integer|min:1',
            'fecha' => 'required|date',
            'hora' => 'required|time',
            'notas' => 'nullable|string|max:500',
            'canal' => 'nullable|in:' . implode(',', self::CANALES),
        ]);
        $barberoId = isset($data['barbero_id']) ? (int) $data['barbero_id'] : null;
        $canal = $data['canal'] ?? 'marketplace';
        $notas = isset($data['notas']) && trim((string) $data['notas']) !== '' ? trim((string) $data['notas']) : null;

        $ctx = $this->availability->context((int) $data['sucursal_id'], $input['servicio_ids'] ?? null, $barberoId);
        $start = $this->startTs($ctx, (string) $data['fecha'], (string) $data['hora']);
        $this->assertWithinPolicy($ctx['policy'], $start, (string) $data['fecha'], (string) $ctx['branch']['zona_horaria']);
        $serviceIds = array_map(static fn (array $s): int => (int) $s['id'], $ctx['services']);
        $userId = (int) $user['id'];

        $id = Database::transaction(function () use ($user, $userId, $ctx, $serviceIds, $barberoId, $start, $data, $canal, $notas, $request): int {
            $clients = new ClientRepository();
            $cliente = $clients->ensureForUser($user);
            if ($cliente['vinculado_mostrador']) {
                Audit::record('cliente.vinculado_mostrador', 'exito', $request, $userId, $this->tenantId, 'clientes', $cliente['id']);
            }
            $clienteId = $cliente['id'];
            $link = $clients->lockTenantLink($this->tenantId, $clienteId, $canal);
            if (!empty($link['bloqueado'])) {
                throw new HttpException(403, 'client_blocked', 'Esta barbería no admite nuevas reservas en línea desde tu cuenta. Comunícate directamente con ella.');
            }
            $policy = $ctx['policy'];
            if ($this->repo->countActiveForClient($clienteId, Clock::nowSql()) >= $policy->maxActivasCliente) {
                throw new HttpException(422, 'active_bookings_limit', "Ya tienes {$policy->maxActivasCliente} citas activas en esta barbería. Cancela o espera a completar una antes de reservar otra.");
            }

            // Candado de agenda de todos los candidatos; luego datos frescos.
            $this->barbers->lockMany(array_keys($ctx['candidates']));
            $locked = array_keys($ctx['candidates']);
            $fresh = $this->availability->context((int) $ctx['branch']['id'], $serviceIds, $barberoId);
            $fresh['candidates'] = array_intersect_key($fresh['candidates'], array_flip($locked));
            [$chosen, $alternativas] = $this->pick($fresh, (string) $data['fecha'], $start, null, null);
            if ($chosen === null) {
                throw new HttpException(409, 'slot_unavailable', 'Ese horario acaba de ser tomado o ya no está disponible. Elige otro.', ['alternativas' => $alternativas]);
            }
            $cand = $fresh['candidates'][$chosen];
            $segments = $this->segments($cand, $fresh['services'], $start);
            $end = $segments[count($segments) - 1]['fin_ts'];
            $this->assertClientFree($clients, $clienteId, $start, $end, null);

            if (self::$beforeWrite !== null) {
                (self::$beforeWrite)();
            }

            $now = Clock::nowSql();
            $estado = $policy->confirmacionAutomatica ? 'confirmada' : 'pendiente';
            $id = $this->insertWithCode([
                'sucursal_id' => (int) $fresh['branch']['id'],
                'barbero_id' => $chosen,
                'cliente_id' => $clienteId,
                'estado' => $estado,
                'inicio_utc' => gmdate('Y-m-d H:i:s', $start),
                'fin_utc' => gmdate('Y-m-d H:i:s', $end),
                'zona_horaria' => (string) $fresh['branch']['zona_horaria'],
                'duracion_total_minutos' => intdiv($end - $start, 60),
                'precio_total' => AvailabilityService::price($cand, $fresh['services']),
                'moneda' => (string) ($fresh['services'][0]['moneda'] ?? 'PEN'),
                'canal' => $canal,
                'barbero_asignado_auto' => $barberoId === null,
                'notas_cliente' => $notas,
                'confirmada_en' => $estado === 'confirmada' ? $now : null,
                'creado_por' => $userId,
            ]);
            $this->repo->replaceServices($id, $this->serviceRows($segments));
            $ip = $request !== null ? Ip::toBinary($request->ip()) : null;
            $this->repo->addEvent($id, [
                'tipo' => 'creada', 'estado_nuevo' => 'pendiente', 'inicio_nuevo_utc' => gmdate('Y-m-d H:i:s', $start), 'fin_nuevo_utc' => gmdate('Y-m-d H:i:s', $end),
                'barbero_nuevo_id' => $chosen, 'actor_tipo' => 'cliente', 'actor_usuario_id' => $userId, 'ip' => $ip,
                'datos' => ['canal' => $canal, 'servicios' => $serviceIds, 'barbero_asignado_auto' => $barberoId === null],
            ]);
            if ($estado === 'confirmada') {
                $this->repo->addEvent($id, ['tipo' => 'confirmada', 'estado_anterior' => 'pendiente', 'estado_nuevo' => 'confirmada', 'actor_tipo' => 'sistema']);
            }
            $this->repo->incrementClientBookings($clienteId);
            $row = (array) $this->repo->find($id);
            (new BookingNotifier($this->tenantId))->notify($row, 'creada', 'cliente');
            Audit::record('reserva.creada', 'exito', $request, $userId, $this->tenantId, 'reservas', $id, [
                'codigo' => $row['codigo'], 'barbero_id' => $chosen, 'inicio_utc' => $row['inicio_utc'], 'canal' => $canal,
            ]);
            return $id;
        }, Database::READ_COMMITTED);

        return $this->presented($id);
    }

    // ================================================================== crear desde el panel

    /**
     * Reserva registrada por el personal (mostrador, teléfono, WhatsApp). Mismo motor y mismos
     * candados que la reserva en línea; cambia solo quién la hace:
     *   - el cliente es uno ya vinculado a la barbería o uno nuevo de mostrador (sin cuenta);
     *   - se puede elegir cualquier barbero activo, acepte o no reservas en línea;
     *   - sin anticipación mínima ni horizonte corto (BookingPolicy::forStaff);
     *   - no aplica el tope de citas activas por cliente ni el bloqueo de reservas en línea.
     * Nunca permite cruzar dos citas del mismo barbero ni del mismo cliente.
     *
     * @param array<string, mixed> $staff usuario autenticado
     * @param array<string, mixed> $input sucursal_id, servicio_ids, barbero_id|null, fecha, hora, cliente_id | cliente{…}, notas, notas_internas, estado
     * @param callable(): int $resolveClient devuelve el cliente_id ya vinculado y bloqueado (se ejecuta dentro de la transacción)
     * @return array<string, mixed>
     */
    public function createByStaff(array $staff, array $input, callable $resolveClient, ?Request $request = null): array
    {
        $data = Validator::validate($input, [
            'sucursal_id' => 'required|integer|min:1',
            'barbero_id' => 'nullable|integer|min:1',
            'fecha' => 'required|date',
            'hora' => 'required|time',
            'notas' => 'nullable|string|max:500',
            'notas_internas' => 'nullable|string|max:1000',
            'estado' => 'nullable|in:pendiente,confirmada',
        ]);
        $barberoId = isset($data['barbero_id']) ? (int) $data['barbero_id'] : null;
        $notas = isset($data['notas']) && trim((string) $data['notas']) !== '' ? trim((string) $data['notas']) : null;
        $internas = isset($data['notas_internas']) && trim((string) $data['notas_internas']) !== '' ? trim((string) $data['notas_internas']) : null;
        $estado = $data['estado'] ?? 'confirmada';

        $ctx = $this->staffAware($this->availability->context((int) $data['sucursal_id'], $input['servicio_ids'] ?? null, $barberoId, false), 'personal');
        $start = $this->startTs($ctx, (string) $data['fecha'], (string) $data['hora']);
        $this->assertWithinPolicy($ctx['policy'], $start, (string) $data['fecha'], (string) $ctx['branch']['zona_horaria']);
        $serviceIds = array_map(static fn (array $s): int => (int) $s['id'], $ctx['services']);
        $userId = (int) $staff['id'];

        $id = Database::transaction(function () use ($userId, $ctx, $serviceIds, $barberoId, $start, $data, $notas, $internas, $estado, $resolveClient, $request): int {
            $clienteId = $resolveClient();

            $this->barbers->lockMany(array_keys($ctx['candidates']));
            $locked = array_keys($ctx['candidates']);
            $fresh = $this->staffAware($this->availability->context((int) $ctx['branch']['id'], $serviceIds, $barberoId, false), 'personal');
            $fresh['candidates'] = array_intersect_key($fresh['candidates'], array_flip($locked));
            [$chosen, $alternativas] = $this->pick($fresh, (string) $data['fecha'], $start, null, null);
            if ($chosen === null) {
                throw new HttpException(409, 'slot_unavailable', 'Ese horario no está libre en la agenda. Elige otro.', ['alternativas' => $alternativas]);
            }
            $cand = $fresh['candidates'][$chosen];
            $segments = $this->segments($cand, $fresh['services'], $start);
            $end = $segments[count($segments) - 1]['fin_ts'];
            $this->assertClientFree(new ClientRepository(), $clienteId, $start, $end, null, true);

            if (self::$beforeWrite !== null) {
                (self::$beforeWrite)();
            }

            $now = Clock::nowSql();
            $id = $this->insertWithCode([
                'sucursal_id' => (int) $fresh['branch']['id'],
                'barbero_id' => $chosen,
                'cliente_id' => $clienteId,
                'estado' => $estado,
                'inicio_utc' => gmdate('Y-m-d H:i:s', $start),
                'fin_utc' => gmdate('Y-m-d H:i:s', $end),
                'zona_horaria' => (string) $fresh['branch']['zona_horaria'],
                'duracion_total_minutos' => intdiv($end - $start, 60),
                'precio_total' => AvailabilityService::price($cand, $fresh['services']),
                'moneda' => (string) ($fresh['services'][0]['moneda'] ?? 'PEN'),
                'canal' => 'panel',
                'barbero_asignado_auto' => $barberoId === null,
                'notas_cliente' => $notas,
                'notas_internas' => $internas,
                'confirmada_en' => $estado === 'confirmada' ? $now : null,
                'creado_por' => $userId,
            ]);
            $this->repo->replaceServices($id, $this->serviceRows($segments));
            $ip = $request !== null ? Ip::toBinary($request->ip()) : null;
            $this->repo->addEvent($id, [
                'tipo' => 'creada', 'estado_nuevo' => 'pendiente', 'inicio_nuevo_utc' => gmdate('Y-m-d H:i:s', $start), 'fin_nuevo_utc' => gmdate('Y-m-d H:i:s', $end),
                'barbero_nuevo_id' => $chosen, 'actor_tipo' => 'personal', 'actor_usuario_id' => $userId, 'ip' => $ip,
                'datos' => ['canal' => 'panel', 'servicios' => $serviceIds, 'barbero_asignado_auto' => $barberoId === null],
            ]);
            if ($estado === 'confirmada') {
                $this->repo->addEvent($id, ['tipo' => 'confirmada', 'estado_anterior' => 'pendiente', 'estado_nuevo' => 'confirmada', 'actor_tipo' => 'personal', 'actor_usuario_id' => $userId]);
            }
            $this->repo->incrementClientBookings($clienteId);
            $row = (array) $this->repo->find($id);
            (new BookingNotifier($this->tenantId))->notify($row, 'creada', 'personal');
            Audit::record('reserva.creada', 'exito', $request, $userId, $this->tenantId, 'reservas', $id, [
                'codigo' => $row['codigo'], 'barbero_id' => $chosen, 'inicio_utc' => $row['inicio_utc'], 'canal' => 'panel', 'cliente_id' => $clienteId,
            ]);
            return $id;
        }, Database::READ_COMMITTED);

        return $this->presented($id);
    }

    // ================================================================== cancelar

    /**
     * @param array<string, mixed> $input motivo (opcional)
     * @return array<string, mixed>
     */
    public function cancel(int $id, array $user, array $input, string $actor = 'cliente', ?Request $request = null): array
    {
        $data = Validator::validate($input, ['motivo' => 'nullable|string|max:255']);
        $motivo = isset($data['motivo']) && trim((string) $data['motivo']) !== '' ? trim((string) $data['motivo']) : null;
        $userId = (int) $user['id'];

        Database::transaction(function () use ($id, $userId, $motivo, $actor, $request): void {
            $r = $this->lockBooking($id);
            if ($r['estado'] === 'cancelada') {
                return; // idempotente: un reintento no falla
            }
            if (!in_array($r['estado'], ['pendiente', 'confirmada'], true)) {
                throw new HttpException(409, 'booking_not_active', 'Esta cita ya no está activa y no se puede cancelar.');
            }
            $policy = $this->availability->policy((int) $r['sucursal_id']);
            $ini = Availability::ts((string) $r['inicio_utc']);
            if ($actor === 'cliente') {
                if ($ini <= Clock::nowUtc()->getTimestamp()) {
                    throw new HttpException(409, 'booking_started', 'La cita ya comenzó o pasó: no se puede cancelar en línea.');
                }
                if (Clock::nowUtc()->getTimestamp() > $ini - $policy->cancelacionLimite * 60) {
                    throw new HttpException(422, 'cancellation_window_closed', 'Ya no puedes cancelar en línea: el plazo vence ' . self::minutesText($policy->cancelacionLimite)
                        . ' antes de la cita. Comunícate con la barbería.', ['texto_politica' => $policy->texto]);
                }
            }
            $now = Clock::nowSql();
            if (!$this->repo->update($id, (int) $r['version'], [
                'estado' => 'cancelada', 'cancelada_en' => $now, 'cancelada_por' => $userId, 'cancelada_por_tipo' => $actor, 'motivo_cancelacion' => $motivo,
            ])) {
                throw new HttpException(409, 'booking_changed', 'La cita cambió mientras la procesábamos. Actualiza e intenta de nuevo.');
            }
            $this->repo->addEvent($id, [
                'tipo' => 'cancelada', 'estado_anterior' => $r['estado'], 'estado_nuevo' => 'cancelada',
                'inicio_anterior_utc' => $r['inicio_utc'], 'fin_anterior_utc' => $r['fin_utc'], 'barbero_anterior_id' => (int) $r['barbero_id'],
                'actor_tipo' => $actor, 'actor_usuario_id' => $userId, 'ip' => $request !== null ? Ip::toBinary($request->ip()) : null,
                'datos' => $motivo !== null ? ['motivo' => $motivo] : null,
            ]);
            $notifier = new BookingNotifier($this->tenantId);
            $anulados = $notifier->cancelPending($id);
            $notifier->notify((array) $this->repo->find($id), 'cancelada', $actor);
            Audit::record('reserva.cancelada', 'exito', $request, $userId, $this->tenantId, 'reservas', $id, [
                'actor' => $actor, 'motivo' => $motivo, 'recordatorios_anulados' => $anulados,
            ], ['estado' => $r['estado'], 'inicio_utc' => $r['inicio_utc']]);
        }, Database::READ_COMMITTED);

        return $this->presented($id);
    }

    // ================================================================== reprogramar

    /**
     * Mueve la cita a otra fecha/hora (y, si el barbero fue asignado automáticamente, quizá a otro barbero).
     * El intervalo anterior sigue ocupado hasta que el cambio se confirma: todo ocurre en una transacción.
     *
     * @param array<string, mixed> $input fecha, hora, barbero_id (opcional)
     * @return array<string, mixed>
     */
    public function reschedule(int $id, array $user, array $input, string $actor = 'cliente', ?Request $request = null): array
    {
        $data = Validator::validate($input, ['fecha' => 'required|date', 'hora' => 'required|time', 'barbero_id' => 'nullable|integer|min:1']);
        $userId = (int) $user['id'];
        $current = $this->repo->find($id);
        if ($current === null) {
            throw new NotFoundException('Reserva no encontrada.');
        }
        if ($current['tenant_estado'] !== 'activo') {
            throw new HttpException(409, 'tenant_unavailable', 'La barbería no está recibiendo reservas en este momento. Puedes cancelar tu cita o comunicarte con ella.');
        }
        $auto = (bool) $current['barbero_asignado_auto'];
        $pedido = isset($data['barbero_id']) ? (int) $data['barbero_id'] : null;
        if ($pedido !== null && $pedido !== (int) $current['barbero_id'] && !$auto && $actor === 'cliente') {
            throw new ValidationException(['barbero_id' => ['Para cambiar de barbero cancela esta cita y reserva una nueva.']]);
        }
        // Cliente con barbero elegido: se mantiene. Con "cualquiera": el motor puede reasignar.
        $pool = $pedido ?? ($auto && $this->availability->policy((int) $current['sucursal_id'])->permiteCualquierBarbero ? null : (int) $current['barbero_id']);
        $serviceIds = array_map(static fn (array $s): int => (int) $s['servicio_id'], $this->repo->services($id));
        $ctx = $this->staffAware($this->availability->context((int) $current['sucursal_id'], $serviceIds, $pool, $actor === 'cliente'), $actor);
        $start = $this->startTs($ctx, (string) $data['fecha'], (string) $data['hora']);

        Database::transaction(function () use ($id, $userId, $ctx, $serviceIds, $pool, $start, $data, $actor, $request): void {
            $locked = array_values(array_unique(array_merge(array_keys($ctx['candidates']), [(int) $this->repo->find($id)['barbero_id']])));
            $this->barbers->lockMany($locked);
            $r = $this->repo->lock($id);
            if ($r === null) {
                throw new NotFoundException('Reserva no encontrada.');
            }
            if (!in_array((int) $r['barbero_id'], $locked, true)) {
                throw new HttpException(409, 'booking_changed', 'La cita cambió mientras la procesábamos. Actualiza e intenta de nuevo.');
            }
            if (!in_array($r['estado'], ['pendiente', 'confirmada'], true)) {
                throw new HttpException(409, 'booking_not_active', 'Esta cita ya no está activa y no se puede reprogramar.');
            }
            $policy = $ctx['policy'];
            $ini = Availability::ts((string) $r['inicio_utc']);
            if ($actor === 'cliente') {
                if (Clock::nowUtc()->getTimestamp() > $ini - $policy->reprogramacionLimite * 60) {
                    throw new HttpException(422, 'reschedule_window_closed', 'Ya no puedes reprogramar en línea: el plazo vence ' . self::minutesText($policy->reprogramacionLimite)
                        . ' antes de la cita. Comunícate con la barbería.', ['texto_politica' => $policy->texto]);
                }
                if ((int) $r['reprogramaciones'] >= $policy->maxReprogramaciones) {
                    throw new HttpException(422, 'reschedule_limit', "Esta cita ya se reprogramó {$r['reprogramaciones']} " . ((int) $r['reprogramaciones'] === 1 ? 'vez' : 'veces')
                        . '. Para otro cambio cancela y reserva de nuevo, o comunícate con la barbería.');
                }
            }
            if ($start === $ini) {
                throw new HttpException(422, 'same_slot', 'Elige una fecha u hora distinta a la actual.');
            }
            $this->assertWithinPolicy($policy, $start, (string) $data['fecha'], (string) $ctx['branch']['zona_horaria']);

            $fresh = $this->staffAware($this->availability->context((int) $r['sucursal_id'], $serviceIds, $pool, $actor === 'cliente'), $actor);
            $fresh['candidates'] = array_intersect_key($fresh['candidates'], array_flip($locked));
            [$chosen, $alternativas] = $this->pick($fresh, (string) $data['fecha'], $start, $id, (int) $r['barbero_id']);
            if ($chosen === null) {
                throw new HttpException(409, 'slot_unavailable', 'Ese horario ya no está disponible. Elige otro; tu cita actual se mantiene.', ['alternativas' => $alternativas]);
            }
            $cand = $fresh['candidates'][$chosen];
            $segments = $this->segments($cand, $fresh['services'], $start);
            $mismoBarbero = $chosen === (int) $r['barbero_id'];
            if ($mismoBarbero) {
                // Se respetan los precios pactados; la duración es la vigente del barbero.
                $prev = [];
                foreach ($this->repo->services($id) as $s) {
                    $prev[(int) $s['servicio_id']] = (string) $s['precio'];
                }
                foreach ($segments as &$seg) {
                    $seg['precio'] = $prev[$seg['servicio_id']] ?? $seg['precio'];
                }
                unset($seg);
            }
            $end = $segments[count($segments) - 1]['fin_ts'];
            $this->assertClientFree(new ClientRepository(), (int) $r['cliente_id'], $start, $end, $id, $actor === 'personal');

            if (self::$beforeWrite !== null) {
                (self::$beforeWrite)();
            }

            $cents = array_sum(array_map(static fn (array $s): int => (int) round((float) $s['precio'] * 100), $segments));
            $nuevo = ['inicio_utc' => gmdate('Y-m-d H:i:s', $start), 'fin_utc' => gmdate('Y-m-d H:i:s', $end)];
            if (!$this->repo->update($id, (int) $r['version'], $nuevo + [
                'barbero_id' => $chosen,
                'duracion_total_minutos' => intdiv($end - $start, 60),
                'precio_total' => number_format($cents / 100, 2, '.', ''),
                'reprogramaciones' => (int) $r['reprogramaciones'] + 1,
                'asistencia_confirmada_en' => null,
            ])) {
                throw new HttpException(409, 'booking_changed', 'La cita cambió mientras la procesábamos. Actualiza e intenta de nuevo.');
            }
            $this->repo->replaceServices($id, $this->serviceRows($segments));
            $this->repo->addEvent($id, [
                'tipo' => 'reprogramada', 'estado_anterior' => $r['estado'], 'estado_nuevo' => $r['estado'],
                'inicio_anterior_utc' => $r['inicio_utc'], 'fin_anterior_utc' => $r['fin_utc'],
                'inicio_nuevo_utc' => $nuevo['inicio_utc'], 'fin_nuevo_utc' => $nuevo['fin_utc'],
                'barbero_anterior_id' => (int) $r['barbero_id'], 'barbero_nuevo_id' => $chosen,
                'actor_tipo' => $actor, 'actor_usuario_id' => $userId, 'ip' => $request !== null ? Ip::toBinary($request->ip()) : null,
            ]);
            $notifier = new BookingNotifier($this->tenantId);
            $notifier->cancelPending($id);
            $notifier->notify((array) $this->repo->find($id), 'reprogramada', $actor, (int) $r['reprogramaciones'] + 1);
            Audit::record('reserva.reprogramada', 'exito', $request, $userId, $this->tenantId, 'reservas', $id,
                $nuevo + ['barbero_id' => $chosen, 'actor' => $actor],
                ['inicio_utc' => $r['inicio_utc'], 'fin_utc' => $r['fin_utc'], 'barbero_id' => (int) $r['barbero_id']]);
        }, Database::READ_COMMITTED);

        return $this->presented($id);
    }

    // ================================================================== confirmar asistencia

    /** @return array<string, mixed> */
    public function confirmAttendance(int $id, array $user, ?Request $request = null): array
    {
        $userId = (int) $user['id'];
        Database::transaction(function () use ($id, $userId, $request): void {
            $r = $this->repo->lock($id);
            if ($r === null) {
                throw new NotFoundException('Reserva no encontrada.');
            }
            $full = (array) $this->repo->find($id);
            if (!in_array($r['estado'], ['pendiente', 'confirmada'], true) || Availability::ts((string) $r['inicio_utc']) <= Clock::nowUtc()->getTimestamp()) {
                throw new HttpException(409, 'booking_not_active', 'Solo puedes confirmar tu asistencia a una cita próxima y activa.');
            }
            if ($full['asistencia_confirmada_en'] !== null) {
                return; // ya confirmada: idempotente
            }
            $this->repo->update($id, (int) $r['version'], ['asistencia_confirmada_en' => Clock::nowSql()]);
            $this->repo->addEvent($id, [
                'tipo' => 'asistencia_confirmada', 'estado_anterior' => $r['estado'], 'estado_nuevo' => $r['estado'],
                'actor_tipo' => 'cliente', 'actor_usuario_id' => $userId, 'ip' => $request !== null ? Ip::toBinary($request->ip()) : null,
            ]);
            (new BookingNotifier($this->tenantId))->notify((array) $this->repo->find($id), 'asistencia_confirmada', 'cliente');
        }, Database::READ_COMMITTED);
        return $this->presented($id);
    }

    // ================================================================== consultas

    /** @return array<string, mixed> */
    public function presented(int $id, bool $withHistory = false): array
    {
        $row = $this->repo->find($id);
        if ($row === null) {
            throw new NotFoundException('Reserva no encontrada.');
        }
        $out = BookingPresenter::present($row, $this->repo->services($id), $this->availability->policy((int) $row['sucursal_id']));
        if ($withHistory) {
            $events = $this->repo->events($id);
            $names = $this->repo->barberNames(array_merge(array_column($events, 'barbero_anterior_id'), array_column($events, 'barbero_nuevo_id')));
            $out['historial'] = BookingPresenter::history($events, (string) $row['zona_horaria'], $names);
        }
        return $out;
    }

    // ================================================================== internos

    /**
     * El personal agenda sin anticipación mínima ni horizonte corto (atiende en mostrador o por
     * teléfono); la jornada, los bloqueos, el margen y la grilla se respetan igual que en línea.
     *
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function staffAware(array $ctx, string $actor): array
    {
        if ($actor === 'personal') {
            $ctx['policy'] = $ctx['policy']->forStaff();
        }
        return $ctx;
    }

    /**
     * Elige el barbero libre en $start: el actual (reprogramación) si sigue libre; si no, el de
     * menos citas ese día y, a igualdad, el de menor orden.
     *
     * @param array<string, mixed> $ctx
     * @return array{0:?int, 1:list<string>} barbero elegido y alternativas cercanas (HH:MM) si no hay
     */
    private function pick(array $ctx, string $fecha, int $start, ?int $excludeId, ?int $prefer): array
    {
        $calc = $this->availability->compute($ctx, $fecha, $fecha, $excludeId);
        $free = $calc['slots'][$fecha][$start] ?? [];
        if ($free === []) {
            $tz = new DateTimeZone((string) $ctx['branch']['zona_horaria']);
            $alts = [];
            foreach (array_keys($calc['slots'][$fecha] ?? []) as $ts) {
                $alts[$ts] = abs($ts - $start);
            }
            asort($alts);
            $near = array_slice(array_keys($alts), 0, 3);
            sort($near);
            return [null, array_map(static fn (int $ts): string => (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('H:i'), $near)];
        }
        if ($prefer !== null && in_array($prefer, $free, true)) {
            return [$prefer, []];
        }
        $carga = $calc['carga'][$fecha] ?? [];
        usort($free, static fn (int $a, int $b): int => [($carga[$a] ?? 0), $ctx['candidates'][$a]['orden'], $a] <=> [($carga[$b] ?? 0), $ctx['candidates'][$b]['orden'], $b]);
        return [$free[0], []];
    }

    /**
     * Servicios consecutivos con el mismo barbero, en el orden elegido.
     *
     * @param array<string, mixed> $cand
     * @param list<array<string, mixed>> $services
     * @return list<array{servicio_id:int, nombre:string, duracion:int, precio:string, inicio_ts:int, fin_ts:int}>
     */
    private function segments(array $cand, array $services, int $start): array
    {
        $out = [];
        $cursor = $start;
        foreach ($services as $s) {
            $sid = (int) $s['id'];
            $dur = (int) $cand['servicios'][$sid]['duracion'];
            $out[] = ['servicio_id' => $sid, 'nombre' => (string) $s['nombre'], 'duracion' => $dur, 'precio' => (string) $cand['servicios'][$sid]['precio'], 'inicio_ts' => $cursor, 'fin_ts' => $cursor + $dur * 60];
            $cursor += $dur * 60;
        }
        return $out;
    }

    /** @param list<array<string, mixed>> $segments @return list<array{servicio_id:int, nombre:string, duracion:int, precio:string, inicio_utc:string, fin_utc:string}> */
    private function serviceRows(array $segments): array
    {
        return array_map(static fn (array $s): array => [
            'servicio_id' => $s['servicio_id'], 'nombre' => $s['nombre'], 'duracion' => $s['duracion'], 'precio' => $s['precio'],
            'inicio_utc' => gmdate('Y-m-d H:i:s', $s['inicio_ts']), 'fin_utc' => gmdate('Y-m-d H:i:s', $s['fin_ts']),
        ], $segments);
    }

    /** Hora local de la sucursal → timestamp UTC (rechaza horas inexistentes por cambio de horario). @param array<string, mixed> $ctx */
    private function startTs(array $ctx, string $fecha, string $hora): int
    {
        $tz = new DateTimeZone((string) $ctx['branch']['zona_horaria']);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "{$fecha} {$hora}", $tz);
        if ($d === false || $d->format('Y-m-d H:i') !== "{$fecha} {$hora}") {
            throw new ValidationException(['hora' => ['Esa hora no existe en la zona horaria de la sucursal.']]);
        }
        return $d->getTimestamp();
    }

    private function assertWithinPolicy(BookingPolicy $policy, int $start, string $fecha, string $tz): void
    {
        $now = Clock::nowUtc();
        if ($start < $now->getTimestamp() + $policy->anticipacion * 60) {
            throw new HttpException(422, 'too_soon', $start <= $now->getTimestamp()
                ? 'Ese horario ya pasó. Elige otro.'
                : 'Las reservas en línea se hacen con al menos ' . self::minutesText($policy->anticipacion) . ' de anticipación.');
        }
        $max = $now->setTimezone(new DateTimeZone($tz))->modify("+{$policy->horizonteDias} day")->format('Y-m-d');
        if ($fecha > $max) {
            throw new HttpException(422, 'too_far', "Puedes reservar con hasta {$policy->horizonteDias} días de anticipación.");
        }
    }

    private function assertClientFree(ClientRepository $clients, int $clienteId, int $start, int $end, ?int $excludeId, bool $staff = false): void
    {
        $o = $clients->overlapping($clienteId, gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-d H:i:s', $end), $excludeId);
        if ($o !== null) {
            throw new HttpException(409, 'client_overlap', $staff
                // Al personal no se le revela en qué otra barbería tiene cita el cliente.
                ? ((int) $o['tenant_id'] === $this->tenantId ? "El cliente ya tiene otra cita a esa hora (código {$o['codigo']})." : 'El cliente ya tiene otra cita a esa hora.')
                : "Ya tienes otra cita a esa hora en {$o['nombre_comercial']} (código {$o['codigo']}).");
        }
    }

    /** Bloquea barbero y reserva (en ese orden, igual que al crear). @return array<string, mixed> */
    public function lockBooking(int $id): array
    {
        $peek = $this->repo->find($id);
        if ($peek === null) {
            throw new NotFoundException('Reserva no encontrada.');
        }
        $this->barbers->lockMany([(int) $peek['barbero_id']]);
        $r = $this->repo->lock($id);
        if ($r === null) {
            throw new NotFoundException('Reserva no encontrada.');
        }
        if ((int) $r['barbero_id'] !== (int) $peek['barbero_id']) {
            throw new HttpException(409, 'booking_changed', 'La cita cambió mientras la procesábamos. Actualiza e intenta de nuevo.');
        }
        return $r;
    }

    /** @param array<string, mixed> $data */
    private function insertWithCode(array $data): int
    {
        for ($i = 0; $i < 5; $i++) {
            try {
                return $this->repo->create(['codigo' => Codes::reserva()] + $data);
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062 || !str_contains($e->getMessage(), 'uk_reservas_codigo')) {
                    throw $e;
                }
            }
        }
        throw new HttpException(500, 'code_generation_failed', 'No se pudo generar el código de la reserva. Intenta de nuevo.');
    }

    public static function minutesText(int $min): string
    {
        if ($min % 1440 === 0 && $min >= 1440) {
            $d = intdiv($min, 1440);
            return $d === 1 ? '1 día' : "{$d} días";
        }
        if ($min % 60 === 0 && $min >= 60) {
            $h = intdiv($min, 60);
            return $h === 1 ? '1 hora' : "{$h} horas";
        }
        return "{$min} minutos";
    }
}
