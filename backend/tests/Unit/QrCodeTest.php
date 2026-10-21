<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Qr\QrCode;
use App\Services\Qr\QrRenderer;
use App\Services\Qr\ReedSolomon;
use PHPUnit\Framework\TestCase;

final class QrCodeTest extends TestCase
{
    public function testChoosesSmallestVersion(): void
    {
        $this->assertSame(1, QrCode::encode('hola', 'M')->version());
        $this->assertSame(21, QrCode::encode('hola', 'M')->size());
        // 52 bytes en nivel M requieren versión 4 (capacidad 62 bytes; la 3 admite 42).
        $this->assertSame(4, QrCode::encode(str_repeat('a', 52), 'M')->version());
    }

    public function testFinderPatternsArePresent(): void
    {
        $qr = QrCode::encode('https://app.barberapp.pe/b/demo', 'M');
        $n = $qr->size();
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$x0, $y0]) {
            for ($i = 0; $i < 7; $i++) {
                $this->assertTrue($qr->module($x0 + $i, $y0), 'borde superior');
                $this->assertTrue($qr->module($x0 + $i, $y0 + 6), 'borde inferior');
            }
            $this->assertFalse($qr->module($x0 + 1, $y0 + 1));
            $this->assertTrue($qr->module($x0 + 3, $y0 + 3), 'centro');
        }
        $this->assertTrue($qr->module(8, $n - 8), 'módulo oscuro fijo');
    }

    public function testReedSolomonKnownVector(): void
    {
        // Ejemplo de la norma ISO/IEC 18004 (versión 1-M, "01234567"): 16 datos → 10 de corrección.
        $data = [0x10, 0x20, 0x0C, 0x56, 0x61, 0x80, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11];
        $ecc = ReedSolomon::remainder($data, ReedSolomon::divisor(10));
        $this->assertSame([0xA5, 0x24, 0xD4, 0xC1, 0xED, 0x36, 0xC7, 0x87, 0x2C, 0x55], $ecc);
    }

    public function testRejectsTooLongText(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QrCode::encode(str_repeat('x', 3000), 'H');
    }

    public function testRenderersProduceValidImages(): void
    {
        $qr = QrCode::encode('https://app.barberapp.pe/b/demo-centro?o=qr');
        $svg = QrRenderer::svg($qr);
        $this->assertNotFalse(simplexml_load_string($svg));
        $png = QrRenderer::png($qr, 5);
        $info = getimagesizefromstring($png);
        $this->assertSame(($qr->size() + 8) * 5, $info[0]);
        $this->assertSame('image/png', $info['mime']);
    }

    /** Verificación real: un lector QR (zbarimg) debe leer exactamente el texto. */
    public function testDecodesWithRealReader(): void
    {
        $bin = trim((string) @shell_exec('command -v zbarimg 2>/dev/null'));
        if ($bin === '') {
            $this->markTestSkipped('zbarimg no instalado (apt-get install zbar-tools).');
        }
        foreach (['L', 'M', 'Q', 'H'] as $ecl) {
            foreach (['https://app.barberapp.pe/b/barberia-demo-centro?o=qr', str_repeat('BARBERAPP-', 30)] as $text) {
                $file = tempnam(sys_get_temp_dir(), 'qr') . '.png';
                file_put_contents($file, QrRenderer::png(QrCode::encode($text, $ecl), 4));
                $out = trim((string) shell_exec(escapeshellarg($bin) . ' -q --raw ' . escapeshellarg($file) . ' 2>/dev/null'));
                @unlink($file);
                $this->assertSame($text, $out, "Nivel {$ecl}");
            }
        }
    }
}
