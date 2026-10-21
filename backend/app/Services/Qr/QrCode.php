<?php

declare(strict_types=1);

namespace App\Services\Qr;

use InvalidArgumentException;

/**
 * Generador de códigos QR (ISO/IEC 18004) sin dependencias externas.
 *
 * - Modo byte (UTF-8), versiones 1 a 40, niveles de corrección L, M, Q y H.
 * - Elige la versión mínima que admite el texto y la máscara con menor penalización.
 * - Basado en el algoritmo de referencia descrito en la norma (y en la implementación
 *   didáctica de Project Nayuki, licencia MIT).
 *
 *   $qr = QrCode::encode('https://app.barberapp.pe/b/mi-barberia', 'M');
 *   $qr->size();        // módulos por lado
 *   $qr->module($x, $y) // true = módulo oscuro
 */
final class QrCode
{
    private const ECC_ORDINAL = ['L' => 0, 'M' => 1, 'Q' => 2, 'H' => 3];
    private const ECC_FORMAT_BITS = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

    /** Bytes de corrección por bloque [nivel][versión] */
    private const ECC_CODEWORDS_PER_BLOCK = [
        [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];

    /** Número de bloques de corrección [nivel][versión] */
    private const NUM_ERROR_CORRECTION_BLOCKS = [
        [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    private int $size;

    /** @var list<list<bool>> [y][x] */
    private array $modules = [];

    /** @var list<list<bool>> módulos de función (no se enmascaran) */
    private array $isFunction = [];

    private function __construct(private int $version, private string $ecl)
    {
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->isFunction = array_fill(0, $this->size, $row);
    }

    public static function encode(string $text, string $ecl = 'M'): self
    {
        $ecl = strtoupper($ecl);
        if (!isset(self::ECC_ORDINAL[$ecl])) {
            throw new InvalidArgumentException('Nivel de corrección inválido (L, M, Q o H).');
        }
        $data = array_values(unpack('C*', $text) ?: []);
        $len = count($data);

        $version = null;
        for ($v = 1; $v <= 40; $v++) {
            $capacityBits = self::numDataCodewords($v, $ecl) * 8;
            $countBits = $v <= 9 ? 8 : 16;
            if (4 + $countBits + $len * 8 <= $capacityBits) {
                $version = $v;
                break;
            }
        }
        if ($version === null) {
            throw new InvalidArgumentException('El texto es demasiado largo para un código QR.');
        }

        // Flujo de bits: modo byte (0100) + longitud + datos + terminador + relleno.
        $bits = [];
        self::appendBits($bits, 0x4, 4);
        self::appendBits($bits, $len, $version <= 9 ? 8 : 16);
        foreach ($data as $byte) {
            self::appendBits($bits, $byte, 8);
        }
        $capacityBits = self::numDataCodewords($version, $ecl) * 8;
        self::appendBits($bits, 0, min(4, $capacityBits - count($bits)));
        self::appendBits($bits, 0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacityBits; $pad ^= 0xEC ^ 0x11) {
            self::appendBits($bits, $pad, 8);
        }
        $codewords = array_fill(0, intdiv(count($bits), 8), 0);
        foreach ($bits as $i => $bit) {
            $codewords[$i >> 3] |= $bit << (7 - ($i & 7));
        }

        $qr = new self($version, $ecl);
        $qr->drawFunctionPatterns();
        $qr->drawCodewords($qr->addEccAndInterleave($codewords));

        // Máscara con menor penalización.
        $best = 0;
        $minPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $qr->applyMask($mask);
            $qr->drawFormatBits($mask);
            $penalty = $qr->penalty();
            if ($penalty < $minPenalty) {
                $best = $mask;
                $minPenalty = $penalty;
            }
            $qr->applyMask($mask); // XOR: deshace la máscara
        }
        $qr->applyMask($best);
        $qr->drawFormatBits($best);
        $qr->isFunction = [];
        return $qr;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function module(int $x, int $y): bool
    {
        return $x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size && $this->modules[$y][$x];
    }

    // ------------------------------------------------------------------ construcción

    /** @param list<int> $bits */
    private static function appendBits(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }
        $this->drawFinder(3, 3);
        $this->drawFinder($this->size - 4, 3);
        $this->drawFinder(3, $this->size - 4);

        $positions = $this->alignmentPositions();
        $n = count($positions);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue; // se solapan con los patrones de búsqueda
                }
                $this->drawAlignment($positions[$i], $positions[$j]);
            }
        }
        $this->drawFormatBits(0); // reserva el área; se reescribe al elegir la máscara
        $this->drawVersion();
    }

    private function drawFinder(int $cx, int $cy): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $dist = max(abs($dx), abs($dy));
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
                    $this->setFunction($x, $y, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private function drawAlignment(int $cx, int $cy): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunction($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    /** @return list<int> */
    private function alignmentPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $num = intdiv($this->version, 7) + 2;
        $step = $this->version === 32 ? 26 : intdiv($this->version * 4 + $num * 2 + 1, $num * 2 - 2) * 2;
        $result = [6];
        for ($pos = $this->size - 7; count($result) < $num; $pos -= $step) {
            array_splice($result, 1, 0, [$pos]);
        }
        return $result;
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (self::ECC_FORMAT_BITS[$this->ecl] << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;

        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, (($bits >> $i) & 1) === 1);
        }
        $this->setFunction(8, 7, (($bits >> 6) & 1) === 1);
        $this->setFunction(8, 8, (($bits >> 7) & 1) === 1);
        $this->setFunction(7, 8, (($bits >> 8) & 1) === 1);
        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, (($bits >> $i) & 1) === 1);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($this->size - 1 - $i, 8, (($bits >> $i) & 1) === 1);
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $this->size - 15 + $i, (($bits >> $i) & 1) === 1);
        }
        $this->setFunction(8, $this->size - 8, true); // módulo oscuro fijo
    }

    private function drawVersion(): void
    {
        if ($this->version < 7) {
            return;
        }
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = ($this->version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) === 1;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunction($a, $b, $dark);
            $this->setFunction($b, $a, $dark);
        }
    }

    private static function numRawDataModules(int $ver): int
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = intdiv($ver, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) {
                $result -= 36;
            }
        }
        return $result;
    }

    private static function numDataCodewords(int $ver, string $ecl): int
    {
        $o = self::ECC_ORDINAL[$ecl];
        return intdiv(self::numRawDataModules($ver), 8)
            - self::ECC_CODEWORDS_PER_BLOCK[$o][$ver] * self::NUM_ERROR_CORRECTION_BLOCKS[$o][$ver];
    }

    /**
     * @param list<int> $data
     * @return list<int>
     */
    private function addEccAndInterleave(array $data): array
    {
        $o = self::ECC_ORDINAL[$this->ecl];
        $numBlocks = self::NUM_ERROR_CORRECTION_BLOCKS[$o][$this->version];
        $blockEccLen = self::ECC_CODEWORDS_PER_BLOCK[$o][$this->version];
        $rawCodewords = intdiv(self::numRawDataModules($this->version), 8);
        $numShortBlocks = $numBlocks - $rawCodewords % $numBlocks;
        $shortBlockLen = intdiv($rawCodewords, $numBlocks);

        $divisor = ReedSolomon::divisor($blockEccLen);
        $blocks = [];
        for ($i = 0, $k = 0; $i < $numBlocks; $i++) {
            $len = $shortBlockLen - $blockEccLen + ($i < $numShortBlocks ? 0 : 1);
            $dat = array_slice($data, $k, $len);
            $k += $len;
            $ecc = ReedSolomon::remainder($dat, $divisor);
            if ($i < $numShortBlocks) {
                $dat[] = 0; // hueco para igualar longitudes al intercalar
            }
            $blocks[] = array_merge($dat, $ecc);
        }

        $result = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortBlockLen - $blockEccLen || $j >= $numShortBlocks) {
                    $result[] = $block[$i];
                }
            }
        }
        return $result;
    }

    /** @param list<int> $codewords */
    private function drawCodewords(array $codewords): void
    {
        $i = 0;
        $total = count($codewords) * 8;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                switch ($mask) {
                    case 0: $invert = ($x + $y) % 2 === 0; break;
                    case 1: $invert = $y % 2 === 0; break;
                    case 2: $invert = $x % 3 === 0; break;
                    case 3: $invert = ($x + $y) % 3 === 0; break;
                    case 4: $invert = (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0; break;
                    case 5: $invert = $x * $y % 2 + $x * $y % 3 === 0; break;
                    case 6: $invert = ($x * $y % 2 + $x * $y % 3) % 2 === 0; break;
                    default: $invert = (($x + $y) % 2 + $x * $y % 3) % 2 === 0; break;
                }
                if ($invert && !$this->isFunction[$y][$x]) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /** Penalización de la norma (reglas N1 a N4). */
    private function penalty(): int
    {
        $n = $this->size;
        $result = 0;

        // N1 y N3 por filas y columnas
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $n; $a++) {
                $line = [];
                for ($b = 0; $b < $n; $b++) {
                    $line[] = $pass === 0 ? $this->modules[$a][$b] : $this->modules[$b][$a];
                }
                $run = 1;
                for ($b = 1; $b <= $n; $b++) {
                    if ($b < $n && $line[$b] === $line[$b - 1]) {
                        $run++;
                        continue;
                    }
                    if ($run >= 5) {
                        $result += 3 + ($run - 5);
                    }
                    $run = 1;
                }
                // Patrón 1:1:3:1:1 con 4 claros a un lado (fuera del símbolo cuenta como claro).
                for ($b = -4; $b < $n; $b++) {
                    $get = static fn (int $i): bool => $i >= 0 && $i < $n && $line[$i];
                    $core = $get($b + 4) && !$get($b + 5) && $get($b + 6) && $get($b + 7) && $get($b + 8) && !$get($b + 9) && $get($b + 10);
                    if (!$core) {
                        continue;
                    }
                    $before = !$get($b) && !$get($b + 1) && !$get($b + 2) && !$get($b + 3);
                    $after = !$get($b + 11) && !$get($b + 12) && !$get($b + 13) && !$get($b + 14);
                    if ($before || $after) {
                        $result += 40;
                    }
                }
            }
        }

        // N2: bloques 2x2 del mismo color
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $this->modules[$y][$x];
                if ($c === $this->modules[$y][$x + 1] && $c === $this->modules[$y + 1][$x] && $c === $this->modules[$y + 1][$x + 1]) {
                    $result += 3;
                }
            }
        }

        // N4: proporción de módulos oscuros
        $dark = 0;
        foreach ($this->modules as $row) {
            foreach ($row as $m) {
                $dark += $m ? 1 : 0;
            }
        }
        $total = $n * $n;
        $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
        return $result + max(0, $k) * 10;
    }
}
