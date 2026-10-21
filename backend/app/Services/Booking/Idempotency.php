<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Clock;
use PDOException;

/**
 * Claves de idempotencia (cabecera Idempotency-Key) para operaciones que no deben repetirse
 * cuando la red falla y la app reintenta: crear y reprogramar reservas.
 *
 * La clave se inserta en la MISMA transacción que la operación:
 *  - Si dos peticiones con la misma clave llegan a la vez, la segunda espera en el índice único
 *    hasta que la primera confirma y luego devuelve la respuesta guardada (sin repetir la reserva).
 *  - Si la operación falla, la transacción se revierte junto con la clave y el reintento vuelve a
 *    ejecutarse (no se guarda un error transitorio como respuesta definitiva).
 *  - La misma clave con otro contenido se rechaza (422), para no devolver una reserva distinta.
 */
final class Idempotency
{
    public const HEADER = 'Idempotency-Key';
    public const TTL_HOURS = 24;
    private const FORMAT = '/^[A-Za-z0-9_\-:.]{8,100}$/';

    /**
     * Ejecuta $fn una sola vez por (usuario, método, ruta, clave).
     *
     * @param callable(): array{status:int, data:mixed, recurso_id?:?int} $fn
     */
    public static function run(Request $request, int $userId, ?int $tenantId, string $recurso, callable $fn): Response
    {
        $key = trim((string) $request->header(self::HEADER, ''));
        if ($key === '') {
            throw new HttpException(428, 'idempotency_key_required', 'Falta la cabecera Idempotency-Key: genera un identificador único por intento de reserva y reutilízalo al reintentar.');
        }
        if (!preg_match(self::FORMAT, $key)) {
            throw new HttpException(422, 'idempotency_key_invalid', 'Idempotency-Key inválida: usa de 8 a 100 caracteres (letras, números, guion, punto o dos puntos).');
        }
        $method = $request->method();
        $endpoint = substr($request->path(), 0, 191);
        $hash = hash('sha256', $method . ' ' . $endpoint . "\n" . self::canonical($request->json()));

        $result = Database::transaction(static function ($pdo) use ($key, $method, $endpoint, $hash, $userId, $tenantId, $recurso, $fn): array {
            $now = Clock::nowSql();
            $pdo->prepare('DELETE FROM idempotency_keys WHERE usuario_id = ? AND metodo = ? AND endpoint = ? AND clave = ? AND expira_en < ?')
                ->execute([$userId, $method, $endpoint, $key, $now]);
            try {
                $pdo->prepare(
                    'INSERT INTO idempotency_keys (usuario_id, tenant_id, clave, metodo, endpoint, request_hash, estado, recurso_tipo, expira_en)
                     VALUES (?, ?, ?, ?, ?, ?, \'procesando\', ?, ?)'
                )->execute([$userId, $tenantId, $key, $method, $endpoint, $hash, $recurso, Clock::nowUtc()->modify('+' . self::TTL_HOURS . ' hours')->format('Y-m-d H:i:s')]);
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
                return self::replay($pdo, $userId, $method, $endpoint, $key, $hash);
            }
            $out = $fn();
            $pdo->prepare(
                "UPDATE idempotency_keys SET estado = 'completada', codigo_respuesta = ?, respuesta = ?, recurso_id = ?
                  WHERE usuario_id = ? AND metodo = ? AND endpoint = ? AND clave = ?"
            )->execute([$out['status'], json_encode($out['data'], JSON_UNESCAPED_UNICODE), $out['recurso_id'] ?? null, $userId, $method, $endpoint, $key]);
            return $out + ['replayed' => false];
        }, Database::READ_COMMITTED);

        $response = Response::success($result['data'], (int) $result['status']);
        if ($result['replayed']) {
            $response->setHeader('Idempotent-Replayed', 'true');
        }
        return $response;
    }

    /** @return array{status:int, data:mixed, replayed:bool} */
    private static function replay(\PDO $pdo, int $userId, string $method, string $endpoint, string $key, string $hash): array
    {
        $stmt = $pdo->prepare('SELECT request_hash, estado, codigo_respuesta, respuesta FROM idempotency_keys WHERE usuario_id = ? AND metodo = ? AND endpoint = ? AND clave = ?');
        $stmt->execute([$userId, $method, $endpoint, $key]);
        $row = $stmt->fetch();
        if ($row === false || $row['estado'] !== 'completada') {
            throw new HttpException(409, 'idempotency_in_progress', 'Tu solicitud anterior aún se está procesando. Espera unos segundos y revisa tus reservas.');
        }
        if (!hash_equals((string) $row['request_hash'], $hash)) {
            throw new HttpException(422, 'idempotency_key_reused', 'Esta Idempotency-Key ya se usó con otros datos. Genera una nueva para una reserva distinta.');
        }
        return ['status' => (int) $row['codigo_respuesta'], 'data' => json_decode((string) $row['respuesta'], true), 'replayed' => true];
    }

    /** JSON con claves ordenadas: el mismo contenido produce el mismo hash aunque cambie el orden. @param mixed $v */
    private static function canonical($v): string
    {
        $sort = static function ($x) use (&$sort) {
            if (is_array($x)) {
                $isList = $x === [] || array_keys($x) === range(0, count($x) - 1);
                if (!$isList) {
                    ksort($x);
                }
                return array_map($sort, $x);
            }
            return $x;
        };
        return (string) json_encode($sort($v), JSON_UNESCAPED_UNICODE);
    }

    /** Borra claves vencidas (programar en cron diario). */
    public static function purge(): int
    {
        $stmt = Database::connection()->prepare('DELETE FROM idempotency_keys WHERE expira_en < ?');
        $stmt->execute([Clock::nowSql()]);
        return $stmt->rowCount();
    }
}
