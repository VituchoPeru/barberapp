<?php

declare(strict_types=1);

namespace App\Services\Reports\Export;

/**
 * Libro de Excel (.xlsx, Office Open XML) mínimo sin dependencias: una hoja por tabla, texto en
 * línea (inlineStr), números como números, encabezado en negrita y primera fila fija.
 *
 * El ZIP se arma a mano (no requiere ext-zip): entradas comprimidas con DEFLATE cuando zlib está
 * disponible (gzdeflate) o guardadas sin comprimir si no. Pensado para reportes de hasta unas
 * decenas de miles de filas (todo se construye en memoria).
 */
final class XlsxWriter
{
    /** @param list<Table> $tables */
    public static function write(array $tables, string $creator = 'BARBERAPP'): string
    {
        if ($tables === []) {
            $tables = [new Table('Reporte', ['Sin datos'])];
        }
        $names = self::sheetNames($tables);
        $files = [
            '[Content_Types].xml' => self::contentTypes(count($tables)),
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
                . '</Relationships>',
            'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
                . '<dc:creator>' . self::esc($creator) . '</dc:creator>'
                . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
                . '</cp:coreProperties>',
            'xl/workbook.xml' => self::workbook($names),
            'xl/_rels/workbook.xml.rels' => self::workbookRels(count($tables)),
            'xl/styles.xml' => self::styles(),
        ];
        foreach ($tables as $i => $t) {
            $files['xl/worksheets/sheet' . ($i + 1) . '.xml'] = self::sheet($t);
        }
        return self::zip($files);
    }

    // ------------------------------------------------------------------ partes del libro

    private static function contentTypes(int $n): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $x .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return $x . '</Types>';
    }

    /** @param list<string> $names */
    private static function workbook(array $names): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        foreach ($names as $i => $name) {
            $x .= '<sheet name="' . self::esc($name) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        return $x . '</sheets></workbook>';
    }

    private static function workbookRels(int $n): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        for ($i = 1; $i <= $n; $i++) {
            $x .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        return $x . '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    }

    /** Estilos: 0 = normal, 1 = encabezado en negrita con fondo. */
    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE4C88A"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private static function sheet(Table $t): string
    {
        $cols = max(1, count($t->headers), ...array_map('count', $t->rows ?: [[]]));
        $widths = array_fill(0, $cols, 10);
        foreach (array_merge([$t->headers], $t->rows) as $row) {
            foreach (array_values($row) as $i => $v) {
                $widths[$i] = min(60, max($widths[$i], mb_strlen((string) $v) + 2));
            }
        }
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>';
        foreach ($widths as $i => $w) {
            $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $x .= '</cols><sheetData>';
        $x .= self::row(1, $t->headers, 1);
        foreach ($t->rows as $i => $row) {
            $x .= self::row($i + 2, $row, 0);
        }
        return $x . '</sheetData></worksheet>';
    }

    /** @param list<string|int|float|null> $cells */
    private static function row(int $n, array $cells, int $style): string
    {
        $x = '<row r="' . $n . '">';
        foreach (array_values($cells) as $i => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $ref = self::col($i) . $n;
            $s = $style > 0 ? ' s="' . $style . '"' : '';
            if ((is_int($v) || is_float($v)) && is_finite((float) $v)) {
                $x .= '<c r="' . $ref . '"' . $s . '><v>' . $v . '</v></c>';
            } else {
                $x .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc((string) $v) . '</t></is></c>';
            }
        }
        return $x . '</row>';
    }

    /** Índice 0 → A, 25 → Z, 26 → AA. */
    public static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    /** Escapa texto XML y quita caracteres de control no permitidos en XML 1.0. */
    private static function esc(string $s): string
    {
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Nombres de hoja únicos, de hasta 31 caracteres y sin \ / ? * [ ] :
     *
     * @param list<Table> $tables
     * @return list<string>
     */
    private static function sheetNames(array $tables): array
    {
        $out = [];
        foreach ($tables as $i => $t) {
            $base = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\\\\\/?*\[\]:]/', ' ', $t->title)));
            $base = mb_substr($base === '' ? 'Hoja ' . ($i + 1) : $base, 0, 31);
            $name = $base;
            for ($k = 2; in_array(mb_strtolower($name), array_map('mb_strtolower', $out), true); $k++) {
                $name = mb_substr($base, 0, 31 - strlen(" ({$k})")) . " ({$k})";
            }
            $out[] = $name;
        }
        return $out;
    }

    // ------------------------------------------------------------------ ZIP

    /** @param array<string, string> $files */
    private static function zip(array $files): string
    {
        $data = '';
        $central = '';
        [$dosTime, $dosDate] = self::dosTime();
        $deflate = function_exists('gzdeflate');
        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $method = $deflate ? 8 : 0;
            $body = $deflate ? (string) gzdeflate($content, 6) : $content;
            $offset = strlen($data);
            $header = pack('vvvvvVVVvv', 20, 0x0800, $method, $dosTime, $dosDate, $crc, strlen($body), strlen($content), strlen($name), 0);
            $data .= "PK\x03\x04" . $header . $name . $body;
            $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, $method, $dosTime, $dosDate, $crc, strlen($body), strlen($content), strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        }
        return $data . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($files), count($files), strlen($central), strlen($data), 0);
    }

    /** @return array{0:int, 1:int} hora y fecha en formato MS-DOS (UTC) */
    private static function dosTime(): array
    {
        $t = getdate(time());
        return [
            ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2),
            (max(0, $t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'],
        ];
    }
}
