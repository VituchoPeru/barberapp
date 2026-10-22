<?php

declare(strict_types=1);

namespace App\Services\Reports\Export;

/**
 * PDF mínimo sin dependencias (Fase 12): una o varias tablas con título, encabezado repetido en
 * cada página, números alineados a la derecha y pie con "Página X de Y".
 *
 * Usa las fuentes estándar Helvetica y Helvetica-Bold (todo lector de PDF las trae, no se
 * incrustan) con codificación WinAnsi (Windows-1252), que cubre el español: tildes, ñ, ¿, ¡, €.
 * Un carácter fuera de ese juego se escribe como "?". Las columnas se ajustan al ancho de la
 * página y el texto que no cabe se corta con "…".
 *
 * Existe para que la app nativa (Android/iOS), que no tiene diálogo de impresión, pueda
 * guardar o compartir el reporte en PDF; en web también se ofrece junto a "Imprimir".
 */
final class PdfWriter
{
    private const A4 = [595.28, 841.89];
    private const MARGIN = 36.0;
    private const FONT_SIZE = 8.5;
    private const ROW_H = 15.0;
    private const PAD = 4.0;
    private const MAX_COL_W = 220.0;
    private const MIN_COL_W = 34.0;

    /** Anchos de Helvetica y Helvetica-Bold (1/1000 em) para los códigos 32–255 de Windows-1252. */
    private const W_REGULAR = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,761,556,0,222,556,333,1000,556,556,333,1000,667,333,1000,0,611,0,0,222,222,333,333,350,556,1000,333,1000,500,333,944,0,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500];
    private const W_BOLD = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,761,556,0,278,556,500,1000,556,556,333,1000,667,333,1000,0,611,0,0,278,278,500,500,350,556,1000,333,1000,556,333,944,0,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556];

    /** @var list<string> contenido de cada página (operadores PDF) */
    private array $pages = [];
    private string $page = '';
    private float $y = 0.0;
    private float $pageW;
    private float $pageH;
    private string $footer;

    /**
     * @param list<Table> $tables
     * @param list<string> $heading líneas bajo el título (barbería, periodo, zona horaria…)
     */
    public static function write(array $tables, string $title = 'Reporte BARBERAPP', array $heading = [], ?string $generatedAt = null): string
    {
        $maxCols = 1;
        foreach ($tables as $t) {
            $maxCols = max($maxCols, count($t->headers));
        }
        $w = new self($maxCols > 6, 'BARBERAPP · ' . ($generatedAt ?? gmdate('Y-m-d H:i') . ' UTC'));
        $w->title($title, $heading);
        if ($tables === []) {
            $tables = [new Table('Reporte', ['Sin datos'])];
        }
        foreach ($tables as $t) {
            $w->table($t);
        }
        return $w->render();
    }

    private function __construct(bool $landscape, string $footer)
    {
        [$this->pageW, $this->pageH] = $landscape ? [self::A4[1], self::A4[0]] : self::A4;
        $this->footer = $footer;
        $this->newPage();
    }

    // ------------------------------------------------------------------ maquetación

    private function newPage(): void
    {
        if ($this->page !== '') {
            $this->pages[] = $this->page;
        }
        $this->page = '';
        $this->y = $this->pageH - self::MARGIN;
    }

    private function ensure(float $height): bool
    {
        if ($this->y - $height < self::MARGIN + 18) {
            $this->newPage();
            return true;
        }
        return false;
    }

    /** @param list<string> $heading */
    private function title(string $title, array $heading): void
    {
        $this->text(self::MARGIN, $this->y - 16, $title, 16, true, [0.07, 0.07, 0.07]);
        $this->y -= 24;
        foreach ($heading as $line) {
            $this->text(self::MARGIN, $this->y - 10, $line, 9.5, false, [0.3, 0.3, 0.3]);
            $this->y -= 14;
        }
        $this->rect(self::MARGIN, $this->y - 6, 56, 2.5, [0.79, 0.64, 0.36]); // regla dorada
        $this->y -= 20;
    }

    private function table(Table $t): void
    {
        $cols = max(1, count($t->headers));
        $rows = array_map(fn (array $r): array => array_map(fn ($v): string => self::cell($v), array_pad($r, $cols, null)), $t->rows);
        $numeric = [];
        for ($c = 0; $c < $cols; $c++) {
            $numeric[$c] = $t->rows !== [] && array_reduce($t->rows, fn (bool $acc, array $r): bool => $acc && (($r[$c] ?? null) === null || is_int($r[$c]) || is_float($r[$c])), true);
        }
        $widths = $this->columnWidths($t->headers, $rows);

        $this->ensure(22 + self::ROW_H * 2);
        $this->text(self::MARGIN, $this->y - 11, $t->title, 11, true, [0.07, 0.07, 0.07]);
        $this->y -= 18;
        $this->headerRow($t->headers, $widths, $numeric);
        if ($rows === []) {
            $this->text(self::MARGIN + self::PAD, $this->y - 10.5, 'Sin datos en este periodo.', self::FONT_SIZE, false, [0.4, 0.4, 0.4]);
            $this->y -= self::ROW_H;
        }
        foreach ($rows as $i => $row) {
            if ($this->ensure(self::ROW_H)) {
                $this->headerRow($t->headers, $widths, $numeric);
            }
            if ($i % 2 === 1) {
                $this->rect(self::MARGIN, $this->y - self::ROW_H, array_sum($widths), self::ROW_H, [0.96, 0.96, 0.96]);
            }
            $this->rowText($row, $widths, $numeric, false);
            $this->y -= self::ROW_H;
        }
        $this->line(self::MARGIN, $this->y, self::MARGIN + array_sum($widths), $this->y, [0.8, 0.8, 0.8]);
        $this->y -= 16;
    }

    /**
     * @param list<string> $headers
     * @param list<bool>   $numeric
     */
    private function headerRow(array $headers, array $widths, array $numeric): void
    {
        $this->rect(self::MARGIN, $this->y - self::ROW_H, array_sum($widths), self::ROW_H, [0.11, 0.11, 0.12]);
        $this->rowText($headers, $widths, $numeric, true);
        $this->y -= self::ROW_H;
    }

    /**
     * @param list<string> $cells
     * @param list<float>  $widths
     * @param list<bool>   $numeric
     */
    private function rowText(array $cells, array $widths, array $numeric, bool $header): void
    {
        $x = self::MARGIN;
        foreach ($widths as $c => $w) {
            $txt = self::fit((string) ($cells[$c] ?? ''), $w - 2 * self::PAD, self::FONT_SIZE, $header);
            $tw = self::width($txt, self::FONT_SIZE, $header);
            $tx = $numeric[$c] ? $x + $w - self::PAD - $tw : $x + self::PAD;
            $color = $header ? [0.89, 0.78, 0.54] : [0.1, 0.1, 0.1];
            $this->text($tx, $this->y - 10.5, $txt, self::FONT_SIZE, $header, $color, false);
            $x += $w;
        }
    }

    /**
     * Ancho natural de cada columna (el texto más largo, con tope) escalado al ancho útil.
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     * @return list<float>
     */
    private function columnWidths(array $headers, array $rows): array
    {
        $avail = $this->pageW - 2 * self::MARGIN;
        $natural = [];
        foreach ($headers as $c => $h) {
            $w = self::width(self::encode($h), self::FONT_SIZE, true);
            foreach (array_slice($rows, 0, 500) as $r) {
                $w = max($w, self::width(self::encode($r[$c] ?? ''), self::FONT_SIZE, false));
            }
            $natural[$c] = min(self::MAX_COL_W, max(self::MIN_COL_W, $w + 2 * self::PAD));
        }
        $sum = array_sum($natural);
        if ($sum <= $avail) {
            // La tabla de dos columnas (indicador/valor) se ve mejor un poco más ancha.
            return count($natural) <= 2 ? array_map(fn (float $w): float => $w * min(1.6, $avail / $sum), $natural) : $natural;
        }
        return array_map(fn (float $w): float => $w * $avail / $sum, $natural);
    }

    // ------------------------------------------------------------------ dibujo

    /** @param array{0:float,1:float,2:float} $rgb */
    private function text(float $x, float $y, string $s, float $size, bool $bold, array $rgb, bool $encode = true): void
    {
        $enc = $encode ? self::encode($s) : $s;
        $this->page .= sprintf("BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $rgb[0], $rgb[1], $rgb[2], $x, $y, self::escape($enc));
    }

    /** @param array{0:float,1:float,2:float} $rgb */
    private function rect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->page .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $rgb[0], $rgb[1], $rgb[2], $x, $y, $w, $h);
    }

    /** @param array{0:float,1:float,2:float} $rgb */
    private function line(float $x1, float $y1, float $x2, float $y2, array $rgb): void
    {
        $this->page .= sprintf("%.3F %.3F %.3F RG 0.6 w %.2F %.2F m %.2F %.2F l S\n", $rgb[0], $rgb[1], $rgb[2], $x1, $y1, $x2, $y2);
    }

    // ------------------------------------------------------------------ texto

    private static function cell(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_int($v)) {
            return number_format($v, 0, '.', ',');
        }
        if (is_float($v)) {
            return number_format($v, 2, '.', ',');
        }
        return (string) $v;
    }

    /** UTF-8 → Windows-1252 (WinAnsiEncoding). Los caracteres fuera del juego quedan como "?". */
    public static function encode(string $s): string
    {
        $s = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
        $prev = mb_substitute_character();
        mb_substitute_character(0x3F);
        $out = mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        mb_substitute_character($prev);
        return is_string($out) ? $out : '';
    }

    /** Ancho en puntos de un texto ya codificado en Windows-1252. */
    private static function width(string $encoded, float $size, bool $bold): float
    {
        $table = $bold ? self::W_BOLD : self::W_REGULAR;
        $sum = 0;
        $len = strlen($encoded);
        for ($i = 0; $i < $len; $i++) {
            $code = ord($encoded[$i]);
            $sum += $code >= 32 ? ($table[$code - 32] ?: 556) : 0;
        }
        return $sum * $size / 1000;
    }

    /** Codifica y corta con "…" (0x85 en Windows-1252) si no cabe en $max puntos. */
    private static function fit(string $s, float $max, float $size, bool $bold): string
    {
        $e = self::encode($s);
        if (self::width($e, $size, $bold) <= $max) {
            return $e;
        }
        $ellipsis = "\x85";
        while ($e !== '' && self::width($e . $ellipsis, $size, $bold) > $max) {
            $e = substr($e, 0, -1);
        }
        return rtrim($e) . $ellipsis;
    }

    private static function escape(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => ' ', "\n" => ' ']);
    }

    // ------------------------------------------------------------------ archivo

    private function render(): string
    {
        $this->pages[] = $this->page;
        $n = count($this->pages);
        $deflate = function_exists('gzcompress');

        // Objetos: 1 catálogo, 2 árbol de páginas, 3–4 fuentes, 5 info; luego página + contenido.
        $objects = [];
        $kids = [];
        foreach ($this->pages as $i => $content) {
            $footer = sprintf("BT /F1 7.5 Tf 0.45 0.45 0.45 rg %.2F %.2F Td (%s) Tj ET\n", self::MARGIN, 20.0, self::escape(self::encode($this->footer)));
            $label = self::encode('Página ' . ($i + 1) . ' de ' . $n);
            $footer .= sprintf("BT /F1 7.5 Tf 0.45 0.45 0.45 rg %.2F %.2F Td (%s) Tj ET\n", $this->pageW - self::MARGIN - self::width($label, 7.5, false), 20.0, self::escape($label));
            $stream = $content . $footer;
            $pageId = 6 + $i * 2;
            $contentId = $pageId + 1;
            $kids[] = $pageId . ' 0 R';
            $objects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', $this->pageW, $this->pageH, $contentId);
            $data = $deflate ? (string) gzcompress($stream, 6) : $stream;
            $objects[$contentId] = '<< /Length ' . strlen($data) . ($deflate ? ' /Filter /FlateDecode' : '') . " >>\nstream\n" . $data . "\nendstream";
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[5] = '<< /Producer (BARBERAPP) /Creator (BARBERAPP) /CreationDate (D:' . gmdate('YmdHis') . "Z) >>";
        ksort($objects);

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $count = max(array_keys($objects)) + 1;
        $out .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size " . $count . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $out;
    }
}
