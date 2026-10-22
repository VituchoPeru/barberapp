<?php

declare(strict_types=1);

namespace App\Services\Reports\Export;

/**
 * CSV en UTF-8 con BOM (Excel reconoce las tildes), separador coma y CRLF. Varias tablas se
 * separan con una línea en blanco y su título. Protege contra inyección de fórmulas: un texto
 * que empieza con = + - @ (o tabulación/retorno) se antepone con un apóstrofo.
 */
final class CsvWriter
{
    /** @param list<Table> $tables @param list<list<string>> $preamble líneas iniciales */
    public static function write(array $tables, array $preamble = []): string
    {
        $lines = [];
        foreach ($preamble as $row) {
            $lines[] = self::line($row);
        }
        foreach ($tables as $i => $t) {
            if ($i > 0 || $preamble !== []) {
                $lines[] = '';
            }
            if (count($tables) > 1) {
                $lines[] = self::line([$t->title]);
            }
            $lines[] = self::line($t->headers);
            foreach ($t->rows as $row) {
                $lines[] = self::line($row);
            }
        }
        return "\u{FEFF}" . implode("\r\n", $lines) . "\r\n";
    }

    /** @param list<string|int|float|null> $row */
    private static function line(array $row): string
    {
        return implode(',', array_map([self::class, 'cell'], $row));
    }

    /** @param string|int|float|null $v */
    public static function cell($v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        $s = $v;
        if ($s !== '' && preg_match('/^[=+\-@\t\r]/', $s) === 1) {
            $s = "'" . $s;
        }
        return preg_match('/[",\r\n]/', $s) === 1 ? '"' . str_replace('"', '""', $s) . '"' : $s;
    }
}
