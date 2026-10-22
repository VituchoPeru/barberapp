<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Core\Config;
use App\Helpers\Clock;
use App\Services\Booking\BookingPresenter;

/**
 * Textos de los avisos de una cita a partir de la fila de BookingRepository::SELECT_DETAIL.
 * Fechas siempre en la zona horaria de la sede (no la del servidor ni la del teléfono).
 */
final class BookingMessages
{
    /**
     * @param array<string, mixed>        $r        fila de la reserva
     * @param list<array<string, mixed>> $services reserva_servicios
     * @param array<string, string>       $extra    accion, motivo
     * @return array<string, string>
     */
    public static function vars(array $r, array $services, array $extra = []): array
    {
        $tz = (string) $r['zona_horaria'];
        $d = Clock::utcToLocal((string) $r['inicio_utc'], $tz);
        $dir = trim((string) ($r['sucursal_direccion'] ?? '') . (!empty($r['sucursal_distrito']) ? ', ' . $r['sucursal_distrito'] : ''), ', ');
        return [
            'barberia' => (string) $r['tenant_nombre'],
            'sucursal' => (string) $r['sucursal_nombre'],
            'direccion' => $dir,
            'barbero' => (string) $r['barbero_nombre'],
            'cliente' => trim((string) $r['cliente_nombres']),
            'fecha' => BookingPresenter::DIAS[(int) $d->format('N')] . ' ' . (int) $d->format('j') . ' de ' . BookingPresenter::MESES[(int) $d->format('n')],
            'hora' => $d->format('H:i'),
            'cuando' => self::relative((string) $r['inicio_utc'], $tz),
            'servicios' => implode(', ', array_map(static fn (array $s): string => (string) $s['nombre_servicio'], $services)),
            'codigo' => (string) $r['codigo'],
            'accion' => $extra['accion'] ?? '',
            'motivo' => $extra['motivo'] ?? '',
        ];
    }

    /** "hoy a las 16:00", "mañana a las 10:00" o "el lunes 3 de noviembre a las 10:00" (respecto de ahora). */
    public static function relative(string $inicioUtc, string $tz): string
    {
        $d = Clock::utcToLocal($inicioUtc, $tz);
        $hoy = Clock::nowUtc()->setTimezone($d->getTimezone())->format('Y-m-d');
        $manana = Clock::nowUtc()->setTimezone($d->getTimezone())->modify('+1 day')->format('Y-m-d');
        $fecha = $d->format('Y-m-d');
        if ($fecha === $hoy) {
            return 'hoy a las ' . $d->format('H:i');
        }
        if ($fecha === $manana) {
            return 'mañana a las ' . $d->format('H:i');
        }
        return 'el ' . BookingPresenter::DIAS[(int) $d->format('N')] . ' ' . (int) $d->format('j') . ' de ' . BookingPresenter::MESES[(int) $d->format('n')] . ' a las ' . $d->format('H:i');
    }

    /** Texto de acción del recordatorio según si la barbería pide confirmar y si ya confirmó. */
    public static function reminderAction(array $r, bool $pedirConfirmacion): string
    {
        if ($r['asistencia_confirmada_en'] !== null) {
            return 'Ya confirmaste tu asistencia. ¡Te esperamos!';
        }
        return $pedirConfirmacion
            ? '¿Vas a asistir? Confirma tu asistencia para que la barbería te espere.'
            : 'Si no puedes asistir, cancela o reprograma desde la app para liberar el horario.';
    }

    /** Debe ofrecerse el botón "Confirmar asistencia". */
    public static function asksConfirmation(array $r, bool $pedirConfirmacion): bool
    {
        return $pedirConfirmacion && $r['asistencia_confirmada_en'] === null && in_array($r['estado'], ['pendiente', 'confirmada'], true);
    }

    public static function frontend(string $path): string
    {
        return rtrim((string) Config::get('app.frontend_url', 'http://localhost'), '/') . '/' . ltrim($path, '/');
    }

    public static function bookingUrl(int $reservaId): string
    {
        return self::frontend('pages/cita.html?id=' . $reservaId);
    }

    public static function confirmUrl(string $token): string
    {
        // El token va en el fragmento (#): el navegador no lo envía al servidor web ni queda en sus registros.
        return self::frontend('pages/asistencia.html#t=' . $token);
    }
}
