<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Conexión PDO única por proceso.
 *
 * - Errores como excepciones, sin emulación de sentencias preparadas.
 * - utf8mb4 y zona horaria de sesión en UTC: todas las fechas de eventos se guardan en UTC.
 * - Modo SQL estricto para rechazar datos truncados o inválidos.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect(Config::get('database', []));
        }
        return self::$pdo;
    }

    /**
     * @param array<string, mixed> $cfg
     */
    public static function connect(array $cfg, bool $withDatabase = true): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            $cfg['host'] ?? '127.0.0.1',
            (int) ($cfg['port'] ?? 3306),
            $cfg['charset'] ?? 'utf8mb4'
        );
        if ($withDatabase && !empty($cfg['database'])) {
            $dsn .= ';dbname=' . $cfg['database'];
        }
        if (!empty($cfg['socket'])) {
            $dsn = sprintf('mysql:unix_socket=%s;charset=%s', $cfg['socket'], $cfg['charset'] ?? 'utf8mb4')
                . ($withDatabase && !empty($cfg['database']) ? ';dbname=' . $cfg['database'] : '');
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => (int) ($cfg['timeout'] ?? 5),
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ];
        if (!empty($cfg['ssl_ca'])) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $cfg['ssl_ca'];
        }

        try {
            $pdo = new PDO($dsn, (string) ($cfg['username'] ?? ''), (string) ($cfg['password'] ?? ''), $options);
        } catch (PDOException $e) {
            // No propagar el DSN ni credenciales en el mensaje.
            throw new RuntimeException('No se pudo conectar a la base de datos: ' . $e->getMessage(), 0, $e);
        }

        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY'");

        return $pdo;
    }

    /**
     * Ejecuta $fn dentro de una transacción. Hace rollback ante cualquier excepción.
     *
     * @template T
     * @param callable(PDO): T $fn
     * @return T
     */
    public static function transaction(callable $fn)
    {
        $pdo = self::connection();
        if ($pdo->inTransaction()) {
            throw new RuntimeException('Transacción anidada no soportada; reutilice la transacción en curso.');
        }
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Permite inyectar una conexión (pruebas) o reiniciarla. */
    public static function setConnection(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }
}
