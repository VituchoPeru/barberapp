<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Helpers\Media;
use App\Services\Marketplace\MarketplacePresenter;
use App\Services\Marketplace\OpeningHours;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class MarketplaceUnitTest extends TestCase
{
    private const TRAMOS = [
        ['dia_semana' => 1, 'hora_apertura' => '09:00', 'hora_cierre' => '13:00'],
        ['dia_semana' => 1, 'hora_apertura' => '15:00', 'hora_cierre' => '20:00'],
        ['dia_semana' => 6, 'hora_apertura' => '10:00', 'hora_cierre' => '14:00'],
    ];

    private function lima(string $limaLocal): DateTimeImmutable
    {
        return (new DateTimeImmutable($limaLocal, new DateTimeZone('America/Lima')))->setTimezone(new DateTimeZone('UTC'));
    }

    public function testOpenWithinSlot(): void
    {
        $s = OpeningHours::status(self::TRAMOS, 'America/Lima', $this->lima('2026-10-19 10:30')); // lunes
        $this->assertTrue($s['abierto']);
        $this->assertSame('13:00', $s['cierra_a']);
    }

    public function testClosedDuringBreakOpensLaterToday(): void
    {
        $s = OpeningHours::status(self::TRAMOS, 'America/Lima', $this->lima('2026-10-19 13:00'));
        $this->assertFalse($s['abierto'], 'La hora de cierre es exclusiva');
        $this->assertSame(['dia' => 1, 'dia_nombre' => 'lunes', 'hora' => '15:00', 'fecha' => '2026-10-19'], $s['proxima_apertura']);
    }

    public function testNextOpeningSkipsClosedDays(): void
    {
        $s = OpeningHours::status(self::TRAMOS, 'America/Lima', $this->lima('2026-10-19 21:00'));
        $this->assertSame('sábado', $s['proxima_apertura']['dia_nombre']);
        $this->assertSame('2026-10-24', $s['proxima_apertura']['fecha']);
        $s = OpeningHours::status(self::TRAMOS, 'America/Lima', $this->lima('2026-10-24 15:00')); // sábado tras cierre
        $this->assertSame('2026-10-26', $s['proxima_apertura']['fecha']);
    }

    public function testNoScheduleMeansNoOpening(): void
    {
        $s = OpeningHours::status([], 'America/Lima', $this->lima('2026-10-19 10:00'));
        $this->assertSame(['abierto' => false, 'cierra_a' => null, 'proxima_apertura' => null], $s);
    }

    public function testMediaUrlNeverReturnsUnsafeValues(): void
    {
        $previous = Config::get('media.url');
        Config::set('media.url', 'https://api.test/media');
        $this->assertSame('https://api.test/media/tenants/3/' . str_repeat('a', 32) . '.webp', Media::url('tenants/3/' . str_repeat('a', 32) . '.webp'));
        $this->assertSame('https://cdn.example/logo.png', Media::url('https://cdn.example/logo.png'));
        foreach ([null, '', 'javascript:alert(1)', 'http://inseguro/x.png', '../../.env', 'tenants/3/x.php', 'data:image/png;base64,AAA'] as $bad) {
            $this->assertNull(Media::url($bad), (string) $bad);
        }
        Config::set('media.url', $previous);
    }

    public function testContactLinksAreBuiltFromValidatedHandles(): void
    {
        $c = MarketplacePresenter::contact(['telefono' => '+51900000010', 'whatsapp' => '+51987654321', 'instagram' => 'mi.barberia', 'facebook' => null, 'tiktok' => 'corte_pe', 'sitio_web' => 'javascript:alert(1)']);
        $this->assertSame('https://wa.me/51987654321', $c['whatsapp_url']);
        $this->assertSame('https://www.instagram.com/mi.barberia/', $c['instagram_url']);
        $this->assertSame('https://www.tiktok.com/@corte_pe', $c['tiktok_url']);
        $this->assertNull($c['facebook_url']);
        $this->assertNull($c['sitio_web']);
    }

    public function testBookingUrl(): void
    {
        $previous = Config::get('app.public_booking_url');
        Config::set('app.public_booking_url', 'https://app.barberapp.pe/');
        try {
            $this->assertSame('https://app.barberapp.pe/b/mi-barberia', MarketplacePresenter::bookingUrl('mi-barberia'));
        } finally {
            Config::set('app.public_booking_url', $previous);
        }
    }
}
