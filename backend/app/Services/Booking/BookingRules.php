<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Core\Exceptions\HttpException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Reglas puras de la vida de una reserva: estados, transiciones y políticas de la barbería.
 *
 * No toca la base de datos ni el reloj: recibe el estado y las horas (timestamps UTC) y decide.
 * BookingService y AgendaService las aplican dentro de su transacción, con la fila bloqueada;
 * así las mismas reglas se prueban aquí sin MySQL (tests/Unit/BookingRulesTest.php).
 *
 * Estados: pendiente → confirmada → completada | no_asistio, y cancelada desde cualquiera activa.
 */
final class BookingRules
{
    public const ESTADOS = ['pendiente', 'confirmada', 'completada', 'cancelada', 'no_asistio'];

    /** Estados que ocupan la agenda y todavía se pueden cambiar. */
    public const ACTIVOS = ['pendiente', 'confirmada'];

    /** Acciones del personal sobre una cita (panel y panel del barbero). */
    public const ACCIONES_PERSONAL = ['confirmar', 'iniciar', 'completar', 'no_asistio'];

    /** Días hacia atrás en que el personal puede corregir atendida ↔ inasistencia. */
    public const DIAS_CORRECCION = 7;

    /** Minutos antes del inicio desde los que se puede iniciar o completar la atención. */
    public const ATENCION_ANTICIPADA_MIN = 30;

    public static function isActive(string $estado): bool
    {
        return in_array($estado, self::ACTIVOS, true);
    }

    // ================================================================== políticas del cliente

    /**
     * ¿Puede cancelarse? Devuelve false si ya estaba cancelada (reintento idempotente: no se hace nada).
     * El personal no tiene plazo; el cliente, solo antes del inicio y del límite de la política.
     */
    public static function assertCancellable(string $estado, string $actor, BookingPolicy $policy, int $inicio, int $now): bool
    {
        if ($estado === 'cancelada') {
            return false;
        }
        if (!self::isActive($estado)) {
            throw new HttpException(409, 'booking_not_active', 'Esta cita ya no está activa y no se puede cancelar.');
        }
        if ($actor === 'cliente') {
            if ($inicio <= $now) {
                throw new HttpException(409, 'booking_started', 'La cita ya comenzó o pasó: no se puede cancelar en línea.');
            }
            if ($now > $inicio - $policy->cancelacionLimite * 60) {
                throw new HttpException(422, 'cancellation_window_closed', 'Ya no puedes cancelar en línea: el plazo vence ' . self::minutesText($policy->cancelacionLimite)
                    . ' antes de la cita. Comunícate con la barbería.', ['texto_politica' => $policy->texto]);
            }
        }
        return true;
    }

    /** Estado, plazo y número de reprogramaciones (el límite y el plazo solo aplican al cliente). */
    public static function assertReschedulable(string $estado, string $actor, BookingPolicy $policy, int $inicio, int $now, int $reprogramaciones): void
    {
        if (!self::isActive($estado)) {
            throw new HttpException(409, 'booking_not_active', 'Esta cita ya no está activa y no se puede reprogramar.');
        }
        if ($actor !== 'cliente') {
            return;
        }
        if ($now > $inicio - $policy->reprogramacionLimite * 60) {
            throw new HttpException(422, 'reschedule_window_closed', 'Ya no puedes reprogramar en línea: el plazo vence ' . self::minutesText($policy->reprogramacionLimite)
                . ' antes de la cita. Comunícate con la barbería.', ['texto_politica' => $policy->texto]);
        }
        if ($reprogramaciones >= $policy->maxReprogramaciones) {
            throw new HttpException(422, 'reschedule_limit', "Esta cita ya se reprogramó {$reprogramaciones} " . ($reprogramaciones === 1 ? 'vez' : 'veces')
                . '. Para otro cambio cancela y reserva de nuevo, o comunícate con la barbería.');
        }
    }

    /** El cliente confirma que asistirá: solo a una cita activa que aún no empieza. */
    public static function assertAttendanceConfirmable(string $estado, int $inicio, int $now): void
    {
        if (!self::isActive($estado) || $inicio <= $now) {
            throw new HttpException(409, 'booking_not_active', 'Solo puedes confirmar tu asistencia a una cita próxima y activa.');
        }
    }

