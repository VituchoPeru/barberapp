<?php

declare(strict_types=1);

namespace App\Services\Qr;

use InvalidArgumentException;

/**
 * Exporta un QrCode a SVG (vectorial, ideal para imprimir) o PNG (sin extensión GD:
 * el PNG se arma a mano con zlib). Incluye el margen de 4 módulos que exige la norma.
 */
final class QrRenderer
{
    public const MARGIN = 4;

    public static function svg(QrCode $qr, string $dark = '#111111', string $light = '#FFFFFF'): string
    {
        self::assertColor($dark);
        self::assertColor($light);
        $n = $qr->size() + self::MARGIN * 2;
        $path = '';
        for ($y = 0; $y < $qr->size(); $y++) {
            for ($x = 0; $x < $qr->size(); $x++) {
                if ($qr->module($x, $y)) {
                    $path .= 'M' . ($x + self::MARGIN) . ',' . ($y + self::MARGIN) . 'h1v1h-1z';
                }
            }
        }
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" viewBox="0 0 ' . $n . ' ' . $n . '" width="' . ($n * 10) . '" height="' . ($n * 10) . '" shape-rendering="crispEdges">'
            . '<rect width="100%" height="100%" fill="' . $light . '"/>'
            . '<path d="' . $path . '" fill="' . $dark . '"/>'
            . '</svg>' . "\n";
    }

    /** @param int $scale píxeles por módulo (1–40) */
    public static function png(QrCode $qr, int $scale = 10, string $dark = '#111111', string $light = '#FFFFFF'): string
    {
        if ($scale < 1 || $scale > 40) {
            throw new InvalidArgumentException('Escala fuera de rango.');
        }
        $d = self::rgb($dark);
        $l = self::rgb($light);
        $modules = $qr->size() + self::MARGIN * 2;
        $px = $modules * $scale;

        $raw = '';
        for ($my = 0; $my < $modules; $my++) {
            $line = "\0"; // filtro "None"
            for ($mx = 0; $mx < $modules; $mx++) {
                $isDark = $qr->module($mx - self::MARGIN, $my - self::MARGIN);
                $line .= str_repeat($isDark ? $d : $l, $scale);
            }
            $raw .= str_repeat($line, $scale);
        }

        $ihdr = pack('NNCCCCC', $px, $px, 8, 2, 0, 0, 0); // 8 bits, RGB
        return "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', $ihdr)
            . self::chunk('IDAT', (string) gzcompress($raw, 9))
            . self::chunk('IEND', '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    private static function rgb(string $hex): string
    {
        self::assertColor($hex);
        return (string) hex2bin(substr($hex, 1));
    }

    private static function assertColor(string $hex): void
    {
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $hex)) {
            throw new InvalidArgumentException('Color inválido.');
        }
    }
}
