<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\HttpException;
use App\Services\Booking\BookingPolicy;
use App\Services\Booking\BookingRules;
use PHPUnit\Framework\TestCase;

/**
 * Fase 14 — unitarias de "validación de políticas" y "control de estados" de una reserva.
 * Reglas puras: sin base de datos ni reloj real.
 */
final class BookingRulesTest extends TestCase
{
    private const NOW = 1_800_000_000; // instante fijo (UTC)
    private const H = 3600;

    private function policy(array $over = []): BookingPolicy
    {
        return BookingPolicy::fromRow($over);
    }

    /** Ejecuta y devuelve el código de error HTTP ("ok" si no falla). */
    private function code(callable $fn): string
    {
        try {
            $fn();
            return 'ok';
        } catch (HttpException $e) {
            return $e->status() . ' ' . $e->errorCode();
        }
    }

    // ================================================================== estados

    public function testStatesMatchTheDatabaseEnum(): void
    {
        $this->assertSame(['pendiente', 'confirmada', 'completada', 'cancelada', 'no_asistio'], BookingRules::ESTADOS);
        foreach (BookingRules::ESTADOS as $estado) {
            $this->assertSame(in_array($estado, ['pendiente', 'confirmada'], true), BookingRules::isActive($estado), $estado);
        }
        $this->assertFalse(BookingRules::isActive('inventado'));
    }

    /**
     * Matriz completa estado × acción del personal, con la cita en curso (empezó hace 10 min),
     * sin atención iniciada y sin cobros.
     */
    public function testStaffTransitionMatrixDuringTheAppointment(): void
    {
        $ctx = ['now' => self::NOW, 'inicio' => self::NOW - 600, 'fin' => self::NOW + 1800, 'atencion_iniciada' => false, 'tiene_cobros' => static fn (): bool => false];
        $esperado = [
            // estado        confirmar                   iniciar                     completar                   no_asistio
            'pendiente'  => ['confirmada',              'confirmada',               'completada',               'no_asistio'],
            'confirmada' => ['noop',                    'confirmada',               'completada',               'no_asistio'],
            'completada' => ['409 booking_not_active',  '409 booking_not_active',   'noop',                     'no_asistio*'],
            'cancelada'  => ['409 booking_not_active',  '409 booking_not_active',   '409 booking_not_active',   '409 booking_not_active'],
            'no_asistio' => ['409 booking_not_active',  '409 booking_not_active',   'completada*',              'noop'],
        ];
        foreach ($esperado as $estado => $fila) {
            foreach (BookingRules::ACCIONES_PERSONAL as $i => $accion) {
                $this->assertSame($fila[$i], $this->outcome($accion, $estado, $ctx), "{$estado} + {$accion}");
            }
        }
    }

    public function testStaffCannotActTooEarlyOrMarkNoShowBeforeTheTime(): void
    {
        // Faltan 2 horas: se puede confirmar, pero no iniciar, completar ni marcar inasistencia.
        $ctx = ['now' => self::NOW, 'inicio' => self::NOW + 2 * self::H, 'fin' => self::NOW + 3 * self::H, 'atencion_iniciada' => false];
        $this->assertSame('confirmada', $this->outcome('confirmar', 'pendiente', $ctx));
        $this->assertSame('422 too_early', $this->outcome('iniciar', 'confirmada', $ctx));
        $this->assertSame('422 too_early', $this->outcome('completar', 'confirmada', $ctx));
        $this->assertSame('422 too_early', $this->outcome('no_asistio', 'confirmada', $ctx));

        // Desde 30 minutos antes ya se puede iniciar y completar; la inasistencia, recién a la hora.
        $ctx['inicio'] = self::NOW + BookingRules::ATENCION_ANTICIPADA_MIN * 60;
        $this->assertSame('confirmada', $this->outcome('iniciar', 'confirmada', $ctx));
        $this->assertSame('completada', $this->outcome('completar', 'confirmada', $ctx));
        $this->assertSame('422 too_early', $this->outcome('no_asistio', 'confirmada', $ctx));
    }

