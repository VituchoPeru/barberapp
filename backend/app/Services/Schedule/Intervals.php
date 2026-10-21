<?php

declare(strict_types=1);

namespace App\Services\Schedule;

/**
 * Operaciones con intervalos semiabiertos [inicio, fin) expresados como enteros
 * (minutos del día o timestamps). Dos intervalos que solo se tocan (13:00 fin / 13:00 inicio)
 * NO se solapan.
 */
final class Intervals
{
    /** "HH:MM" o "HH:MM:SS" → minutos desde medianoche. */
    public static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', array_pad(explode(':', $time), 2, '0'));
        return $h * 60 + $m;
    }

    public static function hhmm(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    public static function overlaps(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
    {
        return $aStart < $bEnd && $bStart < $aEnd;
    }

    /**
     * Une intervalos solapados o contiguos.
     *
     * @param list<array{0:int, 1:int}> $intervals
     * @return list<array{0:int, 1:int}>
     */
    public static function union(array $intervals): array
    {
        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $out = [];
        foreach ($intervals as [$s, $e]) {
            if ($e <= $s) {
                continue;
            }
            $last = count($out) - 1;
            if ($last >= 0 && $s <= $out[$last][1]) {
                $out[$last][1] = max($out[$last][1], $e);
            } else {
                $out[] = [$s, $e];
            }
        }
        return $out;
    }

    /**
     * @param list<array{0:int, 1:int}> $intervals
     * @param list<array{0:int, 1:int}> $holes
     * @return list<array{0:int, 1:int}>
     */
    public static function subtract(array $intervals, array $holes): array
    {
        $holes = self::union($holes);
        $out = [];
        foreach (self::union($intervals) as [$s, $e]) {
            $cursor = $s;
            foreach ($holes as [$hs, $he]) {
                if ($he <= $cursor || $hs >= $e) {
                    continue;
                }
                if ($hs > $cursor) {
                    $out[] = [$cursor, $hs];
                }
                $cursor = max($cursor, $he);
                if ($cursor >= $e) {
                    break;
                }
            }
            if ($cursor < $e) {
                $out[] = [$cursor, $e];
            }
        }
        return $out;
    }

    /**
     * @param list<array{0:int, 1:int}> $a
     * @param list<array{0:int, 1:int}> $b
     * @return list<array{0:int, 1:int}>
     */
    public static function intersect(array $a, array $b): array
    {
        $a = self::union($a);
        $b = self::union($b);
        $out = [];
        $i = $j = 0;
        while ($i < count($a) && $j < count($b)) {
            $s = max($a[$i][0], $b[$j][0]);
            $e = min($a[$i][1], $b[$j][1]);
            if ($s < $e) {
                $out[] = [$s, $e];
            }
            if ($a[$i][1] < $b[$j][1]) {
                $i++;
            } else {
                $j++;
            }
        }
        return $out;
    }

    /**
     * ¿[s, e) está completamente cubierto por la unión de $intervals?
     *
     * @param list<array{0:int, 1:int}> $intervals
     */
    public static function covers(array $intervals, int $s, int $e): bool
    {
        foreach (self::union($intervals) as [$is, $ie]) {
            if ($is <= $s && $e <= $ie) {
                return true;
            }
        }
        return false;
    }
}
