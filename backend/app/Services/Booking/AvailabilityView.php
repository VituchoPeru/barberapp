<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Core\Exceptions\ValidationException;
use App\Helpers\Clock;
use App\Helpers\Validator;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Consulta de disponibilidad compartida por el flujo público (GET /disponibilidad) y por la
 * reprogramación (GET /mis-reservas/{id}/disponibilidad).
 *
 *   ?fecha=AAAA-MM-DD            horarios de ese día
 *   ?desde=AAAA-MM-DD&hasta=…    resumen por día (para el calendario), máximo 31 días
 */
final class AvailabilityView
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $ctx AvailabilityService::context()
     * @return array<string, mixed>
     */
    public static function build(AvailabilityService $svc, array $ctx, array $query, ?int $excludeReservaId = null): array
    {
        $q = Validator::validate($query, ['fecha' => 'nullable|date', 'desde' => 'nullable|date', 'hasta' => 'nullable|date']);
        /** @var BookingPolicy $policy */
        $policy = $ctx['policy'];
        $tzName = (string) $ctx['branch']['zona_horaria'];
        $tz = new DateTimeZone($tzName);
        $today = Clock::nowUtc()->setTimezone($tz)->format('Y-m-d');
        $limit = (new DateTimeImmutable($today))->modify("+{$policy->horizonteDias} day")->format('Y-m-d');

        $duraciones = array_map(static fn (array $c): int => AvailabilityService::duration($c, $ctx['services']), $ctx['candidates']);
        $precios = array_map(static fn (array $c): float => (float) AvailabilityService::price($c, $ctx['services']), $ctx['candidates']);
        $base = [
            'sucursal_id' => (int) $ctx['branch']['id'],
            'zona_horaria' => $tzName,
            'hoy' => $today,
            'reservable_hasta' => $limit,
            'intervalo_minutos' => $policy->intervalo,
            'anticipacion_minima_minutos' => $policy->anticipacion,
            'duracion_minutos' => ['min' => min($duraciones), 'max' => max($duraciones)],
            'precio' => ['min' => number_format(min($precios), 2, '.', ''), 'max' => number_format(max($precios), 2, '.', '')],
            'barberos' => array_values(array_map(static fn (array $c): array => [
                'id' => $c['id'], 'nombre' => $c['nombre'], 'duracion_minutos' => AvailabilityService::duration($c, $ctx['services']),
                'precio' => AvailabilityService::price($c, $ctx['services']),
            ], $ctx['candidates'])),
        ];

        if (!empty($q['fecha'])) {
            $fecha = (string) $q['fecha'];
            $calc = $svc->compute($ctx, $fecha, $fecha, $excludeReservaId);
            $horarios = [];
            foreach ($calc['slots'][$fecha] ?? [] as $ts => $ids) {
                $local = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
                $horarios[] = ['hora' => $local->format('H:i'), 'inicio_utc' => gmdate('Y-m-d\TH:i:s\Z', $ts), 'barberos' => $ids];
            }
            return $base + ['fecha' => $fecha, 'horarios' => $horarios];
        }

        $desde = (string) ($q['desde'] ?? $today);
        $hasta = (string) ($q['hasta'] ?? (new DateTimeImmutable($desde))->modify('+13 day')->format('Y-m-d'));
        if ($hasta < $desde) {
            throw new ValidationException(['hasta' => ['La fecha final debe ser igual o posterior a la inicial.']]);
        }
        if ((new DateTimeImmutable($desde))->diff(new DateTimeImmutable($hasta))->days >= AvailabilityService::MAX_DIAS_CONSULTA) {
            throw new ValidationException(['hasta' => ['Consulta como máximo ' . AvailabilityService::MAX_DIAS_CONSULTA . ' días.']]);
        }
        $calc = $svc->compute($ctx, $desde, $hasta, $excludeReservaId);
        $dias = [];
        for ($d = new DateTimeImmutable($desde); $d->format('Y-m-d') <= $hasta; $d = $d->modify('+1 day')) {
            $f = $d->format('Y-m-d');
            $slots = $calc['slots'][$f] ?? [];
            $first = array_key_first($slots);
            $dias[] = [
                'fecha' => $f,
                'reservable' => $f >= $today && $f <= $limit,
                'disponibles' => count($slots),
                'primera_hora' => $first === null ? null : (new DateTimeImmutable('@' . $first))->setTimezone($tz)->format('H:i'),
            ];
        }
        return $base + ['desde' => $desde, 'hasta' => $hasta, 'dias' => $dias];
    }
}