    public function testStartedAttentionAndPaymentsBlockNoShow(): void
    {
        $ctx = ['now' => self::NOW, 'inicio' => self::NOW - 600, 'fin' => self::NOW + 1200, 'atencion_iniciada' => true];
        $this->assertSame('409 attention_started', $this->outcome('no_asistio', 'confirmada', $ctx));
        $this->assertSame('noop', $this->outcome('iniciar', 'confirmada', $ctx), 'Iniciar dos veces no falla');

        $ctx['tiene_cobros'] = static fn (): bool => true;
        $this->assertSame('409 booking_has_payments', $this->outcome('no_asistio', 'completada', $ctx));
    }

    public function testCorrectionsOnlyWithinSevenDays(): void
    {
        $base = ['now' => self::NOW, 'atencion_iniciada' => false];
        $hace6 = $base + ['inicio' => self::NOW - 6 * 86400, 'fin' => self::NOW - 6 * 86400 + 1800];
        $hace8 = $base + ['inicio' => self::NOW - 8 * 86400, 'fin' => self::NOW - 8 * 86400 + 1800];
        $this->assertSame('completada*', $this->outcome('completar', 'no_asistio', $hace6));
        $this->assertSame('no_asistio*', $this->outcome('no_asistio', 'completada', $hace6));
        $this->assertSame('409 booking_not_active', $this->outcome('completar', 'no_asistio', $hace8));
        $this->assertSame('409 booking_not_active', $this->outcome('no_asistio', 'completada', $hace8));
        // Una cita pendiente que ya terminó no se "confirma": se marca atendida o inasistencia.
        $this->assertSame('409 booking_not_active', $this->outcome('confirmar', 'pendiente', $hace6));
    }

    public function testUnknownActionIsNotFound(): void
    {
        $this->assertSame('404 not_found', $this->outcome('borrar', 'pendiente', ['now' => self::NOW, 'inicio' => self::NOW, 'fin' => self::NOW + 60, 'atencion_iniciada' => false]));
    }

    // ================================================================== políticas del cliente

    public function testClientCancellationWindow(): void
    {
        $p = $this->policy(['cancelacion_limite_minutos' => 120]);
        $this->assertSame('ok', $this->code(fn () => BookingRules::assertCancellable('confirmada', 'cliente', $p, self::NOW + 121 * 60, self::NOW)));
        $this->assertSame('ok', $this->code(fn () => BookingRules::assertCancellable('pendiente', 'cliente', $p, self::NOW + 120 * 60, self::NOW)), 'Justo en el límite todavía se puede');
        $this->assertSame('422 cancellation_window_closed', $this->code(fn () => BookingRules::assertCancellable('confirmada', 'cliente', $p, self::NOW + 119 * 60, self::NOW)));
        $this->assertSame('409 booking_started', $this->code(fn () => BookingRules::assertCancellable('confirmada', 'cliente', $p, self::NOW, self::NOW)));
        // El personal no tiene plazo, pero tampoco puede cancelar una cita terminada.
        $this->assertSame('ok', $this->code(fn () => BookingRules::assertCancellable('confirmada', 'personal', $p, self::NOW - 600, self::NOW)));
        $this->assertSame('409 booking_not_active', $this->code(fn () => BookingRules::assertCancellable('completada', 'personal', $p, self::NOW + 9 * self::H, self::NOW)));
        // Cancelar dos veces no falla: devuelve false (nada que hacer).
        $this->assertFalse(BookingRules::assertCancellable('cancelada', 'cliente', $p, self::NOW - 600, self::NOW));
        $this->assertTrue(BookingRules::assertCancellable('pendiente', 'cliente', $p, self::NOW + 9 * self::H, self::NOW));
    }

    public function testCancellationErrorCarriesThePolicyText(): void
    {
        $p = $this->policy(['cancelacion_limite_minutos' => 1440, 'texto_politica_cancelacion' => 'Avísanos con un día.']);
        try {
            BookingRules::assertCancellable('confirmada', 'cliente', $p, self::NOW + 3 * self::H, self::NOW);
            $this->fail('Debía rechazarse');
        } catch (HttpException $e) {
            $this->assertStringContainsString('1 día antes', $e->getMessage());
            $this->assertSame(['texto_politica' => 'Avísanos con un día.'], $e->details());
        }
    }

