<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Services\Schedule\Intervals;

/**
 * Cálculo puro de horarios disponibles (sin base de datos), para poder probarlo a fondo.
 *
 *   libre   = ventanas de trabajo del día − (reservas activas ± margen entre citas)
 *   inicios = puntos de la grilla (cada `intervalo` minutos desde la medianoche local)
 *             + el inicio de cada tramo libre (así no se pierde el hueco que deja una cita de
 *               45 min en una grilla de 30)
 *   ofrecer = inicios t con [t, t + duración) dentro de un tramo libre y t ≥ ahora + anticipación
 *
 * Todo en timestamps UTC; las ventanas ya vienen en la zona horaria de la sucursal convertidas
 * por WorkingTime (jornada ∩ horario comercial − descansos − bloqueos).
 */
final class Availability
{
    /** Los inicios fuera de la grilla se redondean a múltiplos de 5 minutos. */
    private const ALIGN_SECONDS = 300;

    /**
     * @param list<array{0:int, 1:int}> $windows    ventanas de trabajo (un día local, una sucursal)
     * @param list<array{0:int, 1:int}> $busy       reservas activas del barbero en cualquier sucursal
     * @return list<int> inicios posibles, ordenados
     */
    public static function slots(
        array $windows,
        array $busy,
        int $durationMinutes,
        int $intervalMinutes,
        int $marginMinutes,
        int $earliestTs,
        int $gridOriginTs
    ): array {
        if ($durationMinutes <= 0 || $intervalMinutes <= 0) {
            return [];
        }
        $dur = $durationMinutes * 60;
        $step = $intervalMinutes * 60;
        $margin = max(0, $marginMinutes) * 60;
        $holes = array_map(static fn (array $b): array => [$b[0] - $margin, $b[1] + $margin], $busy);

        $out = [];
        foreach (Intervals::subtract($windows, $holes) as [$fs, $fe]) {
            $last = $fe - $dur;
            if ($last < $fs) {
                continue;
            }
            $first = $gridOriginTs + (int) ceil(($fs - $gridOriginTs) / $step) * $step;
            for ($t = $first; $t <= $last; $t += $step) {
                $out[$t] = true;
            }
            $aligned = $gridOriginTs + (int) ceil(($fs - $gridOriginTs) / self::ALIGN_SECONDS) * self::ALIGN_SECONDS;
            if ($aligned <= $last) {
                $out[$aligned] = true;
            }
        }
        $slots = array_values(array_filter(array_keys($out), static fn (int $t): bool => $t >= $earliestTs));
        sort($slots);
        return $slots;
    }

    /**
     * Intervalos ocupados a partir de filas de reservas activas.
     *
     * @param list<array<string, mixed>> $rows con inicio_utc, fin_utc e id
     * @return list<array{0:int, 1:int}>
     */
    public static function busy(array $rows, ?int $excludeReservaId = null): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ($excludeReservaId !== null && (int) $r['id'] === $excludeReservaId) {
                continue;
            }
            $out[] = [self::ts((string) $r['inicio_utc']), self::ts((string) $r['fin_utc'])];
        }
        return $out;
    }

    /** 'Y-m-d H:i:s' en UTC → timestamp. */
    public static function ts(string $utc): int
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
