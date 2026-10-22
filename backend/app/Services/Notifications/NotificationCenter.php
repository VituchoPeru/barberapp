<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Core\Database;
use App\Helpers\Clock;
use App\Repositories\BookingRepository;

/**
 * Programa avisos en la cola persistente (tabla notificaciones) para que el worker los envíe.
 *
 * Se llama dentro de la transacción de la operación que origina el aviso: si la reserva o el
 * cambio fallan, no queda aviso; si se confirman, el aviso existe aunque la app esté cerrada.
 * Cada fila tiene dedupe_key única (reintentos de red o del planificador no duplican avisos).
 *
 * Canales: interna (bandeja de la app, siempre), push (si la persona tiene dispositivos activos)
 * y email (solo clientes con correo verificado). La barbería decide qué avisos y canales usa
 * (NotificationSettings) y cada persona qué recibe (Preferences); ambas cosas se vuelven a
 * comprobar al enviar, por si cambiaron mientras el aviso esperaba en la cola.
 */
final class NotificationCenter
{
    /** Aviso al cliente → regla de la barbería que lo habilita. */
    public const EVENT_SETTING = [
        'reserva_confirmada' => 'aviso_confirmacion',
        'reserva_recibida' => 'aviso_confirmacion',
        'reserva_cancelada' => 'aviso_cancelacion',
        'reserva_modificada' => 'aviso_modificacion',
    ];

    private NotificationSettings $settings;

    public function __construct(private int $tenantId)
    {
        $this->settings = new NotificationSettings($tenantId);
    }

    public function settings(): NotificationSettings
    {
        return $this->settings;
    }

    /**
     * Push y correo al cliente por un cambio en su cita (la bandeja la llena BookingNotifier).
     *
     * @param array<string, mixed>  $r     fila de BookingRepository::SELECT_DETAIL
     * @param array<string, string> $extra motivo
     */
    public function clientEvent(array $r, string $codigo, string $dedupe, array $extra = []): int
    {
        if ($r['cliente_usuario_id'] === null || !isset(self::EVENT_SETTING[$codigo]) || !$this->settings->all()[self::EVENT_SETTING[$codigo]]) {
            return 0;
        }
        $n = 0;
        foreach (['push', 'email'] as $canal) {
            $n += $this->enqueueBooking($r, $canal, $codigo, $codigo, 'cambios', "{$dedupe}:{$canal}", Clock::nowSql(), $extra);
        }
        return $n;
    }

    /**
     * Recordatorios de una cita activa según las horas configuradas por la barbería.
     * Un recordatorio cuya hora de envío ya pasó (más de $graceSeconds) no se programa: si se
     * reserva con 3 h de anticipación no llega un "tu cita es mañana".
     *
     * @param array<string, mixed> $r fila de BookingRepository::SELECT_DETAIL
     */
    public function scheduleReminders(array $r, int $graceSeconds = 0): int
    {
        if ($r['cliente_usuario_id'] === null || !in_array($r['estado'], ['pendiente', 'confirmada'], true)) {
            return 0;
        }
        $inicio = strtotime($r['inicio_utc'] . ' UTC');
        $now = Clock::nowUtc()->getTimestamp();
        $n = 0;
        foreach ($this->settings->hours() as $h) {
            $sendAt = $inicio - $h * 3600;
            if ($sendAt < $now - $graceSeconds || $inicio <= $now) {
                continue;
            }
            $when = gmdate('Y-m-d H:i:s', max($sendAt, $now));
            $base = "rec:{$r['id']}:{$r['reprogramaciones']}:{$h}h";
            foreach (['interna', 'push', 'email'] as $canal) {
                $n += $this->enqueueBooking($r, $canal, 'recordatorio', 'recordatorio', 'recordatorios', "{$base}:{$canal}", $when, [], ['horas' => $h]);
            }
        }
        return $n;
    }

