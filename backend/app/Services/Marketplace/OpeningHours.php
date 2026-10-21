<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Estado "abierto / cerrado" de una sucursal a partir de su horario comercial
 * (horarios_sucursales, en hora local de la sucursal) y de su zona horaria.
 */
final class OpeningHours
{
    public const DIAS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

    /**
     * @param list<array{dia_semana:int|string, hora_apertura:string, hora_cierre:string}> $tramos
     * @return array{abierto:bool, cierra_a:?string, proxima_apertura:?array{dia:int, dia_nombre:string, hora:string, fecha:string}}
     */
    public static function status(array $tramos, string $timezone, DateTimeImmutable $nowUtc): array
    {
        try {
            $tz = new DateTimeZone($timezone);
        } catch (Exception $e) {
            $tz = new DateTimeZone('America/Lima');
        }
        $local = $nowUtc->setTimezone($tz);
        $byDay = [];
        foreach ($tramos as $t) {
            $byDay[(int) $t['dia_semana']][] = [substr((string) $t['hora_apertura'], 0, 5), substr((string) $t['hora_cierre'], 0, 5)];
        }
        foreach ($byDay as &$list) {
            sort($list);
        }
        unset($list);

        $hoy = (int) $local->format('N');
        $hora = $local->format('H:i');
        foreach ($byDay[$hoy] ?? [] as [$open, $close]) {
            if ($hora >= $open && $hora < $close) {
                return ['abierto' => true, 'cierra_a' => $close, 'proxima_apertura' => null];
            }
        }

        // Próxima apertura: más tarde hoy o en los próximos 7 días.
        for ($offset = 0; $offset <= 7; $offset++) {
            $date = $local->modify("+{$offset} day");
            $dia = (int) $date->format('N');
            foreach ($byDay[$dia] ?? [] as [$open]) {
                if ($offset === 0 && $open <= $hora) {
                    continue;
                }
                return ['abierto' => false, 'cierra_a' => null, 'proxima_apertura' => [
                    'dia' => $dia, 'dia_nombre' => self::DIAS[$dia], 'hora' => $open, 'fecha' => $date->format('Y-m-d'),
                ]];
            }
        }
        return ['abierto' => false, 'cierra_a' => null, 'proxima_apertura' => null];
    }
}
