<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Booking\Availability;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingPolicy;
use App\Services\Booking\BookingService;
use App\Core\Exceptions\ValidationException;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/** Cálculo de horarios disponibles (Fase 5), sin base de datos. */
final class AvailabilityUnitTest extends TestCase
{
    private const DAY = '2030-03-06'; // miércoles

    private function ts(string $hhmm): int
    {
        return (new DateTimeImmutable(self::DAY . ' ' . $hhmm, new DateTimeZone('America/Lima')))->getTimestamp();
    }

    private function origin(): int
    {
        return $this->ts('00:00');
    }

    /** @param list<int> $slots @return list<string> */
    private function hhmm(array $slots): array
    {
        return array_map(static fn (int $t): string => (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('America/Lima'))->format('H:i'), $slots);
    }

    public function testThirtyMinuteServiceOnFifteenMinuteGridFitsInsideWindow(): void
    {
        $w = [[$this->ts('09:00'), $this->ts('11:00')]];
        $slots = $this->hhmm(Availability::slots($w, [], 30, 15, 0, 0, $this->origin()));
        $this->assertSame(['09:00', '09:15', '09:30', '09:45', '10:00', '10:15', '10:30'], $slots, 'La última cita termina justo al cierre');
    }

    public function testFortyFiveMinuteServiceNeverCrossesABreak(): void
    {
        // Jornada partida 09–13 y 14–16: un servicio de 45 min no puede empezar a las 12:30.
        $w = [[$this->ts('09:00'), $this->ts('13:00')], [$this->ts('14:00'), $this->ts('16:00')]];
        $slots = $this->hhmm(Availability::slots($w, [], 45, 30, 0, 0, $this->origin()));
        $this->assertSame(['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '14:00', '14:30', '15:00'], $slots);
        $this->assertNotContains('12:30', $slots);
        $this->assertNotContains('15:30', $slots);
    }

    public function testBusyIntervalsAndTheGapLeftAfterAFortyFiveMinuteBooking(): void
    {
        // Cita 09:00–09:45 en grilla de 30: se ofrece 09:45 (inicio del hueco) además de 10:00.
        $w = [[$this->ts('09:00'), $this->ts('11:00')]];
        $busy = [[$this->ts('09:00'), $this->ts('09:45')]];
        $slots = $this->hhmm(Availability::slots($w, $busy, 30, 30, 0, 0, $this->origin()));
        $this->assertSame(['09:45', '10:00', '10:30'], $slots);
    }

    public function testPartialOverlapIsNeverOffered(): void
    {
        $w = [[$this->ts('09:00'), $this->ts('12:00')]];
        $busy = [[$this->ts('10:00'), $this->ts('10:45')]];
        $slots = $this->hhmm(Availability::slots($w, $busy, 30, 15, 0, 0, $this->origin()));
        foreach (['09:45', '10:00', '10:15', '10:30'] as $h) {
            $this->assertNotContains($h, $slots, "{$h} se cruzaría con la cita de 10:00–10:45");
        }
        $this->assertContains('09:30', $slots, '09:30–10:00 solo toca la cita, no se cruza');
        $this->assertContains('10:45', $slots);
    }

    public function testMarginBetweenAppointmentsAppliesBeforeAndAfter(): void
    {
        $w = [[$this->ts('09:00'), $this->ts('12:00')]];
        $busy = [[$this->ts('10:00'), $this->ts('10:30')]];
        $slots = $this->hhmm(Availability::slots($w, $busy, 30, 15, 10, 0, $this->origin()));
        $this->assertNotContains('09:30', $slots, '09:30–10:00 deja 0 min antes de la cita (margen 10)');
        $this->assertContains('09:15', $slots);
        $this->assertSame('10:40', $slots[array_search('09:15', $slots, true) + 1], 'Tras la cita y el margen el primer hueco es 10:40');
    }

    public function testMinimumNoticeFiltersEarlySlots(): void
    {
        $w = [[$this->ts('09:00'), $this->ts('11:00')]];
        $slots = $this->hhmm(Availability::slots($w, [], 30, 30, 0, $this->ts('09:50'), $this->origin()));
        $this->assertSame(['10:00', '10:30'], $slots);
    }

    public function testBusyFromRowsExcludesTheBookingBeingRescheduled(): void
    {
        $rows = [
            ['id' => 7, 'inicio_utc' => '2030-03-06 15:00:00', 'fin_utc' => '2030-03-06 15:30:00'],
            ['id' => 8, 'inicio_utc' => '2030-03-06 16:00:00', 'fin_utc' => '2030-03-06 16:45:00'],
        ];
        $this->assertCount(2, Availability::busy($rows));
        $this->assertSame([[Availability::ts('2030-03-06 16:00:00'), Availability::ts('2030-03-06 16:45:00')]], Availability::busy($rows, 7));
    }

    public function testCombinedServicesUseTheBarbersOwnDurationAndPrice(): void
    {
        $cand = ['servicios' => [1 => ['duracion' => 45, 'precio' => '40.00'], 2 => ['duracion' => 20, 'precio' => '15.50']]];
        $services = [['id' => 1], ['id' => 2]];
        $this->assertSame(65, AvailabilityService::duration($cand, $services));
        $this->assertSame('55.50', AvailabilityService::price($cand, $services));
    }

    public function testServiceListValidation(): void
    {
        $this->assertSame([3, 1], AvailabilityService::serviceIds('3,1'));
        $this->assertSame([2], AvailabilityService::serviceIds([2]));
        foreach ([[], '', [1, 1], ['x'], [0], [1, 2, 3, 4, 5, 6], null] as $bad) {
            try {
                AvailabilityService::serviceIds($bad);
                $this->fail('Se esperaba error para ' . json_encode($bad));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('servicio_ids', $e->details()['fields']);
            }
        }
    }

    public function testPolicyDefaultsAndText(): void
    {
        $p = BookingPolicy::fromRow(null);
        $this->assertSame(15, $p->intervalo);
        $this->assertTrue($p->permiteCualquierBarbero);
        $p = BookingPolicy::fromRow(['intervalo_minutos' => 30, 'confirmacion_automatica' => 0, 'sucursal_id' => 4] + BookingPolicy::DEFAULTS);
        $this->assertSame(30, $p->intervalo);
        $this->assertFalse($p->confirmacionAutomatica);
        $this->assertSame(4, $p->sucursalId);
        $this->assertSame('2 horas', BookingService::minutesText(120));
        $this->assertSame('1 día', BookingService::minutesText(1440));
        $this->assertSame('90 minutos', BookingService::minutesText(90));
    }
}
