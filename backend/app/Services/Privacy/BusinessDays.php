<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Días hábiles en Perú: lunes a viernes sin feriados nacionales (Ley 27444 y D. Leg. 713).
 * Se usa para el plazo de respuesta de las solicitudes de derechos (D. S. 016-2024-JUS,
 * art. 69: se cuenta desde el día siguiente a la presentación).
 *
 * Los feriados fijos están aquí; Jueves y Viernes Santo se calculan. Los días no laborables
 * que el gobierno declara cada año no se descuentan: el plazo resulta igual o más corto,
 * nunca más largo de lo debido.
 */
final class BusinessDays
{
    /** mes-día de los feriados nacionales fijos */
    private const FIJOS = [
        '01-01', '05-01', '06-07', '06-29', '07-23', '07-28', '07-29', '08-06', '08-30', '10-08', '11-01', '12-08', '12-09', '12-25',
    ];

    public static function isHoliday(DateTimeImmutable $day): bool
    {
        if (in_array($day->format('m-d'), self::FIJOS, true)) {
            return true;
        }
        $easter = self::easter((int) $day->format('Y'));
        $d = $day->format('Y-m-d');
        return $d === $easter->modify('-3 days')->format('Y-m-d') || $d === $easter->modify('-2 days')->format('Y-m-d');
    }

    public static function isBusinessDay(DateTimeImmutable $day): bool
    {
        return (int) $day->format('N') <= 5 && !self::isHoliday($day);
    }

    /** Fecha en que vence un plazo de $days días hábiles contados desde el día siguiente a $from (hora de Lima). */
    public static function add(DateTimeImmutable $from, int $days): DateTimeImmutable
    {
        $day = $from->setTimezone(new DateTimeZone('America/Lima'))->setTime(0, 0);
        $count = 0;
        while ($count < $days) {
            $day = $day->modify('+1 day');
            if (self::isBusinessDay($day)) {
                $count++;
            }
        }
        return $day;
    }

    /** Domingo de Pascua (algoritmo anónimo gregoriano), sin depender de ext-calendar. */
    public static function easter(int $year): DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $dayOfMonth = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $dayOfMonth), new DateTimeZone('America/Lima'));
    }
}
