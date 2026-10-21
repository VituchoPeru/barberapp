<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Helpers\Clock;
use PDO;

/**
 * Limitador de intentos con ventana fija, persistido en MySQL (tabla limites_intentos)
 * para que funcione igual con varios procesos PHP o servidores.
 *
 *   $limiter->ensureNotLimited('login_cuenta', $email . '|' . $ip);  // antes de verificar
 *   $limiter->hit('login_cuenta', $email . '|' . $ip);               // al fallar
 *   $limiter->clear('login_cuenta', $email . '|' . $ip);             // al acertar
 */
final class RateLimiter
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /** @return array{0:int, 1:int} [máximo, ventana en segundos] */
    private function limit(string $ambito): array
    {
        $limit = Config::get('auth.limits.' . $ambito);
        if (!is_array($limit) || count($limit) !== 2) {
            throw new \InvalidArgumentException("Límite no configurado: {$ambito}");
        }
        return [(int) $limit[0], (int) $limit[1]];
    }

    private function key(string $ambito, string $identifier): string
    {
        return hash('sha256', $ambito . '|' . mb_strtolower($identifier));
    }

    /** Segundos restantes de bloqueo, o 0 si aún hay intentos disponibles. */
    public function retryAfter(string $ambito, string $identifier): int
    {
        [$max, $window] = $this->limit($ambito);
        $stmt = $this->db->prepare('SELECT intentos, ventana_inicio FROM limites_intentos WHERE clave = ?');
        $stmt->execute([$this->key($ambito, $identifier)]);
        $row = $stmt->fetch();
        if (!$row) {
            return 0;
        }
        $now = Clock::nowUtc()->getTimestamp();
        $end = strtotime($row['ventana_inicio'] . ' UTC') + $window;
        if ($end <= $now || (int) $row['intentos'] < $max) {
            return 0;
        }
        return max(1, $end - $now);
    }

    /** Lanza 429 si el identificador agotó sus intentos en la ventana actual. */
    public function ensureNotLimited(string $ambito, string $identifier): void
    {
        $retry = $this->retryAfter($ambito, $identifier);
        if ($retry > 0) {
            throw new HttpException(
                429,
                'too_many_attempts',
                'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.',
                ['retry_after' => $retry],
                ['Retry-After' => (string) $retry]
            );
        }
    }

    /** Suma un intento (de forma atómica) y devuelve el total en la ventana actual. */
    public function hit(string $ambito, string $identifier): int
    {
        [, $window] = $this->limit($ambito);
        $now = Clock::nowSql();
        $limite = Clock::nowUtc()->modify("-{$window} seconds")->format('Y-m-d H:i:s');
        $key = $this->key($ambito, $identifier);

        // MySQL evalúa las asignaciones en orden: primero se reinicia o incrementa el contador
        // y, si quedó en 1, la ventana empieza ahora.
        $stmt = $this->db->prepare(
            'INSERT INTO limites_intentos (clave, ambito, intentos, ventana_inicio) VALUES (:k, :a, 1, :now)
             ON DUPLICATE KEY UPDATE
                intentos = IF(ventana_inicio <= :limite, 1, intentos + 1),
                ventana_inicio = IF(intentos = 1, :now2, ventana_inicio)'
        );
        $stmt->execute(['k' => $key, 'a' => $ambito, 'now' => $now, 'limite' => $limite, 'now2' => $now]);

        $count = $this->db->prepare('SELECT intentos FROM limites_intentos WHERE clave = ?');
        $count->execute([$key]);
        return (int) $count->fetchColumn();
    }

    /** Suma un intento y lanza 429 si con él se superó el máximo. Para acciones que siempre cuentan. */
    public function consume(string $ambito, string $identifier): void
    {
        $this->ensureNotLimited($ambito, $identifier);
        $this->hit($ambito, $identifier);
    }

    public function clear(string $ambito, string $identifier): void
    {
        $this->db->prepare('DELETE FROM limites_intentos WHERE clave = ?')->execute([$this->key($ambito, $identifier)]);
    }

    /** Limpieza periódica (cron): elimina contadores con ventanas vencidas hace más de un día. */
    public function purge(): int
    {
        $stmt = $this->db->prepare('DELETE FROM limites_intentos WHERE ventana_inicio < ?');
        $stmt->execute([Clock::nowUtc()->modify('-1 day')->format('Y-m-d H:i:s')]);
        return $stmt->rowCount();
    }
}
