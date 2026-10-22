<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Core\Exceptions\ValidationException;
use App\Helpers\Clock;
use App\Helpers\Validator;
use App\Repositories\TenantScopedRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Métricas de avisos de una barbería (Fase 8), todas desde datos reales:
 *  - recordatorios y avisos programados, enviados, pendientes, fallidos, omitidos y anulados
 *    (por fecha programada y por canal);
 *  - confirmaciones de asistencia de las citas del período;
 *  - tasa de inasistencia (no asistió ÷ (atendidas + no asistió)), también separada por
 *    "confirmó / no confirmó" y "recibió recordatorio / no recibió";
 *  - últimos errores de envío del registro de intentos.
 */
final class NotificationMetrics extends TenantScopedRepository
{
    public const MAX_DIAS = 366;
    private const ESTADOS = ['programada', 'procesando', 'enviada', 'fallida', 'cancelada', 'omitida'];

    /**
     * @param array<string, mixed> $query desde, hasta (AAAA-MM-DD, zona de la barbería)
     * @return array<string, mixed>
     */
    public function report(array $query, string $timezone): array
    {
        $q = Validator::validate($query, ['desde' => 'nullable|date', 'hasta' => 'nullable|date']);
        $tz = new DateTimeZone($timezone);
        $hoy = Clock::nowUtc()->setTimezone($tz);
        $hasta = (string) ($q['hasta'] ?? $hoy->format('Y-m-d'));
        $desde = (string) ($q['desde'] ?? (new DateTimeImmutable($hasta))->modify('-29 day')->format('Y-m-d'));
        if ($desde > $hasta) {
            throw new ValidationException(['desde' => ['La fecha inicial debe ser anterior o igual a la final.']]);
        }
        if ((new DateTimeImmutable($desde))->diff(new DateTimeImmutable($hasta))->days >= self::MAX_DIAS) {
            throw new ValidationException(['hasta' => ['El período no puede superar ' . self::MAX_DIAS . ' días.']]);
        }
        $d = Clock::localToUtc($desde . ' 00:00:00', $timezone)->format('Y-m-d H:i:s');
        $h = Clock::localToUtc((new DateTimeImmutable($hasta))->modify('+1 day')->format('Y-m-d') . ' 00:00:00', $timezone)->format('Y-m-d H:i:s');
        $p = ['t' => $this->tenantId, 'd' => $d, 'h' => $h];

        $empty = static fn (): array => array_fill_keys(['programados', 'enviados', 'pendientes', 'fallidos', 'omitidos', 'cancelados'], 0);
        $out = ['recordatorios' => $empty() + ['por_canal' => []], 'avisos' => $empty() + ['por_canal' => []]];
        $rows = $this->fetchAll(
            "SELECT canal, tipo = 'recordatorio' AS rec, estado, COUNT(*) AS n
               FROM notificaciones
              WHERE tenant_id = :t AND programada_para_utc >= :d AND programada_para_utc < :h
                AND (tipo = 'recordatorio' OR canal IN ('push', 'email'))
              GROUP BY canal, rec, estado",
            $p
        );
        $map = ['enviada' => 'enviados', 'programada' => 'pendientes', 'procesando' => 'pendientes', 'fallida' => 'fallidos', 'omitida' => 'omitidos', 'cancelada' => 'cancelados'];
        foreach ($rows as $r) {
            $grupo = (int) $r['rec'] === 1 ? 'recordatorios' : 'avisos';
            $canal = (string) $r['canal'];
            $n = (int) $r['n'];
            $out[$grupo]['por_canal'][$canal] ??= $empty();
            foreach ([&$out[$grupo], &$out[$grupo]['por_canal'][$canal]] as &$bucket) {
                $bucket['programados'] += $n;
                $bucket[$map[$r['estado']] ?? 'pendientes'] += $n;
            }
            unset($bucket);
        }
        foreach (['recordatorios', 'avisos'] as $g) {
            $real = $out[$g]['programados'] - $out[$g]['cancelados'] - $out[$g]['omitidos'] - $out[$g]['pendientes'];
            $out[$g]['tasa_entrega'] = $real > 0 ? round($out[$g]['enviados'] * 100 / $real, 1) : null;
        }

        // Citas del período (por hora de inicio): confirmaciones e inasistencias.
        $citas = $this->fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(x.recordada) AS recordadas,
                    SUM(x.asistencia_confirmada_en IS NOT NULL) AS confirmadas,
                    SUM(x.asistencia_confirmada_en IS NOT NULL AND x.recordada = 1 AND x.asistencia_confirmada_en >= x.primer_recordatorio) AS confirmadas_tras_recordatorio,
                    SUM(x.estado = 'completada') AS atendidas,
                    SUM(x.estado = 'no_asistio') AS no_asistio,
                    SUM(x.estado = 'completada' AND x.asistencia_confirmada_en IS NOT NULL) AS atendidas_conf,
                    SUM(x.estado = 'no_asistio' AND x.asistencia_confirmada_en IS NOT NULL) AS no_asistio_conf,
                    SUM(x.estado = 'completada' AND x.recordada = 1) AS atendidas_rec,
                    SUM(x.estado = 'no_asistio' AND x.recordada = 1) AS no_asistio_rec
               FROM (SELECT r.estado, r.asistencia_confirmada_en,
                            (SELECT MIN(n.enviada_en) FROM notificaciones n
                              WHERE n.tenant_id = r.tenant_id AND n.reserva_id = r.id AND n.tipo = 'recordatorio' AND n.estado = 'enviada') AS primer_recordatorio,
                            EXISTS (SELECT 1 FROM notificaciones n
                                     WHERE n.tenant_id = r.tenant_id AND n.reserva_id = r.id AND n.tipo = 'recordatorio' AND n.estado = 'enviada') AS recordada
                       FROM reservas r
                      WHERE r.tenant_id = :t AND r.inicio_utc >= :d AND r.inicio_utc < :h AND r.estado <> 'cancelada') x",
            $p
        ) ?? [];
        $i = static fn (string $k): int => (int) ($citas[$k] ?? 0);
        $rate = static fn (int $no, int $si): ?float => ($no + $si) > 0 ? round($no * 100 / ($no + $si), 1) : null;

