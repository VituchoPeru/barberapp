<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use App\Helpers\Clock;
use App\Services\Backup\BackupService;
use App\Services\Backup\OffsiteBackup;
use App\Services\Mail\MailMessage;
use App\Services\Mail\Mailer;
use App\Services\Platform\SystemStatus;
use Throwable;

/**
 * Monitoreo de producción (Fase 15): php bin/console monitor:revisar [--alertar]
 *
 * Revisa lo que una caída silenciosa no avisa por sí sola: la base, migraciones pendientes,
 * el worker de avisos y cada cron (por sus latidos), la antigüedad de la última copia y de su
 * prueba de restauración, las copias fuera del servidor, el espacio en disco, los errores de la
 * última hora, el vencimiento de los certificados HTTPS y si quedó activo el mantenimiento.
 *
 * Nivel de cada punto: ok | aviso | error. Con --alertar envía un correo a MONITOR_EMAIL cuando
 * aparece un problema, lo repite cada MONITOR_REPETIR_HORAS mientras siga y avisa al resolverse.
 * Solo cifras y nombres de tareas: nunca datos de clientes.
 */
final class Monitor
{
    /** @return list<array{nivel: string, tema: string, mensaje: string}> */
    public static function run(string $basePath, bool $checkCertificates = true): array
    {
        $r = [];
        $add = static function (string $nivel, string $tema, string $mensaje) use (&$r): void {
            $r[] = ['nivel' => $nivel, 'tema' => $tema, 'mensaje' => $mensaje];
        };

        // Base de datos y migraciones
        try {
            $db = Database::connection();
            $start = microtime(true);
            $db->query('SELECT 1')->fetchColumn();
            $ms = round((microtime(true) - $start) * 1000, 1);
            $add($ms > 500 ? 'aviso' : 'ok', 'Base de datos', "Responde en {$ms} ms.");
            $status = (new Migrator($db, $basePath . '/database/migrations'))->status();
            $pending = array_filter($status, static fn (array $m): bool => $m['estado'] === 'pendiente');
            $changed = array_filter($status, static fn (array $m): bool => !in_array($m['estado'], ['pendiente', 'aplicada'], true));
            $add($pending === [] ? 'ok' : 'error', 'Migraciones', $pending === [] ? 'Todas aplicadas.' : count($pending) . ' migración(es) pendiente(s): ' . implode(', ', array_column($pending, 'migracion')) . '.');
            if ($changed !== []) {
                $add('aviso', 'Migraciones', 'Archivos modificados después de aplicarse: ' . implode(', ', array_column($changed, 'migracion')) . '.');
            }

            $m5 = Clock::nowUtc()->modify('-5 minutes')->format('Y-m-d H:i:s');
            $q = $db->prepare("SELECT COUNT(*) FROM notificaciones WHERE estado = 'programada' AND canal <> 'interna' AND programada_para_utc < ? AND (proximo_intento_utc IS NULL OR proximo_intento_utc < ?)");
            $q->execute([$m5, $m5]);
            $late = (int) $q->fetchColumn();
            $add($late === 0 ? 'ok' : 'error', 'Cola de avisos', $late === 0 ? 'Sin avisos atrasados.' : "{$late} aviso(s) esperan más de 5 minutos.");
        } catch (Throwable $e) {
            $add('error', 'Base de datos', 'No responde: ' . mb_substr($e->getMessage(), 0, 200));
        }

        // Tareas programadas (latidos)
        foreach ((array) Config::get('ops.heartbeats', []) as $task => $maxMinutes) {
            $maxMinutes = (int) $maxMinutes;
            if ($maxMinutes <= 0 || ($task === 'backup:enviar' && !OffsiteBackup::configured())) {
                continue;
            }
            $age = Heartbeat::ageMinutes((string) $task);
            if ($age === null) {
                $add('error', "Tarea {$task}", 'Nunca registró una ejecución correcta: revisa el cron (docs/DESPLIEGUE.md).');
            } elseif ($age > $maxMinutes) {
                $add('error', "Tarea {$task}", 'Última ejecución correcta hace ' . self::ago($age) . ' (máximo ' . self::ago($maxMinutes) . ').');
            } else {
                $add('ok', "Tarea {$task}", 'Última ejecución correcta hace ' . self::ago($age) . '.');
            }
        }
        if (!OffsiteBackup::configured()) {
            $add(self::production() ? 'error' : 'aviso', 'Copias fuera del servidor', 'No configuradas: si se pierde el servidor se pierden también las copias (BACKUP_S3_*).');
        }

        // Última copia local
        try {
            $copies = (new BackupService())->list();
            if ($copies === []) {
                $add('error', 'Copias', 'No hay ninguna copia en el servidor.');
            } else {
                $age = intdiv(Clock::nowUtc()->getTimestamp() - (int) filemtime($copies[0]), 60);
                $add($age > 26 * 60 ? 'error' : 'ok', 'Copias', 'La más reciente es de hace ' . self::ago($age) . ' (' . count($copies) . ' guardadas).');
            }
        } catch (Throwable $e) {
            $add('error', 'Copias', mb_substr($e->getMessage(), 0, 200));
        }

        // Disco
        $minFree = max(1, (int) Config::get('ops.min_free_disk_percent', 10));
        $paths = ['storage' => $basePath . '/storage', 'copias' => (string) Config::get('backup.path')];
        $seen = [];
        foreach ($paths as $label => $path) {
            $path = is_dir($path) ? $path : dirname($path);
            $total = @disk_total_space($path);
            $free = @disk_free_space($path);
            if (!$total || $free === false) {
                continue;
            }
            $id = (string) round($total) . ':' . (string) round($free / 1048576);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $pct = round($free / $total * 100, 1);
            $add($pct < $minFree ? 'error' : ($pct < $minFree * 2 ? 'aviso' : 'ok'), "Disco ({$label})", sprintf('%.1f GB libres (%s %%).', $free / 1073741824, $pct));
        }

        // Errores recientes de la aplicación
        $errors = SystemStatus::logErrors(Clock::nowUtc()->getTimestamp() - 3600);
        $max = max(1, (int) Config::get('ops.max_errors_per_hour', 20));
        $add($errors['total'] >= $max ? 'error' : ($errors['total'] > 0 ? 'aviso' : 'ok'), 'Errores', $errors['total'] === 0
            ? 'Sin errores en la última hora.'
            : "{$errors['total']} error(es) en la última hora; el último: " . ($errors['ultimos'][0]['mensaje'] ?? '') . '.');

        // Mantenimiento olvidado
        $maint = Maintenance::status();
        if ($maint !== null) {
            $since = strtotime($maint['desde']) ?: Clock::nowUtc()->getTimestamp();
            $mins = intdiv(Clock::nowUtc()->getTimestamp() - $since, 60);
            $add($mins > 30 ? 'error' : 'aviso', 'Mantenimiento', 'La API está en mantenimiento desde hace ' . self::ago($mins) . ' (php bin/console mantenimiento:desactivar).');
        }

        // Certificados HTTPS
        $minDays = (int) Config::get('ops.min_cert_days', 14);
        if ($checkCertificates && $minDays > 0) {
            $hosts = [];
            foreach ([Config::get('app.url'), Config::get('app.frontend_url')] as $url) {
                $p = parse_url((string) $url);
                if (($p['scheme'] ?? '') === 'https' && isset($p['host'])) {
                    $hosts[(string) $p['host'] . ':' . (int) ($p['port'] ?? 443)] = true;
                }
            }
            foreach (array_keys($hosts) as $host) {
                $days = self::certificateDays($host);
                if ($days === null) {
                    $add('aviso', "Certificado {$host}", 'No se pudo leer el certificado.');
                } else {
                    $add($days < $minDays ? 'error' : 'ok', "Certificado {$host}", $days < 0 ? 'Vencido.' : "Vence en {$days} día(s).");
                }
            }
        }
        return $r;
    }

