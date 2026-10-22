<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Reports\Export\PdfWriter;
use App\Services\Reports\Export\Table;
use PHPUnit\Framework\TestCase;

/** PDF de reportes sin dependencias (Fase 12): estructura válida, texto en español y paginación. */
final class PdfWriterTest extends TestCase
{
    /** Texto de todas las páginas (los streams se descomprimen si van con FlateDecode). */
    private static function streams(string $pdf): string
    {
        preg_match_all('/<< \/Length (\d+)( \/Filter \/FlateDecode)? >>\nstream\n/', $pdf, $m, PREG_OFFSET_CAPTURE);
        $out = '';
        foreach ($m[0] as $i => $match) {
            $data = substr($pdf, $match[1] + strlen($match[0]), (int) $m[1][$i][0]);
            $out .= $m[2][$i][0] !== '' ? (string) gzuncompress($data) : $data;
        }
        return $out;
    }

    public function testStructureIsAValidPdf(): void
    {
        $pdf = PdfWriter::write([new Table('Resumen', ['Indicador', 'Valor'], [['Reservas', 12], ['Ingresos', 1520.5]])], 'Reporte', ['Barbería Demo']);
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);

        // Cada entrada de la tabla xref apunta exactamente al inicio de su objeto.
        $this->assertSame(1, preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $sx));
        $xref = substr($pdf, (int) $sx[1]);
        $this->assertStringStartsWith('xref', $xref);
        $this->assertSame(1, preg_match('/^xref\n0 (\d+)\n/', $xref, $count));
        preg_match_all('/^(\d{10}) 00000 n $/m', $xref, $offsets);
        $this->assertCount((int) $count[1] - 1, $offsets[1]);
        foreach ($offsets[1] as $i => $off) {
            $this->assertStringStartsWith(($i + 1) . ' 0 obj', substr($pdf, (int) $off), "Objeto " . ($i + 1));
        }
        $this->assertStringContainsString('/Count 1', $pdf);
        $this->assertStringContainsString('/BaseFont /Helvetica /Encoding /WinAnsiEncoding', $pdf);
    }

    public function testSpanishTextNumbersAndTruncation(): void
    {
        $long = str_repeat('Corte clásico con navaja ', 20);
        $pdf = PdfWriter::write([new Table('Servicios más pedidos', ['Servicio', 'Reservas', 'Ingresos'], [
            ['Afeitado ñandú ¿sí? ¡Ya! (promo)', 1234, 99.5],
            [$long, 3, null],
            ['Emoji 😀 fuera de WinAnsi', 1, 0.0],
        ])]);
        $text = self::streams($pdf);
        $this->assertStringContainsString(mb_convert_encoding('Afeitado ñandú ¿sí? ¡Ya! \\(promo\\)', 'Windows-1252', 'UTF-8'), $text, 'Tildes, ñ y signos en WinAnsi; paréntesis escapados');
        $this->assertStringContainsString('(1,234) Tj', $text, 'Enteros con separador de miles');
        $this->assertStringContainsString('(99.50) Tj', $text, 'Decimales con dos cifras');
        $this->assertStringContainsString("\x85) Tj", $text, 'El texto que no cabe termina en …');
        $this->assertStringNotContainsString(mb_convert_encoding(trim($long), 'Windows-1252', 'UTF-8'), $text);
        $this->assertStringContainsString('(Emoji ? fuera de WinAnsi) Tj', $text);
        $this->assertStringContainsString(mb_convert_encoding('(Página 1 de 1)', 'Windows-1252', 'UTF-8'), $text);
    }

    public function testLongTablesPaginateAndRepeatTheHeader(): void
    {
        $rows = [];
        for ($i = 1; $i <= 150; $i++) {
            $rows[] = ["Cliente {$i}", $i];
        }
        $pdf = PdfWriter::write([new Table('Detalle', ['Cliente', 'Citas'], $rows)]);
        $this->assertSame(1, preg_match('/\/Count (\d+)/', $pdf, $m));
        $pages = (int) $m[1];
        $this->assertGreaterThanOrEqual(3, $pages);
        $text = self::streams($pdf);
        $this->assertSame($pages, substr_count($text, '(Cliente) Tj'), 'El encabezado se repite en cada página');
        $this->assertStringContainsString(mb_convert_encoding("(Página {$pages} de {$pages})", 'Windows-1252', 'UTF-8'), $text);
        $this->assertStringContainsString('(Cliente 150) Tj', $text);
    }

    public function testWideTablesUseLandscape(): void
    {
        $headers = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
        $pdf = PdfWriter::write([new Table('Ancha', $headers, [array_fill(0, 7, 'x')])]);
        $this->assertStringContainsString('/MediaBox [0 0 841.89 595.28]', $pdf);
        $this->assertStringContainsString('/MediaBox [0 0 595.28 841.89]', PdfWriter::write([new Table('Angosta', ['A', 'B'], [])]));
    }
}
