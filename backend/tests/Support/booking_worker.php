<?php

declare(strict_types=1);

/*
 * Proceso independiente para las pruebas de concurrencia del motor de reservas (Fase 5).
 * Cada proceso abre SU PROPIA conexión a MySQL, espera hasta el instante acordado y ejecuta
 * la operación; así dos solicitudes compiten de verdad por el mismo horario.
 *
 *   php tests/Support/booking_worker.php '<json>'
 *   {"db": "barberapp_test", "email": "...", "action": "book|cancel", "body": {...}, "key": "...",
 *    "reserva_id": 1, "start_at": 1700000000.5, "delay_ms": 400}
 *
 * Imprime una línea JSON: {"status": 201, "code": null, "data": {...}, "replayed": false, "ms": 512}
 */

use App\Controllers\BookingsController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Services\Booking\BookingService;

$base = dirname(__DIR__, 2);
require $base . '/vendor/autoload.php';

$args = json_decode((string) ($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);
Env::load($base . '/.env');
Config::loadDirectory($base . '/config');
Config::set('app.env', 'testing');
Config::set('app.log_path', sys_get_temp_dir() . '/barberapp-test-logs');
Config::set('mail.driver', 'array');
date_default_timezone_set('UTC');
Config::set('database.database', (string) $args['db']);
Database::setConnection(Database::connect(Config::get('database')));

$stmt = Database::connection()->prepare('SELECT * FROM usuarios WHERE email = ?');
$stmt->execute([$args['email']]);
$user = $stmt->fetch();
unset($user['password_hash']);

$delay = (int) ($args['delay_ms'] ?? 0);
BookingService::$beforeWrite = $delay > 0 ? static function () use ($delay): void {
    usleep($delay * 1000);
} : null;

while (microtime(true) < (float) $args['start_at']) {
    usleep(500);
}
$t0 = microtime(true);
$out = ['status' => 0, 'code' => null, 'data' => null, 'replayed' => false];
try {
    if ($args['action'] === 'book') {
        $request = (new Request('POST', '/api/v1/reservas', [], ['Idempotency-Key' => (string) $args['key'], 'Content-Type' => 'application/json'],
            (string) json_encode($args['body']), ['REMOTE_ADDR' => '203.0.113.50']))->withAttribute('auth_user', $user);
        $response = (new BookingsController())->store($request);
        $out['status'] = $response->status();
        $out['data'] = $response->decoded()['data'] ?? null;
        $out['replayed'] = $response->header('Idempotent-Replayed') === 'true';
    } else {
        $tenant = (int) Database::connection()->query('SELECT tenant_id FROM reservas WHERE id = ' . (int) $args['reserva_id'])->fetchColumn();
        $out['data'] = (new BookingService($tenant))->cancel((int) $args['reserva_id'], $user, [], 'cliente', null);
        $out['status'] = 200;
    }
} catch (HttpException $e) {
    $out['status'] = $e->status();
    $out['code'] = $e->errorCode();
} catch (Throwable $e) {
    $out['status'] = 500;
    $out['code'] = get_class($e) . ': ' . $e->getMessage();
}
$out['ms'] = (int) round((microtime(true) - $t0) * 1000);
echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