    /**
     * Envía la alerta si el estado cambió (o si un problema sigue tras MONITOR_REPETIR_HORAS).
     *
     * @param list<array{nivel: string, tema: string, mensaje: string}> $checks
     * @return string|null el asunto del correo enviado, o null si no correspondía enviar
     */
    public static function alert(array $checks): ?string
    {
        $email = (string) Config::get('ops.alert_email', '');
        $stateFile = (string) Config::get('ops.alert_state_file');
        $problems = array_values(array_filter($checks, static fn (array $c): bool => $c['nivel'] === 'error'));
        $signature = implode('|', array_map(static fn (array $c): string => $c['tema'], $problems));
        $prev = is_file($stateFile) ? (json_decode((string) @file_get_contents($stateFile), true) ?: []) : [];
        $now = Clock::nowUtc()->getTimestamp();
        $repeat = max(1, (int) Config::get('ops.alert_repeat_hours', 6)) * 3600;

        $subject = null;
        if ($problems !== [] && ($signature !== ($prev['firma'] ?? '') || $now - (int) ($prev['enviado'] ?? 0) >= $repeat)) {
            $subject = '[BARBERAPP] ' . count($problems) . ' problema(s) en ' . (string) Config::get('app.env') . ': ' . mb_substr($signature, 0, 120);
        } elseif ($problems === [] && ($prev['firma'] ?? '') !== '') {
            $subject = '[BARBERAPP] Resuelto: ' . mb_substr((string) $prev['firma'], 0, 120);
        }
        if ($subject === null) {
            return null;
        }
        $sent = false;
        if ($email !== '') {
            $lines = array_map(static fn (array $c): string => strtoupper($c['nivel']) . ' · ' . $c['tema'] . ': ' . $c['mensaje'], $checks);
            $text = "Revisión de " . (string) Config::get('app.url') . ' · ' . Clock::nowUtc()->format('Y-m-d H:i') . " UTC\n\n" . implode("\n", $lines)
                . "\n\nRevisión manual: php bin/console monitor:revisar\nGuía: docs/DESPLIEGUE.md (Monitoreo)\n";
            $sent = Mailer::send(new MailMessage($email, $subject, $text));
        }
        if ($sent || $email === '') {
            @file_put_contents($stateFile, (string) json_encode(['firma' => $signature, 'enviado' => $problems === [] ? 0 : $now]));
        }
        return $email !== '' && $sent ? $subject : null;
    }

    /** Días que le quedan al certificado de host:puerto (negativo = vencido), o null si no se pudo leer. */
    public static function certificateDays(string $hostPort): ?int
    {
        [$host, $port] = array_pad(explode(':', $hostPort, 2), 2, '443');
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $client = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
        if ($client === false) {
            return null;
        }
        $params = stream_context_get_params($client);
        fclose($client);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        $info = $cert ? openssl_x509_parse($cert) : false;
        if (!is_array($info) || !isset($info['validTo_time_t'])) {
            return null;
        }
        return (int) floor(((int) $info['validTo_time_t'] - Clock::nowUtc()->getTimestamp()) / 86400);
    }

    private static function production(): bool
    {
        return !in_array((string) Config::get('app.env'), ['local', 'testing'], true);
    }

    private static function ago(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} min";
        }
        if ($minutes < 48 * 60) {
            return round($minutes / 60, 1) . ' h';
        }
        return round($minutes / 1440, 1) . ' días';
    }
}
