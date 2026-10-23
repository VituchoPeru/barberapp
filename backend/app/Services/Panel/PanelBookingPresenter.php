<?php

declare(strict_types=1);

namespace App\Services\Panel;

use App\Helpers\Clock;
use App\Helpers\Media;
use App\Services\Booking\Availability;
use App\Services\Booking\BookingPresenter;

/**
 * Cita vista por el personal de la barbería (agenda, detalle, ficha del cliente).
 * Incluye contacto del cliente, notas internas, atención, cobros y qué acciones están
 * disponibles para quien consulta (la API las vuelve a validar al ejecutarlas).
 */
final class PanelBookingPresenter
{
    /** Minutos antes del inicio desde los que se puede registrar inicio o fin de la atención. */
    public const ATENCION_ANTICIPADA_MIN = \App\Services\Booking\BookingRules::ATENCION_ANTICIPADA_MIN;

    /** Días durante los que se puede corregir "atendida" ↔ "no asistió". */
    public const DIAS_CORRECCION = \App\Services\Booking\BookingRules::DIAS_CORRECCION;

    /**
     * @param array<string, mixed>       $r        fila de AgendaRepository
     * @param list<array<string, mixed>> $services filas de reserva_servicios
     * @param list<string>               $perms    permisos de quien consulta
     * @return array<string, mixed>
     */
    public static function present(array $r, array $services, array $perms, bool $verContacto = true): array
    {
        $tz = (string) $r['zona_horaria'];
        $ini = Clock::utcToLocal((string) $r['inicio_utc'], $tz);
        $fin = Clock::utcToLocal((string) $r['fin_utc'], $tz);
        $pagado = number_format((float) $r['pagado'], 2, '.', '');
        $saldo = max(0, round((float) $r['precio_total'] - (float) $r['pagado'], 2));
        return [
            'id' => (int) $r['id'],
            'codigo' => (string) $r['codigo'],
            'estado' => (string) $r['estado'],
            'en_atencion' => $r['atencion_iniciada_en'] !== null && in_array($r['estado'], ['pendiente', 'confirmada'], true),
            'fecha' => $ini->format('Y-m-d'),
            'hora_inicio' => $ini->format('H:i'),
            'hora_fin' => $fin->format('Y-m-d') === $ini->format('Y-m-d') ? $fin->format('H:i') : '24:00',
            'inicio_utc' => BookingPresenter::iso((string) $r['inicio_utc']),
            'fin_utc' => BookingPresenter::iso((string) $r['fin_utc']),
            'zona_horaria' => $tz,
            'duracion_total_minutos' => (int) $r['duracion_total_minutos'],
            'sucursal' => ['id' => (int) $r['sucursal_id'], 'nombre' => (string) $r['sucursal_nombre']],
            'barbero' => ['id' => (int) $r['barbero_id'], 'nombre' => (string) $r['barbero_nombre'], 'foto_url' => Media::url($r['barbero_foto'])],
            'barbero_asignado_auto' => (bool) $r['barbero_asignado_auto'],
            'cliente' => [
                'id' => (int) $r['cliente_id'],
                'nombre' => trim($r['cliente_nombres'] . ' ' . ($r['cliente_apellidos'] ?? '')),
                'telefono' => $verContacto ? $r['cliente_telefono'] : null,
                'email' => $verContacto ? $r['cliente_email'] : null,
                'tiene_cuenta' => $r['cliente_usuario_id'] !== null,
            ],
            'servicios' => array_map(static fn (array $s): array => [
                'id' => (int) $s['servicio_id'],
                'nombre' => (string) $s['nombre_servicio'],
                'duracion_minutos' => (int) $s['duracion_minutos'],
                'precio' => (string) $s['precio'],
                'hora_inicio' => Clock::utcToLocal((string) $s['inicio_utc'], $tz)->format('H:i'),
            ], $services),
            'precio_total' => (string) $r['precio_total'],
            'pagado' => $pagado,
            'saldo' => number_format($saldo, 2, '.', ''),
            'moneda' => (string) $r['moneda'],
            'canal' => (string) $r['canal'],
            'notas_cliente' => $r['notas_cliente'],
            'notas_internas' => $r['notas_internas'],
            'asistencia_confirmada' => $r['asistencia_confirmada_en'] !== null,
            'atencion_iniciada_en' => $r['atencion_iniciada_en'] !== null ? BookingPresenter::iso((string) $r['atencion_iniciada_en']) : null,
            'atencion_finalizada_en' => $r['atencion_finalizada_en'] !== null ? BookingPresenter::iso((string) $r['atencion_finalizada_en']) : null,
            'reprogramaciones' => (int) $r['reprogramaciones'],
            'cancelacion' => $r['estado'] === 'cancelada' ? [
                'fecha' => $r['cancelada_en'] !== null ? BookingPresenter::iso((string) $r['cancelada_en']) : null,
                'por' => $r['cancelada_por_tipo'],
                'motivo' => $r['motivo_cancelacion'],
            ] : null,
            'acciones' => self::actions($r, $perms),
            'creada_en' => BookingPresenter::iso((string) $r['created_at']),
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @param list<string>         $perms
     * @return array<string, bool>
     */
    public static function actions(array $r, array $perms): array
    {
        $now = Clock::nowUtc()->getTimestamp();
        $ini = Availability::ts((string) $r['inicio_utc']);
        $fin = Availability::ts((string) $r['fin_utc']);
        $activa = in_array($r['estado'], ['pendiente', 'confirmada'], true);
        $asistencia = in_array('reservas.asistencia', $perms, true);
        $reciente = $ini >= $now - self::DIAS_CORRECCION * 86400;
        $yaPuedeAtender = $now >= $ini - self::ATENCION_ANTICIPADA_MIN * 60;
        return [
            'confirmar' => $activa && $r['estado'] === 'pendiente' && $fin > $now
                && (in_array('reservas.crear', $perms, true) || $asistencia),
            'iniciar' => $activa && $asistencia && $r['atencion_iniciada_en'] === null && $yaPuedeAtender,
            'completar' => $asistencia && (($activa && $yaPuedeAtender) || ($r['estado'] === 'no_asistio' && $reciente)),
            'no_asistio' => $asistencia && (($activa && $now >= $ini && $r['atencion_iniciada_en'] === null)
                || ($r['estado'] === 'completada' && $reciente && (float) $r['pagado'] <= 0)),
            'cancelar' => $activa && in_array('reservas.cancelar', $perms, true),
            'reprogramar' => $activa && $r['atencion_iniciada_en'] === null && in_array('reservas.reprogramar', $perms, true),
            'cobrar' => in_array($r['estado'], ['pendiente', 'confirmada', 'completada'], true) && in_array('reservas.cobrar', $perms, true),
            'notas' => in_array('reservas.crear', $perms, true) || $asistencia,
        ];
    }

    /**
     * Historial interno (incluye quién actuó y cobros).
     *
     * @param list<array<string, mixed>> $events
     * @param array<int, string>         $barberNames
     * @return list<array<string, mixed>>
     */
    public static function history(array $events, string $tz, array $barberNames): array
    {
        $out = [];
        foreach ($events as $e) {
            $datos = is_string($e['datos'] ?? null) ? (array) json_decode((string) $e['datos'], true) : [];
            $local = static fn ($utc): string => $utc === null ? '—' : Clock::utcToLocal((string) $utc, $tz)->format('d/m H:i');
            $texto = match ((string) $e['tipo']) {
                'creada' => 'Reserva registrada' . (($datos['canal'] ?? '') === 'panel' ? ' en el panel' : ' en línea'),
                'confirmada' => $e['actor_tipo'] === 'sistema' ? 'Confirmada automáticamente' : 'Confirmada',
                'reprogramada' => 'Reprogramada: ' . $local($e['inicio_anterior_utc']) . ' → ' . $local($e['inicio_nuevo_utc'])
                    . ($e['barbero_anterior_id'] !== null && $e['barbero_anterior_id'] !== $e['barbero_nuevo_id']
                        ? ' (' . ($barberNames[(int) $e['barbero_anterior_id']] ?? '—') . ' → ' . ($barberNames[(int) $e['barbero_nuevo_id']] ?? '—') . ')' : ''),
                'cancelada' => 'Cancelada por ' . match ($e['actor_tipo']) { 'cliente' => 'el cliente', 'personal' => 'la barbería', default => 'el sistema' }
                    . (!empty($datos['motivo']) ? ': ' . $datos['motivo'] : ''),
                'asistencia_confirmada' => 'El cliente confirmó su asistencia',
                'atencion_iniciada' => 'Atención iniciada',
                'completada' => !empty($datos['correccion']) ? 'Corregida: el cliente sí fue atendido' : 'Atendida',
                'no_asistio' => !empty($datos['correccion']) ? 'Corregida: el cliente no asistió' : 'Inasistencia registrada',
                'nota' => 'Notas internas actualizadas',
                'pago_registrado' => 'Cobro registrado: S/ ' . ($datos['monto'] ?? '') . ' (' . ($datos['metodo'] ?? '') . ')',
                'pago_anulado' => 'Cobro anulado: S/ ' . ($datos['monto'] ?? '') . (!empty($datos['motivo']) ? ' — ' . $datos['motivo'] : ''),
                default => 'Cita modificada',
            };
            $out[] = [
                'tipo' => (string) $e['tipo'],
                'texto' => $texto,
                'actor' => $e['actor_tipo'] === 'personal' ? ($e['actor_nombre'] ?: 'Personal') : ($e['actor_tipo'] === 'cliente' ? 'Cliente' : 'Sistema'),
                'fecha' => BookingPresenter::iso((string) $e['created_at']),
            ];
        }
        return $out;
    }
}