        $fallos = array_map(static fn (array $e): array => [
            'fecha' => str_replace(' ', 'T', (string) $e['created_at']) . 'Z',
            'canal' => $e['canal'],
            'tipo' => $e['tipo'],
            'proveedor' => $e['proveedor'],
            'resultado' => $e['resultado'],
            'codigo' => $e['codigo'],
            'detalle' => $e['detalle'],
            'intento' => (int) $e['intento'],
            'reserva_codigo' => $e['reserva_codigo'],
        ], $this->fetchAll(
            "SELECT e.created_at, e.canal, e.proveedor, e.resultado, e.codigo, e.detalle, e.intento, n.tipo, r.codigo AS reserva_codigo
               FROM notificacion_envios e
               JOIN notificaciones n ON n.id = e.notificacion_id
               LEFT JOIN reservas r ON r.tenant_id = n.tenant_id AND r.id = n.reserva_id
              WHERE e.tenant_id = :t AND e.resultado IN ('error_temporal', 'error_permanente') AND e.created_at >= :d AND e.created_at < :h
              ORDER BY e.created_at DESC, e.id DESC LIMIT 20",
            $p
        ));

        return [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta, 'zona_horaria' => $timezone],
            'recordatorios' => $out['recordatorios'],
            'avisos' => $out['avisos'],
            'citas' => [
                'total' => $i('total'),
                'con_recordatorio' => $i('recordadas'),
                'confirmadas' => $i('confirmadas'),
                'confirmadas_tras_recordatorio' => $i('confirmadas_tras_recordatorio'),
                'tasa_confirmacion' => $i('recordadas') > 0 ? round($i('confirmadas_tras_recordatorio') * 100 / $i('recordadas'), 1) : null,
            ],
            'inasistencia' => [
                'atendidas' => $i('atendidas'),
                'no_asistio' => $i('no_asistio'),
                'tasa' => $rate($i('no_asistio'), $i('atendidas')),
                'tasa_confirmaron' => $rate($i('no_asistio_conf'), $i('atendidas_conf')),
                'tasa_no_confirmaron' => $rate($i('no_asistio') - $i('no_asistio_conf'), $i('atendidas') - $i('atendidas_conf')),
                'tasa_con_recordatorio' => $rate($i('no_asistio_rec'), $i('atendidas_rec')),
                'tasa_sin_recordatorio' => $rate($i('no_asistio') - $i('no_asistio_rec'), $i('atendidas') - $i('atendidas_rec')),
            ],
            'errores_recientes' => $fallos,
        ];
    }
}
