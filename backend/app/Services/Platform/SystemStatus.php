<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Core\Config;
use App\Core\Database;
use App\Helpers\Clock;
use App\Services\Mail\Mailer;

/**
 * Estado de la plataforma para el superadministrador (Fase 9): cola de avisos, errores de
 * envío, proveedores configurados, webhooks, auditoría y errores del log de la aplicación.
 * Solo cifras y códigos: nunca destinatarios, textos de los avisos ni datos de clientes.
 */
final class SystemStatus
{
    /** @return array<string, mixed> */
    public static function snapshot(): array
    {
        $db = Database::connection();
        $now = Clock::nowUtc();
        $nowSql = $now->format('Y-m-d H:i:s');
        $h24 = $now->modify('-1 day')->format('Y-m-d H:i:s');
        $d7 = $now->modify('-7 days')->format('Y-m-d H:i:s');
        $m5 = $now->modify('-5 minutes')->format('Y-m-d H:i:s');

        $cola = ['programada' => 0, 'procesando' => 0, 'fallida_24h' => 0];
        $stmt = $db->prepare("SELECT estado, COUNT(*) n FROM notificaciones WHERE estado IN ('programada','procesando') AND canal <> 'interna' GROUP BY estado");
        $stmt->execute();
        foreach ($stmt->fetchAll() as $r) {
            $cola[(string) $r['estado']] = (int) $r['n'];
        }
        $q = $db->prepare("SELECT COUNT(*) FROM notificaciones WHERE estado = 'fallida' AND updated_at >= ?");
        $q->execute([$h24]);
        $cola['fallida_24h'] = (int) $q->fetchColumn();
        $q = $db->prepare("SELECT COUNT(*) FROM notificaciones WHERE estado = 'programada' AND canal <> 'interna' AND programada_para_utc < ? AND (proximo_intento_utc IS NULL OR proximo_intento_utc < ?)");
        $q->execute([$m5, $m5]);
        $atrasadas = (int) $q->fetchColumn();

        $envios = [];
        $q = $db->prepare('SELECT canal, resultado, COUNT(*) n FROM notificacion_envios WHERE created_at >= ? GROUP BY canal, resultado ORDER BY canal, resultado');
        $q->execute([$h24]);
        foreach ($q->fetchAll() as $r) {
            $envios[] = ['canal' => (string) $r['canal'], 'resultado' => (string) $r['resultado'], 'total' => (int) $r['n']];
        }
        $q = $db->prepare(
            "SELECT canal, proveedor, COALESCE(codigo, 'sin_codigo') codigo, COUNT(*) n, MAX(created_at) ultimo
               FROM notificacion_envios WHERE resultado IN ('error_temporal','error_permanente') AND created_at >= ?
              GROUP BY canal, proveedor, codigo ORDER BY n DESC LIMIT 10"
        );
        $q->execute([$d7]);
        $errores = array_map(static fn (array $r): array => [
            'canal' => (string) $r['canal'], 'proveedor' => (string) $r['proveedor'], 'codigo' => (string) $r['codigo'],
            'total' => (int) $r['n'], 'ultimo' => self::iso((string) $r['ultimo']),
        ], $q->fetchAll());
        $ultimoEnvio = $db->query("SELECT MAX(created_at) FROM notificacion_envios WHERE resultado = 'enviada'")->fetchColumn();

        $q = $db->prepare("SELECT COUNT(*) FROM webhook_eventos WHERE estado = 'fallido' AND recibido_en >= ?");
        $q->execute([$d7]);
        $webhooks = (int) $q->fetchColumn();

        $q = $db->prepare("SELECT resultado, COUNT(*) n FROM auditoria WHERE created_at >= ? AND resultado IN ('denegado','error') GROUP BY resultado");
        $q->execute([$h24]);
        $audit = ['denegado' => 0, 'error' => 0];
        foreach ($q->fetchAll() as $r) {
            $audit[(string) $r['resultado']] = (int) $r['n'];
        }

        $pushDriver = (string) Config::get('notifications.push.driver', 'log');
        $fcmCreds = (string) Config::get('notifications.push.fcm.credentials', '');
        $alertas = [];
        if ($atrasadas > 0) {
            $alertas[] = ['nivel' => 'error', 'codigo' => 'cola_atrasada', 'mensaje' => "{$atrasadas} aviso(s) esperan más de 5 minutos: revisa que el cron de notificaciones:procesar esté activo."];
        }
        if ($pushDriver === 'fcm' && ($fcmCreds === '' || !is_readable($fcmCreds))) {
            $alertas[] = ['nivel' => 'error', 'codigo' => 'fcm_sin_credenciales', 'mensaje' => 'PUSH_DRIVER=fcm pero el archivo de credenciales no existe o no se puede leer.'];
        }
        if (in_array($pushDriver, ['log', 'array'], true) || Mailer::driver() === 'log') {
            $alertas[] = ['nivel' => 'info', 'codigo' => 'modo_desarrollo', 'mensaje' => 'Push o correo están en modo de registro local: los avisos no llegan a las personas.'];
        }
        if ($cola['fallida_24h'] > 0) {
            $alertas[] = ['nivel' => 'warning', 'codigo' => 'envios_fallidos', 'mensaje' => "{$cola['fallida_24h']} aviso(s) fallaron definitivamente en las últimas 24 h."];
        }
        $logs = self::logErrors($now->getTimestamp() - 86400);
        if ($logs['total'] > 0) {
            $alertas[] = ['nivel' => 'warning', 'codigo' => 'errores_log', 'mensaje' => "{$logs['total']} error(es) registrados por la aplicación en las últimas 24 h."];
        }

        return [
            'generado_en' => self::iso($nowSql),
            'app' => ['version' => (string) Config::get('app.version', ''), 'entorno' => (string) Config::get('app.env', 'production'), 'php' => PHP_VERSION,
                'mysql' => (string) $db->query('SELECT VERSION()')->fetchColumn()],
            'integraciones' => [
                'push' => ['driver' => $pushDriver, 'credenciales' => $pushDriver === 'fcm' ? ($fcmCreds !== '' && is_readable($fcmCreds)) : null],
                'correo' => ['driver' => Mailer::driver()],
                'dispositivos_activos' => (int) $db->query('SELECT COUNT(*) FROM dispositivos_push WHERE activo = 1')->fetchColumn(),
            ],
            'cola' => $cola + ['atrasadas' => $atrasadas, 'ultimo_envio' => $ultimoEnvio ? self::iso((string) $ultimoEnvio) : null],
            'envios_24h' => $envios,
            'errores_envio_7_dias' => $errores,
            'webhooks_fallidos_7_dias' => $webhooks,
            'auditoria_24h' => $audit,
            'log' => $logs,
            'alertas' => $alertas,
        ];
    }

