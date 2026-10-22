<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Helpers\Clock;
use App\Repositories\AgendaBlockRepository;
use App\Repositories\BarberRepository;
use App\Repositories\BookingRepository;
use App\Repositories\BranchRepository;
use App\Services\Schedule\WorkingTime;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Motor de disponibilidad: qué horarios se pueden ofrecer para una combinación de
 * sucursal + servicios + barbero (o cualquiera) en un rango de fechas locales.
 *
 * Considera: jornada semanal y vigencias, horario comercial de la sucursal, descansos,
 * bloqueos aprobados (vacaciones, ausencias, feriados), reservas activas del barbero en
 * CUALQUIER sucursal (un barbero no puede estar en dos sedes a la vez), duración total de los
 * servicios con los valores propios de cada barbero, margen entre citas, anticipación mínima,
 * horizonte máximo, grilla configurable y zona horaria de la sucursal.
 *
 * La misma función valida una reserva dentro de la transacción (con los barberos bloqueados),
 * así lo que se ofrece y lo que se acepta nunca difieren.
 */
final class AvailabilityService
{
    public const MAX_SERVICIOS = 5;
    public const MAX_DIAS_CONSULTA = 31;

    private BookingRepository $bookings;

    public function __construct(private int $tenantId)
    {
        $this->bookings = new BookingRepository($tenantId);
    }

    public function policy(?int $sucursalId): BookingPolicy
    {
        return BookingPolicy::fromRow($this->bookings->policyRow($sucursalId));
    }

    /**
     * Valida sucursal, servicios y barbero, y devuelve el contexto para calcular horarios.
     *
     * @param mixed $servicioIds
     * @return array{branch: array<string, mixed>, policy: BookingPolicy, services: list<array<string, mixed>>, candidates: array<int, array<string, mixed>>, barbero_id: ?int}
     */
    public function context(int $sucursalId, $servicioIds, ?int $barberoId, bool $onlineOnly = true): array
    {
        $branch = $this->bookings->branch($sucursalId);
        if ($branch === null) {
            throw new NotFoundException('Sucursal no encontrada o no disponible.');
        }
        $ids = self::serviceIds($servicioIds);
        $found = $this->bookings->activeServices($ids);
        if (count($found) !== count($ids)) {
            throw new ValidationException(['servicio_ids' => ['Alguno de los servicios elegidos ya no está disponible.']]);
        }
        $policy = $this->policy($sucursalId);
        if ($barberoId === null && !$policy->permiteCualquierBarbero) {
            throw new ValidationException(['barbero_id' => ['Esta barbería pide elegir un barbero.']]);
        }
        $candidates = $this->bookings->candidates($sucursalId, $ids, $barberoId, $onlineOnly);
        if ($candidates === []) {
            throw $barberoId !== null
                ? new ValidationException(['barbero_id' => ['Ese barbero no realiza todos los servicios elegidos en esta sucursal o no acepta reservas en línea.']])
                : new HttpException(422, 'no_barber_for_services', 'Ningún barbero de esta sucursal realiza esa combinación de servicios. Elige otros servicios o reserva por separado.');
        }
        return [
            'branch' => $branch,
            'policy' => $policy,
            'services' => array_map(static fn (int $id): array => $found[$id], $ids),
            'candidates' => $candidates,
            'barbero_id' => $barberoId,
        ];
    }