    public function testClientRescheduleWindowAndLimit(): void
    {
        $p = $this->policy(['reprogramacion_limite_minutos' => 60, 'max_reprogramaciones' => 2]);
        $lejos = self::NOW + 5 * self::H;
        $this->assertSame('ok', $this->code(fn () => BookingRules::assertReschedulable('confirmada', 'cliente', $p, $lejos, self::NOW, 1)));
        $this->assertSame('422 reschedule_limit', $this->code(fn () => BookingRules::assertReschedulable('confirmada', 'cliente', $p, $lejos, self::NOW, 2)));
        $this->assertSame('422 reschedule_window_closed', $this->code(fn () => BookingRules::assertReschedulable('confirmada', 'cliente', $p, self::NOW + 59 * 60, self::NOW, 0)));
        $this->assertSame('409 booking_not_active', $this->code(fn () => BookingRules::assertReschedulable('cancelada', 'cliente', $p, $lejos, self::NOW, 0)));
        // El personal puede reprogramar sin plazo ni límite.
        $this->assertSame('ok', $this->code(fn () => BookingRules::assertReschedulable('pendiente', 'personal', $p, self::NOW + 60, self::NOW, 9)));
        $this->assertSame('409 booking_not_active', $this->code(fn () => BookingRules::assertReschedulable('no_asistio', 'personal', $p, $lejos, self::NOW, 0)));
    }

    public function testAttendanceConfirmationOnlyForUpcomingActiveBookings(): void
    {
        $this->assertSame('ok', $this->code(fn () => BookingRules::assertAttendanceConfirmable('pendiente', self::NOW + 60, self::NOW)));
        $this->assertSame('ok', $this->code(fn () => BookingRules::assertAttendanceConfirmable('confirmada', self::NOW + 60, self::NOW)));
        $this->assertSame('409 booking_not_active', $this->code(fn () => BookingRules::assertAttendanceConfirmable('confirmada', self::NOW, self::NOW)));
        foreach (['completada', 'cancelada', 'no_asistio'] as $estado) {
            $this->assertSame('409 booking_not_active', $this->code(fn () => BookingRules::assertAttendanceConfirmable($estado, self::NOW + self::H, self::NOW)), $estado);
        }
    }

    public function testMinimumNoticeAndHorizonUseTheBranchTimezone(): void
    {
        $p = $this->policy(['anticipacion_minima_minutos' => 60, 'horizonte_maximo_dias' => 30]);
        $tz = 'America/Lima';
        $this->assertSame('422 too_soon', $this->code(fn () => BookingRules::assertWithinPolicy($p, self::NOW + 59 * 60, '2027-01-15', $tz, self::NOW)));
        $this->assertSame('ok', $this->code(fn () => BookingRules::assertWithinPolicy($p, self::NOW + 60 * 60, '2027-01-15', $tz, self::NOW)));
        try {
            BookingRules::assertWithinPolicy($p, self::NOW - 60, '2027-01-15', $tz, self::NOW);
            $this->fail('Un horario pasado debe rechazarse');
        } catch (HttpException $e) {
            $this->assertSame('Ese horario ya pasó. Elige otro.', $e->getMessage());
        }

        // 2026-12-31 23:30 en Lima = 2027-01-01 04:30 UTC. El horizonte se cuenta desde la fecha de Lima.
        $now = (new \DateTimeImmutable('2027-01-01 04:30:00', new \DateTimeZone('UTC')))->getTimestamp();
        $start = $now + 10 * 86400;
        $this->assertSame('ok', $this->code(fn () => BookingRules::assertWithinPolicy($p, $start, '2027-01-30', $tz, $now)));
        $this->assertSame('422 too_far', $this->code(fn () => BookingRules::assertWithinPolicy($p, $start, '2027-01-31', $tz, $now)));
    }

    public function testMinutesText(): void
    {
        $this->assertSame('45 minutos', BookingRules::minutesText(45));
        $this->assertSame('1 hora', BookingRules::minutesText(60));
        $this->assertSame('90 minutos', BookingRules::minutesText(90));
        $this->assertSame('2 horas', BookingRules::minutesText(120));
        $this->assertSame('1 día', BookingRules::minutesText(1440));
        $this->assertSame('3 días', BookingRules::minutesText(4320));
    }

    /** "noop", nuevo estado (con * si es corrección) o "status código". */
    private function outcome(string $accion, string $estado, array $ctx): string
    {
        try {
            $r = BookingRules::staffTransition($accion, $estado, $ctx);
        } catch (HttpException $e) {
            return $e->status() . ' ' . $e->errorCode();
        }
        return $r === null ? 'noop' : $r['estado'] . ($r['correccion'] ? '*' : '');
    }
}
