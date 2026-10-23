<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Clock;
use App\Services\Ops\Monitor;
use Throwable;

/**
 * Verificación de salud.
 *   GET /api/v1/health       → app + base de datos + migraciones (503 si la BD falla)
 *   GET /api/v1/health/live  → solo confirma que PHP responde (sin tocar la BD)
 *   GET /api/v1/health/operacion (Fase 15) → monitor:revisar para monitores externos, con
 *       Authorization: Bearer MONITOR_TOKEN; 503 si hay algún problema. Sin token configurado, 404.
 */
final class HealthController extends BaseController
{
    public function live(Request $request): Response
    {
        return $this->ok(['status' => 'ok', 'time_utc' => Clock::nowUtc()->format('Y-m-d\TH:i:s\Z')]);
    }

    public function operation(Request $request): Response
    {
        $token = (string) Config::get('ops.token', '');
        if ($token === '') {
            return Response::error(404, 'not_found', 'Recurso no encontrado.');
        }
        $given = (string) ($request->bearerToken() ?? '');
        if ($given === '' || !hash_equals(hash('sha256', $token), hash('sha256', $given))) {
            return Response::error(401, 'unauthenticated', 'Token de monitoreo inválido.');
        }
        $checks = Monitor::run(dirname(__DIR__, 2), $request->query('certificados') === '1');
        $problems = array_values(array_filter($checks, static fn (array $c): bool => $c['nivel'] === 'error'));
        $response = Response::success([
            'status' => $problems === [] ? 'ok' : 'error',
            'time_utc' => Clock::nowUtc()->format('Y-m-d\TH:i:s\Z'),
            'problemas' => count($problems),
            'puntos' => $checks,
        ], $problems === [] ? 200 : 503);
        $response->setHeader('Cache-Control', 'no-store');
        return $response;
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
            'maintenance' => \App\Services\Ops\Maintenance::status() !== null,
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