    /**
     * Horarios disponibles por fecha local.
     *
     * @param array<string, mixed> $ctx resultado de context()
     * @return array{slots: array<string, array<int, list<int>>>, carga: array<string, array<int, int>>, desde: ?string, hasta: ?string}
     *         slots[fecha][timestamp] = ids de barberos libres; carga[fecha][barbero] = reservas activas ese día
     */
    public function compute(array $ctx, string $desde, string $hasta, ?int $excludeReservaId = null): array
    {
        /** @var BookingPolicy $policy */
        $policy = $ctx['policy'];
        $sucursalId = (int) $ctx['branch']['id'];
        $tzName = WorkingTime::zone((string) $ctx['branch']['zona_horaria'])->getName();
        $tz = new DateTimeZone($tzName);
        $now = Clock::nowUtc();
        $earliest = $now->getTimestamp() + $policy->anticipacion * 60;
        $today = $now->setTimezone($tz)->format('Y-m-d');
        $maxDate = (new DateTimeImmutable($today))->modify("+{$policy->horizonteDias} day")->format('Y-m-d');
        $desde = max($desde, $today);
        $hasta = min($hasta, $maxDate);
        $empty = ['slots' => [], 'carga' => [], 'desde' => null, 'hasta' => null];
        if ($desde > $hasta) {
            return $empty;
        }

        $branchMap = (new BranchRepository($this->tenantId))->scheduleMap(true);
        if (!isset($branchMap[$sucursalId])) {
            return $empty;
        }
        $only = [$sucursalId => $branchMap[$sucursalId]];
        $fromUtc = (new DateTimeImmutable($desde . ' 00:00:00', $tz))->modify('-1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $toUtc = (new DateTimeImmutable($hasta . ' 00:00:00', $tz))->modify('+2 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $barberIds = array_keys($ctx['candidates']);
        $bookingsByBarber = [];
        foreach ($this->bookings->activeBookings($barberIds, $fromUtc, $toUtc) as $r) {
            $bookingsByBarber[(int) $r['barbero_id']][] = $r;
        }
        $barbers = new BarberRepository($this->tenantId);
        $blocks = new AgendaBlockRepository($this->tenantId);

        $slots = [];
        $carga = [];
        foreach ($ctx['candidates'] as $id => $cand) {
            $duration = self::duration($cand, $ctx['services']);
            $rows = $bookingsByBarber[$id] ?? [];
            $busy = Availability::busy($rows, $excludeReservaId);
            foreach ($rows as $r) {
                if ($excludeReservaId !== null && (int) $r['id'] === $excludeReservaId) {
                    continue;
                }
                $f = Clock::utcToLocal((string) $r['inicio_utc'], $tzName)->format('Y-m-d');
                $carga[$f][$id] = ($carga[$f][$id] ?? 0) + 1;
            }
            $windows = WorkingTime::windows($barbers->shifts($id), $barbers->breaks($id), $only, $blocks->approvedFor($id, $fromUtc, $toUtc), $desde, $hasta);
            $byDay = [];
            foreach ($windows as $w) {
                $byDay[$w['fecha']][] = [Availability::ts($w['inicio_utc']), Availability::ts($w['fin_utc'])];
            }
            foreach ($byDay as $fecha => $win) {
                $origin = (new DateTimeImmutable($fecha . ' 00:00:00', $tz))->getTimestamp();
                foreach (Availability::slots($win, $busy, $duration, $policy->intervalo, $policy->margen, $earliest, $origin) as $ts) {
                    $slots[$fecha][$ts][] = $id;
                }
            }
        }
        foreach ($slots as &$day) {
            ksort($day);
        }
        unset($day);
        ksort($slots);
        return ['slots' => $slots, 'carga' => $carga, 'desde' => $desde, 'hasta' => $hasta];
    }

    /** Duración total (min) de los servicios con los valores propios del barbero. @param array<string, mixed> $cand @param list<array<string, mixed>> $services */
    public static function duration(array $cand, array $services): int
    {
        $total = 0;
        foreach ($services as $s) {
            $total += (int) $cand['servicios'][(int) $s['id']]['duracion'];
        }
        return $total;
    }

    /** Precio total con los valores propios del barbero. @param array<string, mixed> $cand @param list<array<string, mixed>> $services */
    public static function price(array $cand, array $services): string
    {
        $cents = 0;
        foreach ($services as $s) {
            $cents += (int) round((float) $cand['servicios'][(int) $s['id']]['precio'] * 100);
        }
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * Lista de servicios: 1..MAX, enteros, sin repetir. Acepta lista JSON o "1,2,3".
     *
     * @param mixed $raw
     * @return list<int>
     */
    public static function serviceIds($raw): array
    {
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : explode(',', $raw);
        }
        if (!is_array($raw) || $raw === []) {
            throw new ValidationException(['servicio_ids' => ['Elige al menos un servicio.']]);
        }
        if (count($raw) > self::MAX_SERVICIOS) {
            throw new ValidationException(['servicio_ids' => ['Puedes reservar hasta ' . self::MAX_SERVICIOS . ' servicios en una misma cita.']]);
        }
        $ids = [];
        foreach ($raw as $v) {
            $id = filter_var(is_string($v) ? trim($v) : $v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || is_bool($v)) {
                throw new ValidationException(['servicio_ids' => ['Lista de servicios inválida.']]);
            }
            if (in_array($id, $ids, true)) {
                throw new ValidationException(['servicio_ids' => ['No repitas un servicio en la misma cita.']]);
            }
            $ids[] = (int) $id;
        }
        return $ids;
    }
}
