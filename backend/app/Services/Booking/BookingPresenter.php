<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Helpers\Clock;
use App\Helpers\Media;
use DateTimeImmutable;
use DateTimeZone;

/** Representación de una reserva para el cliente (comprobante, listado y detalle). */
final class BookingPresenter
{
    private const DIAS = [1 => 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
    private const MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /**
     * @param array<string, mixed>            $r        fila de BookingRepository::SELECT_DETAIL
     * @param list<array<string, mixed>>      $services filas de reserva_servicios
     * @return array<string, mixed>
     */
    public static function present(array $r, array $services, BookingPolicy $policy): array
    {
        $tz = (string) $r['zona_horaria'];
        $ini = Clock::utcToLocal((string) $r['inicio_utc'], $tz);
        $fin = Clock::utcToLocal((string) $r['fin_utc'], $tz);
        return [
            'id' => (int) $r['id'],
            'codigo' => (string) $r['codigo'],
            'estado' => (string) $r['estado'],
            'barberia' => [
                'slug' => (string) $r['tenant_slug'],
                'nombre' => (string) $r['tenant_nombre'],
                'logo_url' => Media::url($r['tenant_logo']),
                'telefono' => $r['sucursal_telefono'] ?: $r['tenant_telefono'],
                'whatsapp' => $r['tenant_whatsapp'],
            ],
            'sucursal' => [
                'id' => (int) $r['sucursal_id'],
                'nombre' => (string) $r['sucursal_nombre'],
                'direccion' => (string) $r['sucursal_direccion'],
                'distrito' => (string) $r['sucursal_distrito'],
                'referencia' => $r['sucursal_referencia'],
                'coordenadas' => $r['latitud'] !== null ? ['lat' => (float) $r['latitud'], 'lng' => (float) $r['longitud']] : null,
            ],
            'barbero' => ['id' => (int) $r['barbero_id'], 'nombre' => (string) $r['barbero_nombre'], 'foto_url' => Media::url($r['barbero_foto'])],
            'barbero_asignado_auto' => (bool) $r['barbero_asignado_auto'],
            'servicios' => array_map(static fn (array $s): array => [
                'id' => (int) $s['servicio_id'],
                'nombre' => (string) $s['nombre_servicio'],
                'duracion_minutos' => (int) $s['duracion_minutos'],
                'precio' => (string) $s['precio'],
                'hora_inicio' => Clock::utcToLocal((string) $s['inicio_utc'], $tz)->format('H:i'),
                'hora_fin' => Clock::utcToLocal((string) $s['fin_utc'], $tz)->format('H:i'),
            ], $services),
            'fecha' => $ini->format('Y-m-d'),
            'hora_inicio' => $ini->format('H:i'),
            'hora_fin' => $fin->format('H:i'),
            'cuando' => self::whenText($r),
            'zona_horaria' => $tz,
            'inicio_utc' => self::iso((string) $r['inicio_utc']),
            'fin_utc' => self::iso((string) $r['fin_utc']),
            'duracion_total_minutos' => (int) $r['duracion_total_minutos'],
            'precio_total' => (string) $r['precio_total'],
            'moneda' => (string) $r['moneda'],
            'canal' => (string) $r['canal'],
            'notas_cliente' => $r['notas_cliente'],
            'asistencia_confirmada' => $r['asistencia_confirmada_en'] !== null,
            'reprogramaciones' => (int) $r['reprogramaciones'],
            'cancelacion' => $r['estado'] === 'cancelada' ? [
                'fecha' => self::iso((string) $r['cancelada_en']),
                'por' => $r['cancelada_por_tipo'],
                'motivo' => $r['motivo_cancelacion'],
            ] : null,
            'acciones' => self::actions($r, $policy),
            'politica' => [
                'cancelacion_limite_minutos' => $policy->cancelacionLimite,
                'reprogramacion_limite_minutos' => $policy->reprogramacionLimite,
                'max_reprogramaciones' => $policy->maxReprogramaciones,
                'texto' => $policy->texto,
            ],
            'creada_en' => self::iso((string) $r['created_at']),
        ];
    }

    /**
     * Qué puede hacer el cliente ahora mismo (la API vuelve a validarlo al ejecutar).
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public static function actions(array $r, BookingPolicy $policy): array
    {
        $now = Clock::nowUtc()->getTimestamp();
        $ini = Availability::ts((string) $r['inicio_utc']);
        $activa = in_array($r['estado'], ['pendiente', 'confirmada'], true) && $ini > $now;
        $cancelHasta = $ini - $policy->cancelacionLimite * 60;
        $reprogHasta = $ini - $policy->reprogramacionLimite * 60;
        $quedan = max(0, $policy->maxReprogramaciones - (int) $r['reprogramaciones']);
        return [
            'activa' => $activa,
            'puede_cancelar' => $activa && $now <= $cancelHasta,
            'cancelar_hasta' => gmdate('Y-m-d\TH:i:s\Z', $cancelHasta),
            'puede_reprogramar' => $activa && $now <= $reprogHasta && $quedan > 0,
            'reprogramar_hasta' => gmdate('Y-m-d\TH:i:s\Z', $reprogHasta),
            'reprogramaciones_restantes' => $quedan,
            'puede_confirmar_asistencia' => $activa && $r['asistencia_confirmada_en'] === null,
        ];
    }

    /**
     * Historial visible para el cliente (sin notas internas).
     *
     * @param list<array<string, mixed>> $events
     * @param array<int, string>         $barberNames
     * @return list<array<string, mixed>>
     */
    public static function history(array $events, string $tz, array $barberNames): array
    {
        $out = [];
        foreach ($events as $e) {
            $tipo = (string) $e['tipo'];
            $texto = match ($tipo) {
                'creada' => 'Reserva registrada',
                'confirmada' => $e['actor_tipo'] === 'sistema' ? 'Confirmada automáticamente' : 'Confirmada por la barbería',
                'reprogramada' => 'Reprogramada: ' . self::local($e['inicio_anterior_utc'], $tz) . ' → ' . self::local($e['inicio_nuevo_utc'], $tz)
                    . ($e['barbero_anterior_id'] !== null && $e['barbero_anterior_id'] !== $e['barbero_nuevo_id']
                        ? ' (con ' . ($barberNames[(int) $e['barbero_nuevo_id']] ?? 'otro barbero') . ')' : ''),
                'cancelada' => match ($e['actor_tipo']) {
                    'cliente' => 'Cancelada por ti',
                    'personal' => 'Cancelada por la barbería',
                    default => 'Cancelada por el sistema',
                },
                'asistencia_confirmada' => 'Confirmaste tu asistencia',
                'atencion_iniciada' => 'Atención iniciada',
                'completada' => 'Atendida',
                'no_asistio' => 'Inasistencia registrada',
                default => null,
            };
            if ($texto !== null) {
                $out[] = ['tipo' => $tipo, 'texto' => $texto, 'fecha' => self::iso((string) $e['created_at'])];
            }
        }
        return $out;
    }

    /** "jueves 22 de octubre a las 10:00" en la hora de la sucursal. @param array<string, mixed> $r */
    public static function whenText(array $r): string
    {
        $d = Clock::utcToLocal((string) $r['inicio_utc'], (string) $r['zona_horaria']);
        return self::DIAS[(int) $d->format('N')] . ' ' . (int) $d->format('j') . ' de ' . self::MESES[(int) $d->format('n')] . ' a las ' . $d->format('H:i');
    }

    public static function iso(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    /** @param mixed $utc */
    private static function local($utc, string $tz): string
    {
        if ($utc === null) {
            return '—';
        }
        $d = Clock::utcToLocal((string) $utc, $tz);
        return (int) $d->format('j') . ' ' . substr(self::MESES[(int) $d->format('n')], 0, 3) . ' ' . $d->format('H:i');
    }
}
