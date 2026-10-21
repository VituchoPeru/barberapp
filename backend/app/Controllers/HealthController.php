<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Clock;
use Throwable;

/**
 * Verificación de salud.
 *   GET /api/v1/health       → app + base de datos + migraciones (503 si la BD falla)
 *   GET /api/v1/health/live  → solo confirma que PHP responde (sin tocar la BD)
 */
final class HealthController extends BaseController
{
    public function live(Request $request): Response
    {
        return $this->ok(['status' => 'ok', 'time_utc' => Clock::nowUtc()->format('Y-m-d\TH:i:s\Z')]);
    }

    public function show(Request $request): Response
    {
        $debug = (bool) Config::get('app.debug', false);
        $database = ['status' => 'ok'];
        $migrations = null;
        $healthy = true;

        try {
            $start = microtime(true);
            $pdo = Database::connection();
            $row = $pdo->query('SELECT VERSION() AS version, @@session.time_zone AS tz, NOW() AS now_utc')->fetch();
            $database['latency_ms'] = round((microtime(true) - $start) * 1000, 2);
            $database['session_time_zone'] = $row['tz'];
            if ($debug) {
                $database['version'] = $row['version'];
            }

            $migrations = $this->migrationStatus($pdo);
            if ($migrations['pendientes'] > 0) {
                $healthy = false;
            }
        } catch (Throwable $e) {
            $healthy = false;
            $database = ['status' => 'error'];
            if ($debug) {
                $database['message'] = $e->getMessage();
            }
        }

        $data = [
            'status' => $healthy ? 'ok' : 'degraded',
            'app' => Config::get('app.name'),
            'version' => Config::get('app.version'),
            'environment' => Config::get('app.env'),
            'time_utc' => Clock::nowUtc()->format('Y-m-d\TH:i:s\Z'),
            'php' => $debug ? PHP_VERSION : PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'checks' => [
                'database' => $database,
                'migrations' => $migrations,
            ],
        ];

        return Response::success($data, $healthy ? 200 : 503);
    }

    /** @return array{aplicadas:int, pendientes:int, ultima:?string} */
    private function migrationStatus(\PDO $pdo): array
    {
        $dir = dirname(__DIR__, 2) . '/database/migrations';
        $files = array_map('basename', glob($dir . '/*.sql') ?: []);
        sort($files);

        $applied = [];
        $exists = $pdo->query("SHOW TABLES LIKE 'migraciones'")->fetchColumn();
        if ($exists !== false) {
            $applied = $pdo->query('SELECT migracion FROM migraciones ORDER BY migracion')->fetchAll(\PDO::FETCH_COLUMN);
        }
        $pending = array_values(array_diff($files, $applied));

        return [
            'aplicadas' => count($applied),
            'pendientes' => count($pending),
            'ultima' => $applied === [] ? null : (string) end($applied),
        ];
    }
}
