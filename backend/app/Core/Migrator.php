<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Migraciones versionadas en SQL plano (database/migrations/NNNN_nombre.sql).
 *
 * - Se aplican en orden alfabético y se registran en la tabla `migraciones` con su checksum.
 * - MySQL confirma implícitamente cada DDL, por lo que una migración fallida puede quedar a
 *   medias: el runner se detiene, informa la sentencia que falló y NO la registra.
 * - Una migración aplicada no debe editarse; si su checksum cambia, `status` lo advierte.
 */
final class Migrator
{
    public function __construct(private PDO $pdo, private string $directory)
    {
    }

    public function ensureTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS migraciones (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                migracion   VARCHAR(191) NOT NULL,
                checksum    CHAR(64)     NOT NULL,
                lote        INT UNSIGNED NOT NULL,
                aplicada_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_migraciones_migracion (migracion)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci"
        );
    }

    /** @return list<string> Rutas absolutas ordenadas. */
    public function files(): array
    {
        $files = glob(rtrim($this->directory, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    /** @return array<string, string> migracion => checksum */
    public function applied(): array
    {
        $this->ensureTable();
        $rows = $this->pdo->query('SELECT migracion, checksum FROM migraciones ORDER BY migracion')->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['migracion']] = (string) $row['checksum'];
        }
        return $out;
    }

    /** @return list<array{migracion:string, estado:string}> */
    public function status(): array
    {
        $applied = $this->applied();
        $out = [];
        foreach ($this->files() as $file) {
            $name = basename($file);
            if (!isset($applied[$name])) {
                $estado = 'pendiente';
            } elseif ($applied[$name] !== hash_file('sha256', $file)) {
                $estado = 'aplicada (¡archivo modificado!)';
            } else {
                $estado = 'aplicada';
            }
            $out[] = ['migracion' => $name, 'estado' => $estado];
        }
        return $out;
    }

    /**
     * @param callable(string): void|null $output
     * @return list<string> Migraciones aplicadas en esta ejecución.
     */
    public function migrate(?callable $output = null): array
    {
        $applied = $this->applied();
        $batch = (int) $this->pdo->query('SELECT COALESCE(MAX(lote), 0) + 1 FROM migraciones')->fetchColumn();
        $done = [];

        foreach ($this->files() as $file) {
            $name = basename($file);
            if (isset($applied[$name])) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            foreach (self::splitStatements($sql) as $i => $statement) {
                try {
                    $this->pdo->exec($statement);
                } catch (Throwable $e) {
                    throw new RuntimeException(sprintf(
                        "Falló la migración %s (sentencia #%d): %s\n%s",
                        $name,
                        $i + 1,
                        $e->getMessage(),
                        substr($statement, 0, 300)
                    ), 0, $e);
                }
            }
            $stmt = $this->pdo->prepare('INSERT INTO migraciones (migracion, checksum, lote) VALUES (?, ?, ?)');
            $stmt->execute([$name, hash_file('sha256', $file), $batch]);
            $done[] = $name;
            if ($output) {
                $output($name);
            }
        }
        return $done;
    }

    /** Elimina TODAS las tablas de la base actual. Solo para desarrollo y pruebas. */
    public function dropAllTables(): void
    {
        $tables = $this->pdo->query(
            "SELECT table_name FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                $this->pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', (string) $table) . '`');
            }
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * Divide un script en sentencias. Ignora comentarios "-- " de línea completa y separa
     * por ";" al final de línea (las migraciones no usan triggers ni procedimientos).
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $buffer = '';
        $statements = [];
        foreach ($lines as $line) {
            $trim = ltrim($line);
            if ($trim === '' || str_starts_with($trim, '-- ') || $trim === '--') {
                continue;
            }
            $buffer .= $line . "\n";
            if (preg_match('/;\s*$/', $line)) {
                $statement = trim(preg_replace('/;\s*$/', '', trim($buffer)) ?? '');
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }
        return $statements;
    }

    /** Concatena todas las migraciones en un único script instalable (database/schema.sql). */
    public static function buildSchema(string $directory, string $databaseName = 'barberapp'): string
    {
        $files = glob(rtrim($directory, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $out = "-- ============================================================\n"
            . "-- BARBERAPP SaaS · Script SQL completo para MySQL 8.0+\n"
            . "-- Generado con: php bin/console schema:build\n"
            . "-- Fuente de verdad: database/migrations/*.sql (no editar este archivo a mano)\n"
            . "-- ============================================================\n\n"
            . "CREATE DATABASE IF NOT EXISTS `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;\n"
            . "USE `{$databaseName}`;\n\n"
            . "SET NAMES utf8mb4;\n"
            . "SET time_zone = '+00:00';\n\n";

        $out .= "CREATE TABLE IF NOT EXISTS migraciones (\n"
            . "    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "    migracion   VARCHAR(191) NOT NULL,\n"
            . "    checksum    CHAR(64)     NOT NULL,\n"
            . "    lote        INT UNSIGNED NOT NULL,\n"
            . "    aplicada_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "    PRIMARY KEY (id),\n"
            . "    UNIQUE KEY uk_migraciones_migracion (migracion)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;\n\n";

        $register = [];
        foreach ($files as $file) {
            $out .= trim((string) file_get_contents($file)) . "\n\n";
            $register[] = sprintf("('%s', '%s', 1)", basename($file), hash_file('sha256', $file));
        }
        $out .= "-- Registra las migraciones incluidas para que `php bin/console migrate` no las repita.\n"
            . "INSERT INTO migraciones (migracion, checksum, lote) VALUES\n    "
            . implode(",\n    ", $register) . ";\n";
        return $out;
    }
}
