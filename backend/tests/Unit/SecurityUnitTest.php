<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Request;
use App\Helpers\TrustedProxies;
use App\Services\Backup\BackupCipher;
use App\Services\Privacy\AccountService;
use App\Services\Privacy\BusinessDays;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Fase 13: días hábiles, proxies de confianza, cifrado de copias y ocultar correos. */
final class SecurityUnitTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::set('security.trusted_proxies', []);
    }

    public function testBusinessDaysSkipWeekendsAndPeruvianHolidays(): void
    {
        $this->assertSame('2026-04-05', BusinessDays::easter(2026)->format('Y-m-d'));
        $this->assertSame('2027-03-28', BusinessDays::easter(2027)->format('Y-m-d'));
        $this->assertTrue(BusinessDays::isHoliday(new DateTimeImmutable('2026-04-02'))); // Jueves Santo
        $this->assertTrue(BusinessDays::isHoliday(new DateTimeImmutable('2026-07-28')));
        // Jueves 22-10-2026 + 10 días hábiles: el 1-11 es domingo; vence el jueves 5-11.
        $this->assertSame('2026-11-05', BusinessDays::add(new DateTimeImmutable('2026-10-22T15:00:00Z'), 10)->format('Y-m-d'));
        // Viernes 24-07-2026 + 3: saltan sábado, domingo, 28 y 29 (feriados).
        $this->assertSame('2026-07-31', BusinessDays::add(new DateTimeImmutable('2026-07-24T15:00:00Z'), 3)->format('Y-m-d'));
    }

    public function testTrustedProxyRanges(): void
    {
        $this->assertTrue(TrustedProxies::inRange('10.1.2.3', '10.0.0.0/8'));
        $this->assertFalse(TrustedProxies::inRange('11.1.2.3', '10.0.0.0/8'));
        $this->assertTrue(TrustedProxies::inRange('192.168.1.129', '192.168.1.128/25'));
        $this->assertFalse(TrustedProxies::inRange('192.168.1.127', '192.168.1.128/25'));
        $this->assertTrue(TrustedProxies::inRange('2001:db8::5', '2001:db8::/32'));
        $this->assertFalse(TrustedProxies::inRange('10.0.0.1', '2001:db8::/32'));
        $this->assertFalse(TrustedProxies::inRange('10.0.0.1', '10.0.0.0/99'));
        $this->assertFalse(TrustedProxies::inRange('10.0.0.1', 'basura'));

        Config::set('security.trusted_proxies', ['10.0.0.0/8']);
        // Se toma la primera IP no confiable desde la derecha: un cliente no puede anteponer una IP falsa.
        $this->assertSame('198.51.100.9', TrustedProxies::clientIp('10.0.0.2', '1.2.3.4, 198.51.100.9, 10.0.0.7'));
        $this->assertSame('203.0.113.5', TrustedProxies::clientIp('203.0.113.5', '1.2.3.4'), 'Desde una IP no confiable se ignora la cabecera');
        $this->assertSame('10.0.0.2', TrustedProxies::clientIp('10.0.0.2', 'no-es-ip'));

        $req = new Request('GET', '/', [], ['X-Forwarded-Proto' => 'https'], '', ['REMOTE_ADDR' => '10.0.0.2']);
        $this->assertTrue($req->isSecure());
        $req = new Request('GET', '/', [], ['X-Forwarded-Proto' => 'https'], '', ['REMOTE_ADDR' => '8.8.8.8']);
        $this->assertFalse($req->isSecure());
        $this->assertTrue((new Request('GET', '/', [], [], '', ['HTTPS' => 'on']))->isSecure());
    }

    public function testBackupCipherRoundTripAndTamperDetection(): void
    {
        $dir = sys_get_temp_dir() . '/bbk-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $key = BackupCipher::parseKey(BackupCipher::generateKey());
        try {
            foreach ([0, 10, 1048576, 1048576 * 2 + 5] as $size) {
                $plain = $size === 0 ? '' : random_bytes($size);
                file_put_contents("$dir/in", $plain);
                @unlink("$dir/enc");
                @unlink("$dir/out");
                BackupCipher::encryptFile("$dir/in", "$dir/enc", $key);
                $this->assertTrue(BackupCipher::isEncrypted("$dir/enc"));
                BackupCipher::decryptFile("$dir/enc", "$dir/out", $key);
                $this->assertSame(hash('sha256', $plain), hash_file('sha256', "$dir/out"), "tamaño {$size}");
            }
            // Truncar el último bloque se detecta.
            $enc = (string) file_get_contents("$dir/enc");
            file_put_contents("$dir/enc", substr($enc, 0, strlen($enc) - 1048600));
            @unlink("$dir/out");
            try {
                BackupCipher::decryptFile("$dir/enc", "$dir/out", $key);
                $this->fail('Una copia truncada no debe descifrarse');
            } catch (RuntimeException $e) {
                $this->assertFileDoesNotExist("$dir/out");
            }
            $this->expectException(RuntimeException::class);
            BackupCipher::parseKey('base64:' . base64_encode('corta'));
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }

    public function testEmailMask(): void
    {
        $this->assertSame('l******@ejemplo.pe', AccountService::mask('lucia.quispe@ejemplo.pe'));
        $this->assertSame('a**@x.pe', AccountService::mask('ab@x.pe'));
    }
}