    /**
     * Errores del log de la aplicación (storage/logs) desde $sinceTs: total y los últimos 5
     * mensajes (sin el contexto, que puede traer datos de la petición).
     *
     * @return array{total:int, ultimos:list<array{fecha:string, nivel:string, mensaje:string}>}
     */
    public static function logErrors(int $sinceTs): array
    {
        $dir = (string) Config::get('app.log_path', dirname(__DIR__, 3) . '/storage/logs');
        $total = 0;
        $last = [];
        foreach ([gmdate('Y-m-d', $sinceTs), gmdate('Y-m-d', Clock::nowUtc()->getTimestamp())] as $i => $day) {
            if ($i === 1 && $day === gmdate('Y-m-d', $sinceTs)) {
                break;
            }
            $file = $dir . '/app-' . $day . '.log';
            if (!is_file($file) || filesize($file) > 50 * 1024 * 1024) {
                continue;
            }
            $fh = fopen($file, 'r');
            if ($fh === false) {
                continue;
            }
            while (($line = fgets($fh)) !== false) {
                if (strpos($line, '"level":"error"') === false && strpos($line, '"level":"critical"') === false) {
                    continue;
                }
                $j = json_decode($line, true);
                if (!is_array($j) || strtotime((string) ($j['ts'] ?? '')) < $sinceTs) {
                    continue;
                }
                $total++;
                $last[] = ['fecha' => (string) $j['ts'], 'nivel' => (string) $j['level'], 'mensaje' => mb_substr((string) ($j['message'] ?? ''), 0, 200)];
                if (count($last) > 5) {
                    array_shift($last);
                }
            }
            fclose($fh);
        }
        return ['total' => $total, 'ultimos' => array_reverse($last)];
    }

    private static function iso(string $sql): string
    {
        return str_replace(' ', 'T', $sql) . 'Z';
    }
}
