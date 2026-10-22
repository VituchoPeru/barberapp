<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Helpers\Clock;
use App\Repositories\BookingRepository;
use App\Services\Mail\Mailer;
use App\Services\Mail\MailMessage;
use App\Services\Notifications\Push\Push;
use App\Services\Notifications\Push\PushResult;
use App\Services\Notifications\Push\PushTransport;
use Throwable;

/**
 * Worker de la cola de notificaciones. Lo ejecuta cron cada minuto:
 *   php bin/console notificaciones:procesar
 *
 * En cada ejecución:
 *   1. Devuelve a la cola los avisos "procesando" de un worker que se cayó (más de 10 min).
 *   2. Planificador: crea los recordatorios que falten para las citas próximas (ReminderPlanner).
 *   3. Toma lotes de avisos vencidos con SELECT … FOR UPDATE SKIP LOCKED y los marca
 *      "procesando" con su identificador: varios workers en paralelo nunca toman el mismo aviso.
 *   4. Envía cada uno y registra el intento en notificacion_envios:
 *        enviada  → listo;  omitida → no correspondía (preferencia, canal apagado, sin dispositivos);
 *        cancelada → la cita cambió o se canceló; error temporal → reintento con backoff
 *        (1 min, 5 min, 15 min, 1 h, 3 h) hasta max_intentos y luego "fallida".
 * Garantía: al menos una vez. Si el proceso muere justo después de que FCM aceptó un push y
 * antes de marcarlo, ese push se repetirá al recuperar el aviso (caso raro y acotado).
 */
final class QueueWorker
{
    private string $workerId;
    private ?PushTransport $push = null;
    private bool $pushResolved = false;
    /** @var array<string, int> */
    private array $stats = ['planificados' => 0, 'recuperados' => 0, 'procesados' => 0, 'enviada' => 0, 'omitida' => 0, 'cancelada' => 0, 'reintento' => 0, 'fallida' => 0];

    public function __construct(?string $workerId = null)
    {
        $this->workerId = $workerId ?? substr(gethostname() ?: 'worker', 0, 40) . ':' . getmypid() . ':' . bin2hex(random_bytes(3));
    }

    public static function maxAttempts(): int
    {
        return max(1, min(10, (int) Config::get('notifications.worker.max_attempts', 5)));
    }

    /** @return array<string, int> */
    public function run(?int $limit = null, ?int $maxSeconds = null): array
    {
        $limit ??= (int) Config::get('notifications.worker.batch', 200);
        $maxSeconds ??= (int) Config::get('notifications.worker.max_seconds', 50);
        $deadline = microtime(true) + max(1, $maxSeconds);
        NotificationSettings::flush();
        Templates::flush();

        $this->stats['recuperados'] = $this->recoverStale();
        $this->stats['planificados'] = (new ReminderPlanner())->plan();

        while ($this->stats['procesados'] < $limit && microtime(true) < $deadline) {
            $ids = $this->claim(min(50, $limit - $this->stats['procesados']));
            if ($ids === []) {
                break;
            }
            foreach ($ids as $id) {
                $this->stats['procesados']++;
                try {
                    $this->process($id);
                } catch (Throwable $e) {
                    // Un aviso con datos inesperados no detiene la cola: se reintenta y queda registrado.
                    Logger::error('Error procesando notificación', ['notificacion_id' => $id, 'exception' => $e]);
                    $row = $this->row($id);
                    if ($row !== null && $row['estado'] === 'procesando') {
                        $this->retryOrFail($row, 'worker', 'exception', mb_substr($e->getMessage(), 0, 500), null, null);
                    }
                }
            }
        }
        return $this->stats;
    }

    /** Avisos "procesando" abandonados por un worker caído vuelven a la cola. */
    private function recoverStale(): int
    {
        $limit = Clock::nowUtc()->modify('-' . (int) Config::get('notifications.worker.stale_minutes', 10) . ' minutes')->format('Y-m-d H:i:s');
        $stmt = Database::connection()->prepare(
            "UPDATE notificaciones SET estado = 'programada', bloqueada_por = NULL, bloqueada_en = NULL
              WHERE estado = 'procesando' AND bloqueada_en < ?"
        );
        $stmt->execute([$limit]);
        return $stmt->rowCount();
    }

