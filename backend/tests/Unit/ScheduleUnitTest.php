<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\ValidationException;
use App\Services\Catalog\ServiceCatalog;
use App\Services\Schedule\Intervals;
use App\Services\Schedule\WeeklySchedule;
use App\Services\Schedule\WorkingTime;
use PHPUnit\Framework\TestCase;

/** Reglas de jornada (Fase 4): intervalos, validación semanal y jornada efectiva. */
final class ScheduleUnitTest extends TestCase
{
    /** Dos sucursales abiertas de lunes a sábado 09:00–20:00 (Lima). */
    private function branches(): array
    {
        $horario = [];
        for ($d = 1; $d <= 6; $d++) {
            $horario[] = [$d, '09:00', '20:00'];
        }
        return [
            1 => ['nombre' => 'Cercado', 'activo' => true, 'zona_horaria' => 'America/Lima', 'horario' => $horario],
            2 => ['nombre' => 'Miraflores', 'activo' => true, 'zona_horaria' => 'America/Lima', 'horario' => $horario],
            3 => ['nombre' => 'Cerrada', 'activo' => false, 'zona_horaria' => 'America/Lima', 'horario' => $horario],
        ];
    }

    private function fieldErrors(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return (array) $e->details()['fields'];
        }
        $this->fail('Se esperaba ValidationException.');
    }

    public function testIntervalOperations(): void
    {
        $this->assertSame([[0, 30], [60, 120]], Intervals::subtract([[0, 120]], [[30, 60]]));
        $this->assertSame([], Intervals::subtract([[0, 60]], [[0, 60]]));
        $this->assertSame([[0, 90]], Intervals::union([[0, 30], [30, 60], [50, 90]]));
        $this->assertSame([[30, 60]], Intervals::intersect([[0, 60]], [[30, 120]]));
        $this->assertFalse(Intervals::overlaps(0, 60, 60, 120), 'Intervalos contiguos no se solapan');
        $this->assertTrue(Intervals::overlaps(0, 61, 60, 120));
        $this->assertTrue(Intervals::covers([[540, 780], [780, 1200]], 600, 900), 'Tramos contiguos cubren como uno solo');
        $this->assertSame('09:05', Intervals::hhmm(545));
    }

    public function testValidWeekIsNormalizedAndSorted(): void
    {
        $r = WeeklySchedule::normalize([
            'tramos' => [
                ['dia_semana' => 2, 'hora_inicio' => '14:00', 'hora_fin' => '20:00', 'sucursal_id' => 2],
                ['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '19:00', 'sucursal_id' => 1],
                ['dia_semana' => 2, 'hora_inicio' => '09:00', 'hora_fin' => '13:00', 'sucursal_id' => 1],
            ],
            'descansos' => [['dia_semana' => 1, 'hora_inicio' => '13:00', 'hora_fin' => '14:00', 'motivo' => 'Almuerzo']],
        ], $this->branches());
        $this->assertSame([1, 2, 2], array_column($r['tramos'], 'dia_semana'));
        $this->assertSame(['09:00', '09:00', '14:00'], array_column($r['tramos'], 'hora_inicio'));
        $this->assertSame('Almuerzo', $r['descansos'][0]['motivo']);
    }

    public function testBarberCannotWorkInTwoBranchesAtTheSameTime(): void
    {
        $errors = $this->fieldErrors(fn () => WeeklySchedule::normalize(['tramos' => [
            ['dia_semana' => 3, 'hora_inicio' => '09:00', 'hora_fin' => '15:00', 'sucursal_id' => 1],
            ['dia_semana' => 3, 'hora_inicio' => '14:00', 'hora_fin' => '20:00', 'sucursal_id' => 2],
        ]], $this->branches()));
        $this->assertArrayHasKey('tramos.1', $errors);
        $this->assertStringContainsString('dos sucursales', $errors['tramos.1'][0]);

        // Contiguos (15:00 fin / 15:00 inicio) sí se permiten: pasa de una sede a otra.
        $ok = WeeklySchedule::normalize(['tramos' => [
            ['dia_semana' => 3, 'hora_inicio' => '09:00', 'hora_fin' => '15:00', 'sucursal_id' => 1],
            ['dia_semana' => 3, 'hora_inicio' => '15:00', 'hora_fin' => '20:00', 'sucursal_id' => 2],
        ]], $this->branches());
        $this->assertCount(2, $ok['tramos']);
    }

    public function testOverlapIsAllowedWhenValidityPeriodsDoNotIntersect(): void
    {
        $r = WeeklySchedule::normalize(['tramos' => [
            ['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '18:00', 'sucursal_id' => 1, 'vigente_hasta' => '2026-11-30'],
            ['dia_semana' => 1, 'hora_inicio' => '10:00', 'hora_fin' => '20:00', 'sucursal_id' => 2, 'vigente_desde' => '2026-12-01'],
        ]], $this->branches());
        $this->assertCount(2, $r['tramos']);
    }

    public function testShiftOutsideBranchHoursOrClosedDayIsRejected(): void
    {
        $errors = $this->fieldErrors(fn () => WeeklySchedule::normalize(['tramos' => [
            ['dia_semana' => 1, 'hora_inicio' => '08:00', 'hora_fin' => '12:00', 'sucursal_id' => 1],
            ['dia_semana' => 7, 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'sucursal_id' => 1],
            ['dia_semana' => 2, 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'sucursal_id' => 3],
            ['dia_semana' => 2, 'hora_inicio' => '12:00', 'hora_fin' => '10:00', 'sucursal_id' => 1],
            ['dia_semana' => 8, 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'sucursal_id' => 1],
            ['dia_semana' => 4, 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'sucursal_id' => 99],
        ]], $this->branches()));
        $this->assertStringContainsString('Fuera del horario', $errors['tramos.0'][0]);
        $this->assertStringContainsString('no abre', $errors['tramos.1'][0]);
        $this->assertStringContainsString('desactivada', $errors['tramos.2'][0]);
        $this->assertStringContainsString('posterior', $errors['tramos.3'][0]);
        $this->assertStringContainsString('Día inválido', $errors['tramos.4'][0]);
        $this->assertStringContainsString('no encontrada', $errors['tramos.5'][0]);
    }

    public function testBreakMustBeInsideAShiftAndNotOverlap(): void
    {
        $errors = $this->fieldErrors(fn () => WeeklySchedule::normalize([
            'tramos' => [['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '13:00', 'sucursal_id' => 1]],
            'descansos' => [
                ['dia_semana' => 1, 'hora_inicio' => '12:30', 'hora_fin' => '13:30'],
                ['dia_semana' => 2, 'hora_inicio' => '10:00', 'hora_fin' => '10:30'],
                ['dia_semana' => 1, 'hora_inicio' => '10:00', 'hora_fin' => '10:30'],
                ['dia_semana' => 1, 'hora_inicio' => '10:15', 'hora_fin' => '10:45'],
            ],
        ], $this->branches()));
        $this->assertArrayHasKey('descansos.0', $errors);
        $this->assertArrayHasKey('descansos.1', $errors);
        $this->assertArrayNotHasKey('descansos.2', $errors);
        $this->assertStringContainsString('Se cruza', $errors['descansos.3'][0]);
    }

    public function testWorkingWindowsSubtractBreaksAndBlocks(): void
    {
        // 2026-10-26 es lunes. Lima = UTC-5.
        $tramos = [['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '20:00', 'sucursal_id' => 1]];
        $descansos = [['dia_semana' => 1, 'hora_inicio' => '13:00', 'hora_fin' => '14:00']];
        $bloqueos = [['sucursal_id' => null, 'inicio_utc' => '2026-10-26 21:00:00', 'fin_utc' => '2026-10-26 22:00:00']]; // 16:00–17:00 local
        $w = WorkingTime::windows($tramos, $descansos, $this->branches(), $bloqueos, '2026-10-26', '2026-10-27');
        $this->assertSame(['09:00-13:00', '14:00-16:00', '17:00-20:00'], array_map(static fn ($x) => $x['inicio_local'] . '-' . $x['fin_local'], $w));
        $this->assertSame('2026-10-26 14:00:00', $w[0]['inicio_utc']);
        $this->assertSame(240 + 120 + 180, array_sum(array_column($w, 'minutos')));
    }

    public function testFullDayBlockAndBranchBlocksAndValidity(): void
    {
        $tramos = [
            ['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '13:00', 'sucursal_id' => 1],
            ['dia_semana' => 1, 'hora_inicio' => '14:00', 'hora_fin' => '20:00', 'sucursal_id' => 2],
            ['dia_semana' => 2, 'hora_inicio' => '09:00', 'hora_fin' => '20:00', 'sucursal_id' => 1, 'vigente_desde' => '2026-11-01'],
        ];
        // Bloqueo solo de la sucursal 2 el lunes completo: el tramo en la sucursal 1 sigue.
        $bloqueos = [['sucursal_id' => 2, 'inicio_utc' => '2026-10-26 05:00:00', 'fin_utc' => '2026-10-27 05:00:00']];
        $w = WorkingTime::windows($tramos, [], $this->branches(), $bloqueos, '2026-10-26', '2026-10-27');
        $this->assertCount(1, $w, 'El martes 27 aún no está vigente su jornada');
        $this->assertSame([1, '09:00', '13:00'], [$w[0]['sucursal_id'], $w[0]['inicio_local'], $w[0]['fin_local']]);

        // Bloqueo de todo el barbero (sucursal NULL) el día completo: sin ventanas.
        $w = WorkingTime::windows($tramos, [], $this->branches(), [['sucursal_id' => null, 'inicio_utc' => '2026-10-26 05:00:00', 'fin_utc' => '2026-10-27 05:00:00']], '2026-10-26', '2026-10-26');
        $this->assertSame([], $w);
    }

    public function testWindowsAreClippedToBranchHoursAndIgnoreInactiveBranches(): void
    {
        $branches = $this->branches();
        $branches[1]['horario'] = [[1, '10:00', '18:00']]; // la sucursal recortó su horario después
        unset($branches[2]); // sucursal desactivada (no se entrega a WorkingTime)
        $tramos = [
            ['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '20:00', 'sucursal_id' => 1],
            ['dia_semana' => 1, 'hora_inicio' => '06:00', 'hora_fin' => '08:00', 'sucursal_id' => 2],
        ];
        $w = WorkingTime::windows($tramos, [], $branches, [], '2026-10-26', '2026-10-26');
        $this->assertCount(1, $w);
        $this->assertSame(['10:00', '18:00'], [$w[0]['inicio_local'], $w[0]['fin_local']]);
    }

    public function testBranchTimezoneIsRespected(): void
    {
        $branches = [1 => ['nombre' => 'Madrid', 'activo' => true, 'zona_horaria' => 'Europe/Madrid', 'horario' => [[1, '09:00', '18:00']]]];
        $w = WorkingTime::windows([['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '10:00', 'sucursal_id' => 1]], [], $branches, [], '2026-10-26', '2026-10-26');
        $this->assertSame('2026-10-26 08:00:00', $w[0]['inicio_utc'], 'Madrid es UTC+1 tras el cambio de hora de octubre');
    }

    public function testServiceDurationAndPriceRules(): void
    {
        $this->assertNotNull(ServiceCatalog::durationError(0), 'Duración cero');
        $this->assertNotNull(ServiceCatalog::durationError(-15));
        $this->assertNotNull(ServiceCatalog::durationError(7), 'No múltiplo de 5');
        $this->assertNotNull(ServiceCatalog::durationError(605));
        $this->assertNotNull(ServiceCatalog::durationError('treinta'));
        $this->assertNull(ServiceCatalog::durationError(45));
        $this->assertNull(ServiceCatalog::durationError('45'));
        $this->assertNotNull(ServiceCatalog::priceError(-1), 'Precio negativo');
        $this->assertNotNull(ServiceCatalog::priceError('10.555'));
        $this->assertNotNull(ServiceCatalog::priceError('100000'));
        $this->assertNull(ServiceCatalog::priceError(0), 'Servicio gratuito permitido');
        $this->assertNull(ServiceCatalog::priceError('35.50'));
    }
}