    /**
     * Anticipación mínima y horizonte máximo de la política. $fecha es la fecha local (AAAA-MM-DD)
     * de la sucursal, cuya zona horaria es $tz.
     */
    public static function assertWithinPolicy(BookingPolicy $policy, int $start, string $fecha, string $tz, int $now): void
    {
        if ($start < $now + $policy->anticipacion * 60) {
            throw new HttpException(422, 'too_soon', $start <= $now
                ? 'Ese horario ya pasó. Elige otro.'
                : 'Las reservas en línea se hacen con al menos ' . self::minutesText($policy->anticipacion) . ' de anticipación.');
        }
        $max = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone($tz))->modify("+{$policy->horizonteDias} day")->format('Y-m-d');
        if ($fecha > $max) {
            throw new HttpException(422, 'too_far', "Puedes reservar con hasta {$policy->horizonteDias} días de anticipación.");
        }
    }

    // ================================================================== acciones del personal

    /**
     * Decide una acción del personal (confirmar | iniciar | completar | no_asistio).
     *
     * @param array{now:int, inicio:int, fin:int, atencion_iniciada:bool, tiene_cobros?:callable():bool} $c
     * @return array{estado:string, evento:string, correccion:bool, iniciar:bool}|null null = nada que hacer
     *         (la cita ya está en el estado pedido: un reintento no falla).
     *         iniciar = la acción marca el inicio de la atención (iniciar).
     */
    public static function staffTransition(string $accion, string $estado, array $c): ?array
    {
        $now = $c['now'];
        $activa = self::isActive($estado);
        $reciente = $c['inicio'] >= $now - self::DIAS_CORRECCION * 86400;
        $temprano = $now < $c['inicio'] - self::ATENCION_ANTICIPADA_MIN * 60;

        switch ($accion) {
            case 'confirmar':
                if ($estado === 'confirmada') {
                    return null;
                }
                if ($estado !== 'pendiente' || $c['fin'] <= $now) {
                    throw new HttpException(409, 'booking_not_active', 'Solo se confirma una cita pendiente que aún no termina.');
                }
                return ['estado' => 'confirmada', 'evento' => 'confirmada', 'correccion' => false, 'iniciar' => false];

            case 'iniciar':
                if ($c['atencion_iniciada'] && $activa) {
                    return null;
                }
                if (!$activa) {
                    throw new HttpException(409, 'booking_not_active', 'La cita no está activa.');
                }
                if ($temprano) {
                    throw new HttpException(422, 'too_early', 'Aún falta para esta cita: puedes iniciar la atención desde ' . self::ATENCION_ANTICIPADA_MIN . ' minutos antes.');
                }
                return ['estado' => 'confirmada', 'evento' => 'atencion_iniciada', 'correccion' => false, 'iniciar' => true];

            case 'completar':
                if ($estado === 'completada') {
                    return null;
                }
                $correccion = false;
                if ($activa) {
                    if ($temprano) {
                        throw new HttpException(422, 'too_early', 'Aún falta para esta cita: no se puede marcar como atendida.');
                    }
                } elseif ($estado === 'no_asistio' && $reciente) {
                    $correccion = true;
                } else {
                    throw new HttpException(409, 'booking_not_active', 'Esta cita no se puede marcar como atendida.');
                }
                return ['estado' => 'completada', 'evento' => 'completada', 'correccion' => $correccion, 'iniciar' => false];

            case 'no_asistio':
                if ($estado === 'no_asistio') {
                    return null;
                }
                $correccion = false;
                if ($activa) {
                    if ($now < $c['inicio']) {
                        throw new HttpException(422, 'too_early', 'La inasistencia se registra cuando ya pasó la hora de la cita.');
                    }
                    if ($c['atencion_iniciada']) {
                        throw new HttpException(409, 'attention_started', 'La atención ya se inició: marca la cita como atendida.');
                    }
                } elseif ($estado === 'completada' && $reciente) {
                    if (isset($c['tiene_cobros']) && ($c['tiene_cobros'])()) {
                        throw new HttpException(409, 'booking_has_payments', 'La cita tiene cobros registrados. Anúlalos antes de marcar la inasistencia.');
                    }
                    $correccion = true;
                } else {
                    throw new HttpException(409, 'booking_not_active', 'Esta cita no se puede marcar como inasistencia.');
                }
                return ['estado' => 'no_asistio', 'evento' => 'no_asistio', 'correccion' => $correccion, 'iniciar' => false];
        }
        throw new HttpException(404, 'not_found', 'Acción no encontrada.');
    }

    /** "2 horas", "1 día", "45 minutos". */
    public static function minutesText(int $min): string
    {
        if ($min % 1440 === 0 && $min >= 1440) {
            $d = intdiv($min, 1440);
            return $d === 1 ? '1 día' : "{$d} días";
        }
        if ($min % 60 === 0 && $min >= 60) {
            $h = intdiv($min, 60);
            return $h === 1 ? '1 hora' : "{$h} horas";
        }
        return "{$min} minutos";
    }
}
