<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Calcula la jornada EFECTIVA de un barbero en un rango de fechas: los intervalos en los que
 * realmente puede atender. Es la base del motor de disponibilidad (Fase 5), que solo tendrá
 * que trocear estas ventanas según la duración del servicio y quitar las reservas activas.
 *
 *   ventanas = (jornada ∩ horario comercial de la sucursal) − descansos − bloqueos aprobados
 *
 * Jornada, horario comercial y descansos están en hora local de cada sucursal (zona horaria
 * propia); los bloqueos están en UTC. El resultado se devuelve en UTC y en hora local.
 */
final class WorkingTime
{
    /**
     * @param list<array{dia_semana:int|string, hora_inicio:string, hora_fin:string, sucursal_id:int|string, vigente_desde?:?string, vigente_hasta?:?string}> $tramos
     * @param list<array{dia_semana:int|string, hora_inicio:string, hora_fin:string}> $descansos
     * @param array<int, array{zona_horaria:string, horario:list<array{0:int, 1:string, 2:string}>}> $branches
     * @param list<array{sucursal_id:int|string|null, inicio_utc:string, fin_utc:string}> $bloqueos aprobados que afectan al barbero
     * @return list<array{fecha:string, sucursal_id:int, zona_horaria:string, inicio_utc:string, fin_utc:string, inicio_local:string, fin_local:string, minutos:int}>
     */
    public static function windows(array $tramos, array $descansos, array $branches, array $bloqueos, string $desde, string $hasta): array
    {
        $utc = new DateTimeZone('UTC');
        $blocks = [];
        foreach ($bloqueos as $b) {
            $blocks[] = [
                'sucursal_id' => $b['sucursal_id'] === null ? null : (int) $b['sucursal_id'],
                's' => (new DateTimeImmutable($b['inicio_utc'], $utc))->getTimestamp(),
                'e' => (new DateTimeImmutable($b['fin_utc'], $utc))->getTimestamp(),
            ];
        }

        $out = [];
        $period = new DatePeriod(new DateTimeImmutable($desde), new DateInterval('P1D'), (new DateTimeImmutable($hasta))->modify('+1 day'));
        foreach ($period as $day) {
            $fecha = $day->format('Y-m-d');
            $dia = (int) $day->format('N');
            // Tramos del día agrupados por sucursal (cada sucursal tiene su zona horaria).
            $bySucursal = [];
            foreach ($tramos as $t) {
                if ((int) $t['dia_semana'] !== $dia) {
                    continue;
                }
                if (!empty($t['vigente_desde']) && $fecha < $t['vigente_desde']) {
                    continue;
                }
                if (!empty($t['vigente_hasta']) && $fecha > $t['vigente_hasta']) {
                    continue;
                }
                $bySucursal[(int) $t['sucursal_id']][] = [Intervals::minutes($t['hora_inicio']), Intervals::minutes($t['hora_fin'])];
            }
            $pausas = [];
            foreach ($descansos as $d) {
                if ((int) $d['dia_semana'] === $dia) {
                    $pausas[] = [Intervals::minutes($d['hora_inicio']), Intervals::minutes($d['hora_fin'])];
                }
            }

            foreach ($bySucursal as $sucursalId => $intervals) {
                $branch = $branches[$sucursalId] ?? null;
                if ($branch === null) {
                    continue; // sucursal desactivada o eliminada: no se ofrece jornada allí
                }
                $abierto = [];
                foreach ($branch['horario'] as [$d, $a, $c]) {
                    if ((int) $d === $dia) {
                        $abierto[] = [Intervals::minutes($a), Intervals::minutes($c)];
                    }
                }
                $mins = Intervals::subtract(Intervals::intersect($intervals, $abierto), $pausas);
                if ($mins === []) {
                    continue;
                }
                $tz = self::zone($branch['zona_horaria']);
                $midnight = new DateTimeImmutable($fecha . ' 00:00:00', $tz);
                $ts = array_map(static fn (array $i): array => [
                    $midnight->modify("+{$i[0]} minutes")->getTimestamp(),
                    $midnight->modify("+{$i[1]} minutes")->getTimestamp(),
                ], $mins);
                $holes = [];
                foreach ($blocks as $b) {
                    if ($b['sucursal_id'] === null || $b['sucursal_id'] === $sucursalId) {
                        $holes[] = [$b['s'], $b['e']];
                    }
                }
                foreach (Intervals::subtract($ts, $holes) as [$s, $e]) {
                    $ls = (new DateTimeImmutable('@' . $s))->setTimezone($tz);
                    $le = (new DateTimeImmutable('@' . $e))->setTimezone($tz);
                    $out[] = [
                        'fecha' => $fecha,
                        'sucursal_id' => $sucursalId,
                        'zona_horaria' => $tz->getName(),
                        'inicio_utc' => gmdate('Y-m-d H:i:s', $s),
                        'fin_utc' => gmdate('Y-m-d H:i:s', $e),
                        'inicio_local' => $ls->format('H:i'),
                        'fin_local' => $le->format('Y-m-d') === $fecha ? $le->format('H:i') : '24:00',
                        'minutos' => intdiv($e - $s, 60),
                    ];
                }
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['inicio_utc'], $b['inicio_utc']));
        return $out;
    }

    public static function zone(string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name);
        } catch (Exception $e) {
            return new DateTimeZone('America/Lima');
        }
    }
}