    /**
     * Push al personal (barbero, encargados) con el mismo texto del aviso de su bandeja.
     *
     * @param array<string, mixed> $datos
     */
    public function staffPush(int $usuarioId, string $tipo, string $titulo, string $cuerpo, array $datos, string $dedupe): int
    {
        if (!$this->settings->channelEnabled('push') || !Preferences::allowed($usuarioId, $this->tenantId, 'push', 'agenda') || !self::hasDevice($usuarioId)) {
            return 0;
        }
        return $this->insert([
            'usuario_id' => $usuarioId, 'reserva_id' => $datos['reserva_id'] ?? null, 'canal' => 'push', 'tipo' => $tipo,
            'destino' => null, 'titulo' => $titulo, 'cuerpo' => $cuerpo,
            'datos' => $datos + ['categoria' => 'agenda'], 'programada_para_utc' => Clock::nowSql(), 'dedupe_key' => "{$dedupe}:push",
        ]);
    }

    public static function hasDevice(int $usuarioId): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM dispositivos_push WHERE usuario_id = ? AND activo = 1 LIMIT 1');
        $stmt->execute([$usuarioId]);
        return $stmt->fetchColumn() !== false;
    }

    /** Correo verificado del usuario (solo a correos verificados se envían avisos). */
    public static function verifiedEmail(int $usuarioId): ?string
    {
        $stmt = Database::connection()->prepare("SELECT email FROM usuarios WHERE id = ? AND estado = 'activo' AND email_verificado_en IS NOT NULL");
        $stmt->execute([$usuarioId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    /**
     * @param array<string, mixed>  $r
     * @param array<string, string> $extra
     * @param array<string, mixed>  $datos
     */
    private function enqueueBooking(array $r, string $canal, string $tipo, string $codigo, string $categoria, string $dedupe, string $when, array $extra, array $datos = []): int
    {
        $uid = (int) $r['cliente_usuario_id'];
        $destino = null;
        if ($canal !== 'interna') {
            if (!$this->settings->channelEnabled($canal) || !Preferences::allowed($uid, $this->tenantId, $canal, $categoria)) {
                return 0;
            }
            if ($canal === 'push' && !self::hasDevice($uid)) {
                return 0;
            }
            if ($canal === 'email' && ($destino = self::verifiedEmail($uid)) === null) {
                return 0;
            }
        }
        $services = (new BookingRepository($this->tenantId))->services((int) $r['id']);
        if ($tipo === 'recordatorio') {
            $extra['accion'] = BookingMessages::reminderAction($r, (bool) $this->settings->all()['pedir_confirmacion']);
        }
        [$titulo, $cuerpo] = Templates::render($codigo, $canal === 'interna' ? 'push' : $canal, $this->tenantId, BookingMessages::vars($r, $services, $extra));
        return $this->insert([
            'usuario_id' => $uid, 'reserva_id' => (int) $r['id'], 'canal' => $canal, 'tipo' => $tipo, 'destino' => $destino,
            'titulo' => $titulo, 'cuerpo' => $cuerpo,
            'datos' => $datos + array_filter([
                'reserva_id' => (int) $r['id'], 'plantilla' => $codigo, 'categoria' => $categoria, 'inicio_utc' => (string) $r['inicio_utc'],
                'motivo' => $extra['motivo'] ?? null,
            ], static fn ($v) => $v !== null && $v !== ''),
            'programada_para_utc' => $when, 'dedupe_key' => $dedupe,
        ]);
    }

    /** @param array<string, mixed> $f */
    private function insert(array $f): int
    {
        $stmt = Database::connection()->prepare(
            "INSERT INTO notificaciones (tenant_id, usuario_id, reserva_id, canal, tipo, destino, titulo, cuerpo, datos, estado, programada_para_utc, max_intentos, dedupe_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'programada', ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id"
        );
        $stmt->execute([
            $this->tenantId, $f['usuario_id'], $f['reserva_id'], $f['canal'], $f['tipo'], $f['destino'],
            mb_substr((string) $f['titulo'], 0, 160), (string) $f['cuerpo'], json_encode($f['datos'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $f['programada_para_utc'], QueueWorker::maxAttempts(), substr((string) $f['dedupe_key'], 0, 191),
        ]);
        return $stmt->rowCount() === 1 ? 1 : 0;
    }
}