    /** @return list<int> */
    private function claim(int $n): array
    {
        return Database::transaction(function () use ($n): array {
            $now = Clock::nowSql();
            $db = Database::connection();
            $stmt = $db->prepare(
                "SELECT id FROM notificaciones
                  WHERE estado = 'programada' AND programada_para_utc <= ? AND (proximo_intento_utc IS NULL OR proximo_intento_utc <= ?)
                  ORDER BY programada_para_utc, id
                  LIMIT " . max(1, $n) . ' FOR UPDATE SKIP LOCKED'
            );
            $stmt->execute([$now, $now]);
            $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
            if ($ids !== []) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $db->prepare("UPDATE notificaciones SET estado = 'procesando', bloqueada_por = ?, bloqueada_en = ? WHERE id IN ({$in})")
                    ->execute(array_merge([$this->workerId, $now], $ids));
            }
            return $ids;
        });
    }

    /** @return array<string, mixed>|null */
    private function row(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM notificaciones WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        return $r === false ? null : $r;
    }

    private function process(int $id): void
    {
        $n = $this->row($id);
        if ($n === null || $n['estado'] !== 'procesando' || $n['bloqueada_por'] !== $this->workerId) {
            return; // otro worker lo recuperó o la cita lo anuló mientras tanto
        }
        $datos = json_decode((string) ($n['datos'] ?? 'null'), true);
        $datos = is_array($datos) ? $datos : [];
        $tenantId = $n['tenant_id'] !== null ? (int) $n['tenant_id'] : null;
        $canal = (string) $n['canal'];
        $categoria = (string) ($datos['categoria'] ?? '');
        $settings = $tenantId !== null ? new NotificationSettings($tenantId) : null;
        $booking = null;

        // 1. ¿Sigue aplicando? (datos frescos de la cita y reglas vigentes)
        if ($tenantId !== null && $n['reserva_id'] !== null && isset($datos['plantilla'])) {
            $booking = (new BookingRepository($tenantId))->find((int) $n['reserva_id']);
            if ($booking === null) {
                $this->finish($n, 'cancelada', 'La cita ya no existe.');
                return;
            }
            if ($n['tipo'] === 'recordatorio') {
                $why = $this->reminderInvalid($booking, $datos, $settings);
                if ($why !== null) {
                    $this->finish($n, 'cancelada', $why);
                    return;
                }
            }
        }
        if ($canal !== 'interna') {
            if ($settings !== null && !$settings->channelEnabled($canal)) {
                $this->skip($n, 'canal_desactivado', 'La barbería desactivó los avisos por ' . ($canal === 'email' ? 'correo' : 'push') . '.');
                return;
            }
            if ($n['usuario_id'] !== null && $categoria !== '' && !Preferences::allowed((int) $n['usuario_id'], $tenantId, $canal, $categoria)) {
                $this->skip($n, 'preferencia', 'La persona desactivó este tipo de aviso.');
                return;
            }
        }

        // 2. Texto definitivo con datos frescos (fecha relativa "mañana", confirmación vigente…).
        $titulo = (string) $n['titulo'];
        $cuerpo = (string) $n['cuerpo'];
        $vars = null;
        $askConfirm = false;
        if ($booking !== null && $tenantId !== null) {
            $extra = ['motivo' => (string) ($datos['motivo'] ?? '')];
            if ($n['tipo'] === 'recordatorio') {
                $pedir = (bool) $settings->all()['pedir_confirmacion'];
                $extra['accion'] = BookingMessages::reminderAction($booking, $pedir);
                $askConfirm = BookingMessages::asksConfirmation($booking, $pedir);
            }
            $vars = BookingMessages::vars($booking, (new BookingRepository($tenantId))->services((int) $booking['id']), $extra);
            [$titulo, $cuerpo] = Templates::render((string) $datos['plantilla'], $canal === 'interna' ? 'push' : $canal, $tenantId, $vars);
            $datos['accion'] = $askConfirm ? 'confirmar_asistencia' : null;
            $datos = array_filter($datos, static fn ($v) => $v !== null);
        }

        // 3. Envío por canal.
        $t0 = microtime(true);
        switch ($canal) {
            case 'interna':
                $this->sent($n, 'interna', null, null, $titulo, $cuerpo, $datos, $t0);
                return;
            case 'push':
                $this->sendPush($n, $titulo, $cuerpo, $datos, $booking, $askConfirm, $t0);
                return;
            case 'email':
                $this->sendEmail($n, $titulo, $cuerpo, $datos, $booking, $askConfirm, $t0);
                return;
            default:
                $this->skip($n, 'canal_no_disponible', "El canal {$canal} todavía no está disponible.");
        }
    }

    /** @param array<string, mixed> $datos */
    private function reminderInvalid(array $b, array $datos, ?NotificationSettings $settings): ?string
    {
        if (!in_array($b['estado'], ['pendiente', 'confirmada'], true)) {
            return 'La cita ya no está activa.';
        }
        if (isset($datos['inicio_utc']) && (string) $datos['inicio_utc'] !== (string) $b['inicio_utc']) {
            return 'La cita cambió de horario: se programó un recordatorio nuevo.';
        }
        if (strtotime($b['inicio_utc'] . ' UTC') <= Clock::nowUtc()->getTimestamp()) {
            return 'La cita ya empezó: el recordatorio llegó tarde y no se envía.';
        }
        if ($b['tenant_estado'] !== 'activo') {
            return 'La barbería no está activa.';
        }
        if ($settings !== null && isset($datos['horas']) && !in_array((int) $datos['horas'], $settings->hours(), true)) {
            return 'La barbería quitó este recordatorio de sus reglas.';
        }
        return null;
    }

    /**
     * @param array<string, mixed>      $n
     * @param array<string, mixed>      $datos
     * @param array<string, mixed>|null $booking
     */
    private function sendPush(array $n, string $titulo, string $cuerpo, array $datos, ?array $booking, bool $askConfirm, float $t0): void
    {
        if (!$this->pushResolved) {
            $this->push = Push::transport();
            $this->pushResolved = true;
        }
        if ($this->push === null) {
            $this->skip($n, 'push_no_configurado', 'El envío push no está configurado en el servidor (PUSH_DRIVER=none).');
            return;
        }
        $stmt = Database::connection()->prepare('SELECT id, token, plataforma FROM dispositivos_push WHERE usuario_id = ? AND activo = 1 ORDER BY ultimo_uso_en DESC, id DESC LIMIT 10');
        $stmt->execute([(int) $n['usuario_id']]);
        $devices = $stmt->fetchAll();
        if ($devices === []) {
            $this->skip($n, 'sin_dispositivos', 'La persona no tiene dispositivos con avisos activados.');
            return;
        }
        $data = ['notificacion_id' => (string) $n['id'], 'tipo' => (string) $n['tipo'], 'tag' => 'n' . $n['id']];
        foreach (['reserva_id', 'bloqueo_id', 'url'] as $k) {
            if (isset($datos[$k])) {
                $data[$k] = (string) $datos[$k];
            }
        }
        if ($booking !== null) {
            $data['url'] ??= 'pages/cita.html?id=' . (int) $booking['id'];
            $data['tag'] = 'reserva-' . (int) $booking['id'];
            if ($askConfirm) {
                $data['accion'] = 'confirmar_asistencia';
                $data['token'] = AttendanceToken::issue((int) $booking['tenant_id'], (int) $booking['id'], (string) $booking['inicio_utc']);
            }
        }
        $message = ['title' => $titulo, 'body' => $cuerpo, 'data' => $data];

        $ok = 0;
        $invalid = 0;
        $retry = null;
        $failed = null;
        foreach ($devices as $d) {
            $res = $this->push->send((string) $d['token'], (string) $d['plataforma'], $message);
            if ($res->status === PushResult::OK) {
                $ok++;
                Database::connection()->prepare('UPDATE dispositivos_push SET ultimo_uso_en = ? WHERE id = ?')->execute([Clock::nowSql(), (int) $d['id']]);
            } elseif ($res->status === PushResult::INVALID_TOKEN) {
                $invalid++;
                Database::connection()->prepare('UPDATE dispositivos_push SET activo = 0, desactivado_en = ?, motivo_desactivado = ? WHERE id = ?')
                    ->execute([Clock::nowSql(), mb_substr('Token rechazado por el proveedor (' . ($res->code ?? 'invalid') . ')', 0, 120), (int) $d['id']]);
            } elseif ($res->status === PushResult::RETRY) {
                $retry = $res;
            } else {
                $failed = $res;
            }
        }
        $count = count($devices);
        if ($ok > 0) {
            $this->sent($n, $this->push->name(), null, $count, $titulo, $cuerpo, $datos, $t0);
        } elseif ($retry !== null) {
            $this->retryOrFail($n, $this->push->name(), $retry->code, $retry->detail, $count, self::ms($t0));
        } elseif ($failed !== null) {
            $this->fail($n, $this->push->name(), $failed->code, $failed->detail, $count, self::ms($t0));
        } else {
            $this->skip($n, 'tokens_invalidos', "El proveedor rechazó los {$invalid} dispositivo(s) de la persona; se desactivaron.", $this->push->name(), $count);
        }
    }

    /**
     * @param array<string, mixed>      $n
     * @param array<string, mixed>      $datos
     * @param array<string, mixed>|null $booking
     */
    private function sendEmail(array $n, string $asunto, string $cuerpo, array $datos, ?array $booking, bool $askConfirm, float $t0): void
    {
        $to = (string) ($n['destino'] ?? '');
        if ($to === '' && $n['usuario_id'] !== null) {
            $to = (string) NotificationCenter::verifiedEmail((int) $n['usuario_id']);
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->skip($n, 'sin_correo', 'No hay un correo verificado al que enviar.');
            return;
        }
        $boton = null;
        $url = null;
        if ($booking !== null) {
            if ($askConfirm) {
                $boton = 'Confirmar asistencia';
                $url = BookingMessages::confirmUrl(AttendanceToken::issue((int) $booking['tenant_id'], (int) $booking['id'], (string) $booking['inicio_utc']));
            } elseif ($booking['estado'] !== 'cancelada') {
                $boton = 'Ver mi cita';
                $url = BookingMessages::bookingUrl((int) $booking['id']);
            }
        }
        $prefs = BookingMessages::frontend('pages/auth/cuenta.html#avisos');
        $nota = 'Recibes este correo por tu cita en BARBERAPP. Puedes elegir qué avisos recibir en Mi cuenta → Avisos.';
        $text = $cuerpo . ($url !== null ? "\n\n{$boton}: {$url}" : '') . "\n\n{$nota}\n{$prefs}\n\nBARBERAPP";
        $html = EmailLayout::render($asunto, $cuerpo, $boton, $url, $nota);
        try {
            $msg = new MailMessage($to, str_replace(["\r", "\n"], ' ', $asunto), $text, $html);
        } catch (Throwable $e) {
            $this->fail($n, Mailer::driver(), 'invalid_message', $e->getMessage(), null, self::ms($t0));
            return;
        }
        if (Mailer::send($msg)) {
            $this->sent($n, Mailer::driver(), null, null, $asunto, $cuerpo, $datos, $t0);
        } else {
            $this->retryOrFail($n, Mailer::driver(), 'mail_error', Mailer::lastError() ?? 'No se pudo enviar el correo.', null, self::ms($t0));
        }
    }

    // ------------------------------------------------------------------ resultados

    /**
     * @param array<string, mixed> $n
     * @param array<string, mixed> $datos
     */
    private function sent(array $n, string $proveedor, ?string $codigo, ?int $dispositivos, string $titulo, string $cuerpo, array $datos, float $t0): void
    {
        $intento = (int) $n['intentos'] + 1;
        $now = Clock::nowSql();
        Database::connection()->prepare(
            "UPDATE notificaciones SET estado = 'enviada', enviada_en = ?, intentos = ?, titulo = ?, cuerpo = ?, datos = ?,
                    proximo_intento_utc = NULL, ultimo_error = NULL, bloqueada_por = NULL, bloqueada_en = NULL
              WHERE id = ?"
        )->execute([$now, min($intento, (int) $n['max_intentos']), mb_substr($titulo, 0, 160), $cuerpo,
            json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int) $n['id']]);
        $this->log($n, $intento, $proveedor, 'enviada', $codigo, null, $dispositivos, self::ms($t0));
        $this->stats['enviada']++;
    }

    /** @param array<string, mixed> $n */
    private function skip(array $n, string $codigo, string $detalle, string $proveedor = 'worker', ?int $dispositivos = null): void
    {
        Database::connection()->prepare(
            "UPDATE notificaciones SET estado = 'omitida', ultimo_error = ?, proximo_intento_utc = NULL, bloqueada_por = NULL, bloqueada_en = NULL WHERE id = ?"
        )->execute([$detalle, (int) $n['id']]);
        $this->log($n, (int) $n['intentos'] + 1, $proveedor, 'omitida', $codigo, $detalle, $dispositivos, null);
        $this->stats['omitida']++;
    }

    /** @param array<string, mixed> $n */
    private function finish(array $n, string $estado, string $motivo): void
    {
        Database::connection()->prepare(
            'UPDATE notificaciones SET estado = ?, ultimo_error = ?, proximo_intento_utc = NULL, bloqueada_por = NULL, bloqueada_en = NULL WHERE id = ?'
        )->execute([$estado, $motivo, (int) $n['id']]);
        $this->stats[$estado]++;
    }

    /** @param array<string, mixed> $n */
    private function retryOrFail(array $n, string $proveedor, ?string $codigo, ?string $detalle, ?int $dispositivos, ?int $ms): void
    {
        $intento = (int) $n['intentos'] + 1;
        $max = (int) $n['max_intentos'];
        $backoff = (array) Config::get('notifications.worker.backoff', [60, 300, 900, 3600, 10800]);
        $wait = (int) ($backoff[min($intento - 1, count($backoff) - 1)] ?? 3600);
        $wait += random_int(0, (int) ceil($wait * 0.1)); // jitter: los reintentos no se agolpan
        $next = Clock::nowUtc()->modify("+{$wait} seconds");
        // Un recordatorio que reintentaría después de la hora de la cita ya no sirve.
        $tooLate = $n['tipo'] === 'recordatorio' && $n['reserva_id'] !== null && $this->bookingStartsBefore($n, $next->getTimestamp());
        if ($intento >= $max || $tooLate) {
            $this->fail($n, $proveedor, $codigo, $detalle . ($tooLate && $intento < $max ? ' (sin más reintentos: la cita empieza antes del siguiente intento)' : ''), $dispositivos, $ms, $intento);
            return;
        }
        Database::connection()->prepare(
            "UPDATE notificaciones SET estado = 'programada', intentos = ?, proximo_intento_utc = ?, ultimo_error = ?, bloqueada_por = NULL, bloqueada_en = NULL WHERE id = ?"
        )->execute([$intento, $next->format('Y-m-d H:i:s'), mb_substr((string) $detalle, 0, 1000), (int) $n['id']]);
        $this->log($n, $intento, $proveedor, 'error_temporal', $codigo, $detalle, $dispositivos, $ms);
        $this->stats['reintento']++;
    }

    /** @param array<string, mixed> $n */
    private function fail(array $n, string $proveedor, ?string $codigo, ?string $detalle, ?int $dispositivos, ?int $ms, ?int $intento = null): void
    {
        $intento ??= (int) $n['intentos'] + 1;
        Database::connection()->prepare(
            "UPDATE notificaciones SET estado = 'fallida', intentos = ?, ultimo_error = ?, proximo_intento_utc = NULL, bloqueada_por = NULL, bloqueada_en = NULL WHERE id = ?"
        )->execute([min($intento, (int) $n['max_intentos']), mb_substr((string) $detalle, 0, 1000), (int) $n['id']]);
        $this->log($n, $intento, $proveedor, 'error_permanente', $codigo, $detalle, $dispositivos, $ms);
        $this->stats['fallida']++;
        Logger::log('warning', 'Notificación fallida', ['notificacion_id' => (int) $n['id'], 'canal' => $n['canal'], 'codigo' => $codigo, 'detalle' => $detalle]);
    }

    /** @param array<string, mixed> $n */
    private function bookingStartsBefore(array $n, int $ts): bool
    {
        $stmt = Database::connection()->prepare('SELECT inicio_utc FROM reservas WHERE tenant_id = ? AND id = ?');
        $stmt->execute([(int) $n['tenant_id'], (int) $n['reserva_id']]);
        $ini = $stmt->fetchColumn();
        return $ini !== false && strtotime($ini . ' UTC') <= $ts;
    }

    /** @param array<string, mixed> $n */
    private function log(array $n, int $intento, string $proveedor, string $resultado, ?string $codigo, ?string $detalle, ?int $dispositivos, ?int $ms): void
    {
        Database::connection()->prepare(
            'INSERT INTO notificacion_envios (notificacion_id, tenant_id, intento, canal, proveedor, resultado, codigo, detalle, dispositivos, duracion_ms, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([(int) $n['id'], $n['tenant_id'], min(255, max(1, $intento)), (string) $n['canal'], mb_substr($proveedor, 0, 30), $resultado,
            $codigo !== null ? mb_substr($codigo, 0, 60) : null, $detalle !== null ? mb_substr($detalle, 0, 1000) : null, $dispositivos, $ms, Clock::nowSql()]);
    }

    private static function ms(float $t0): int
    {
        return (int) round((microtime(true) - $t0) * 1000);
    }
}
