<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Core\Config;
use App\Core\Database;
use App\Helpers\Clock;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Copias de seguridad de la base de datos en PHP puro (funciona igual en XAMPP y en un VPS,
 * sin depender de mysqldump).
 *
 *  - create(): vuelca todas las tablas a SQL comprimido (gzip), una sentencia por línea, con un
 *    manifiesto de filas por tabla; lo cifra con BACKUP_KEY (AES-256-GCM) y guarda su SHA-256.
 *  - restore(): descifra, verifica el SHA-256 y ejecuta el SQL en la base indicada.
 *  - verify(): prueba de restauración: restaura la copia en una base temporal, compara las
 *    filas de cada tabla con el manifiesto y borra la base temporal.
 *
 * El archivo resultante también se puede restaurar a mano: descifrarlo con
 * `backup:restaurar --solo-descifrar` y luego `gunzip | mysql`.
 */
final class BackupService
{
    private const PREFIX = 'barberapp-';
    private const ROWS_PER_INSERT = 200;

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? (string) Config::get('backup.path'), '/\\');
    }

    public function directory(): string
    {
        return $this->dir;
    }

    private function key(): ?string
    {
        $raw = (string) Config::get('backup.key', '');
        return $raw === '' ? null : BackupCipher::parseKey($raw);
    }

    /**
     * Ruta de una copia. Acepta la ruta completa o solo el nombre del archivo, que se busca en
     * BACKUP_PATH (así funcionan `backup:verificar <nombre>` y `backup:restaurar <nombre>` tal como
     * los sugieren `backup:listar` y `backup:traer`, desde cualquier carpeta).
     */
    public function resolve(string $file): string
    {
        if (is_file($file)) {
            return $file;
        }
        $inDir = $this->dir . DIRECTORY_SEPARATOR . $file;
        if (basename($file) === $file && is_file($inDir)) {
            return $inDir;
        }
        throw new RuntimeException("No existe la copia {$file} (ni en {$this->dir}).");
    }

    // ================================================================== crear

    /** @return array{archivo: string, cifrado: bool, bytes: int, tablas: int, filas: int, sha256: string, borradas: list<string>} */
    public function create(?PDO $source = null): array
    {
        $key = $this->key();
        if ($key === null && !in_array((string) Config::get('app.env'), ['local', 'testing'], true)) {
            throw new RuntimeException('BACKUP_KEY es obligatoria fuera de local/testing: las copias contienen datos personales (php bin/console backup:clave).');
        }
        $this->ensureDir();
        $stamp = Clock::nowUtc()->format('Ymd-His');
        $plain = $this->dir . '/' . self::PREFIX . $stamp . '.sql.gz';
        $final = $key === null ? $plain : $plain . '.enc';
        if (file_exists($final)) {
            throw new RuntimeException('Ya existe una copia con esa hora. Espera un segundo e inténtalo de nuevo.');
        }
        $tmp = $this->dir . '/.tmp-' . bin2hex(random_bytes(6)) . '.sql.gz';

        $cfg = (array) Config::get('database');
        $db = $source ?? Database::connect($cfg);
        try {
            $manifest = $this->dump($db, $tmp, (string) ($cfg['database'] ?? ''));
            if ($key !== null) {
                BackupCipher::encryptFile($tmp, $final, $key);
                @unlink($tmp);
            } else {
                rename($tmp, $final);
            }
        } catch (Throwable $e) {
            @unlink($tmp);
            @unlink($final);
            throw $e;
        }
        @chmod($final, 0600);
        $sha = (string) hash_file('sha256', $final);
        file_put_contents($final . '.sha256', $sha . '  ' . basename($final) . "\n");
        @chmod($final . '.sha256', 0600);

        return [
            'archivo' => $final, 'cifrado' => $key !== null, 'bytes' => (int) filesize($final),
            'tablas' => count($manifest), 'filas' => array_sum($manifest), 'sha256' => $sha, 'borradas' => $this->rotate(),
        ];
    }

    /** @return array<string, int> filas por tabla */
    private function dump(PDO $db, string $file, string $database): array
    {
        $gz = gzopen($file, 'wb6');
        if ($gz === false) {
            throw new RuntimeException("No se pudo escribir {$file}.");
        }
        $w = static function (string $line) use ($gz): void {
            if (gzwrite($gz, $line . "\n") === false) {
                throw new RuntimeException('No se pudo escribir la copia (¿disco lleno?).');
            }
        };
        $w('-- BARBERAPP · copia de seguridad · ' . Clock::nowUtc()->format('Y-m-d\TH:i:s\Z') . ' · base ' . $database);
        $w('-- Restaurar: php bin/console backup:restaurar <archivo> --base=<nombre>');
        $w("SET NAMES utf8mb4;");
        $w("SET time_zone = '+00:00';");
        $w('SET FOREIGN_KEY_CHECKS = 0;');
        $w('SET UNIQUE_CHECKS = 0;');

        // Instantánea coherente: todas las tablas se leen como estaban en el mismo instante (InnoDB).
        $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $manifest = [];
        try {
            $tables = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
            foreach ($tables as [$table]) {
                $t = (string) $table;
                $q = '`' . str_replace('`', '``', $t) . '`';
                $create = (string) $db->query('SHOW CREATE TABLE ' . $q)->fetch(PDO::FETCH_NUM)[1];
                $w('DROP TABLE IF EXISTS ' . $q . ';');
                // SHOW CREATE TABLE ya escapa los saltos de línea dentro de los comentarios.
                $w(str_replace(["\r\n", "\n"], ' ', $create) . ';');
                $manifest[$t] = $this->dumpRows($db, $t, $w);
            }
            $db->exec('COMMIT');
        } catch (Throwable $e) {
            $db->exec('ROLLBACK');
            gzclose($gz);
            throw $e;
        }
        $w('SET FOREIGN_KEY_CHECKS = 1;');
        $w('SET UNIQUE_CHECKS = 1;');
        $w('-- manifiesto: ' . json_encode($manifest, JSON_UNESCAPED_UNICODE));
        $w('-- fin');
        gzclose($gz);
        return $manifest;
    }

    /**
     * Vuelca las filas de una tabla en INSERT de varias filas (una línea por sentencia).
     * Se lee por páginas: por clave primaria numérica cuando existe, si no con LIMIT/OFFSET.
     */
    private function dumpRows(PDO $db, string $table, callable $w): int
    {
        $cols = $db->prepare(
            "SELECT column_name, data_type, column_key FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND extra NOT LIKE '%GENERATED%' ORDER BY ordinal_position"
        );
        $cols->execute([$table]);
        $columns = $cols->fetchAll(PDO::FETCH_NUM);
        if ($columns === []) {
            return 0;
        }
        $binary = [];
        $names = [];
        $pk = [];
        foreach ($columns as $i => [$name, $type, $key]) {
            $binary[$i] = in_array(strtolower((string) $type), ['binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'bit'], true);
            $names[] = '`' . $name . '`';
            if ($key === 'PRI') {
                $pk[] = [$i, (string) $name, strtolower((string) $type)];
            }
        }
        $q = '`' . str_replace('`', '``', $table) . '`';
        $list = implode(', ', $names);
        $keyset = count($pk) === 1 && in_array($pk[0][2], ['int', 'bigint', 'smallint', 'mediumint', 'tinyint'], true);
        $order = $pk !== [] ? implode(', ', array_map(static fn (array $c): string => '`' . $c[1] . '`', $pk)) : $list;

        $count = 0;
        $last = null;
        $offset = 0;
        $page = 1000;
        while (true) {
            if ($keyset) {
                $col = '`' . $pk[0][1] . '`';
                $stmt = $db->prepare("SELECT {$list} FROM {$q}" . ($last !== null ? " WHERE {$col} > ?" : '') . " ORDER BY {$col} LIMIT {$page}");
                $stmt->execute($last !== null ? [$last] : []);
            } else {
                $stmt = $db->query("SELECT {$list} FROM {$q} ORDER BY {$order} LIMIT {$page} OFFSET {$offset}");
            }
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            if ($rows === []) {
                break;
            }
            foreach (array_chunk($rows, self::ROWS_PER_INSERT) as $chunk) {
                $values = [];
                foreach ($chunk as $row) {
                    $vals = [];
                    foreach ($row as $i => $v) {
                        if ($v === null) {
                            $vals[] = 'NULL';
                        } elseif ($binary[$i]) {
                            $vals[] = $v === '' ? "''" : '0x' . bin2hex((string) $v);
                        } elseif (is_int($v) || is_float($v)) {
                            $vals[] = (string) $v;
                        } else {
                            $vals[] = (string) $db->quote((string) $v);
                        }
                    }
                    $values[] = '(' . implode(',', $vals) . ')';
                }
                $w("INSERT INTO {$q} ({$list}) VALUES " . implode(',', $values) . ';');
            }
            $count += count($rows);
            if ($keyset) {
                $last = $rows[count($rows) - 1][$pk[0][0]];
            } else {
                $offset += $page;
            }
            if (count($rows) < $page) {
                break;
            }
        }
        return $count;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new RuntimeException("No se pudo crear la carpeta de copias {$this->dir}.");
        }
        $public = realpath(dirname(__DIR__, 3) . '/public');
        $real = realpath($this->dir);
        if ($public !== false && $real !== false && str_starts_with($real . '/', $public . '/')) {
            throw new RuntimeException('BACKUP_PATH no puede estar dentro de public/: las copias quedarían descargables.');
        }
        if (!is_file($this->dir . '/.htaccess')) {
            @file_put_contents($this->dir . '/.htaccess', "Require all denied\n");
        }
    }

    /** @return list<string> copias, de la más nueva a la más antigua */
    public function list(): array
    {
        $files = array_merge(glob($this->dir . '/' . self::PREFIX . '*.sql.gz') ?: [], glob($this->dir . '/' . self::PREFIX . '*.sql.gz.enc') ?: []);
        rsort($files, SORT_STRING);
        return array_values($files);
    }

    /** @return list<string> copias borradas por exceder BACKUP_KEEP */
    private function rotate(): array
    {
        $keep = max(1, (int) Config::get('backup.keep', 14));
        $removed = [];
        foreach (array_slice($this->list(), $keep) as $old) {
            @unlink($old);
            @unlink($old . '.sha256');
            $removed[] = basename($old);
        }
        return $removed;
    }

    // ================================================================== restaurar

    /**
     * Ejecuta la copia en la base $database (se crea si no existe). Con $clean borra antes todas
     * sus tablas, también las que no están en la copia. Devuelve el manifiesto.
     *
     * @return array{manifiesto: array<string, int>, sentencias: int}
     */
    public function restore(string $file, string $database, bool $clean = false): array
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $database)) {
            throw new RuntimeException('Nombre de base inválido.');
        }
        $sql = $this->plainCopy($file);
        try {
            $cfg = (array) Config::get('database');
            $server = Database::connect($cfg, false);
            $server->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
            $db = Database::connect(['database' => $database] + $cfg);
            if ($clean) {
                // Volver a una versión anterior (rollback de la Fase 15): las tablas que creó una
                // migración posterior a la copia no deben quedar sueltas.
                (new \App\Core\Migrator($db, ''))->dropAllTables();
            }
            $gz = gzopen($sql['path'], 'rb');
            if ($gz === false) {
                throw new RuntimeException('No se pudo leer la copia.');
            }
            $manifest = null;
            $count = 0;
            $buffer = '';
            $ended = false;
            while (($line = gzgets($gz)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($buffer === '' && str_starts_with($line, '-- ')) {
                    if (str_starts_with($line, '-- manifiesto: ')) {
                        $manifest = json_decode(substr($line, 15), true);
                    } elseif ($line === '-- fin') {
                        $ended = true;
                    }
                    continue;
                }
                if ($line === '') {
                    continue;
                }
                $buffer .= ($buffer === '' ? '' : "\n") . $line;
                if (str_ends_with($line, ';')) {
                    $db->exec($buffer);
                    $buffer = '';
                    $count++;
                }
            }
            gzclose($gz);
            if (!$ended || !is_array($manifest)) {
                throw new RuntimeException('La copia está incompleta (no tiene el final esperado).');
            }
            return ['manifiesto' => array_map('intval', $manifest), 'sentencias' => $count];
        } finally {
            if ($sql['temp']) {
                @unlink($sql['path']);
            }
        }
    }

    /**
     * Verifica el SHA-256 y, si está cifrada, la descifra a un temporal.
     *
     * @return array{path: string, temp: bool}
     */
    public function plainCopy(string $file): array
    {
        $file = $this->resolve($file);
        $sidecar = $file . '.sha256';
        if (is_file($sidecar)) {
            $expected = strtok((string) file_get_contents($sidecar), " \n");
            if (!hash_equals((string) $expected, (string) hash_file('sha256', $file))) {
                throw new RuntimeException('El SHA-256 de la copia no coincide: el archivo está dañado o fue modificado.');
            }
        }
        if (!BackupCipher::isEncrypted($file)) {
            return ['path' => $file, 'temp' => false];
        }
        $key = $this->key();
        if ($key === null) {
            throw new RuntimeException('La copia está cifrada: configura BACKUP_KEY con la clave con que se creó.');
        }
        $tmp = sys_get_temp_dir() . '/barberapp-restore-' . bin2hex(random_bytes(6)) . '.sql.gz';
        BackupCipher::decryptFile($file, $tmp, $key);
        @chmod($tmp, 0600);
        return ['path' => $tmp, 'temp' => true];
    }

    /**
     * Prueba de restauración: restaura en una base temporal, compara filas por tabla con el
     * manifiesto y borra la base temporal.
     *
     * @return array{archivo: string, base: string, tablas: int, filas: int, diferencias: array<string, array{esperado: int, restaurado: int}>, ok: bool, segundos: float}
     */
    public function verify(?string $file = null): array
    {
        $file ??= $this->list()[0] ?? null;
        if ($file === null) {
            throw new RuntimeException('No hay copias para verificar en ' . $this->dir . '.');
        }
        $database = (string) Config::get('backup.verify_database');
        $main = (string) Config::get('database.database');
        if ($database === '' || $database === $main) {
            throw new RuntimeException('BACKUP_VERIFY_DATABASE debe ser distinta de la base principal.');
        }
        $start = microtime(true);
        $cfg = (array) Config::get('database');
        $server = Database::connect($cfg, false);
        $server->exec("DROP DATABASE IF EXISTS `{$database}`");
        try {
            $result = $this->restore($file, $database);
            $db = Database::connect(['database' => $database] + $cfg);
            $diff = [];
            foreach ($result['manifiesto'] as $table => $expected) {
                $actual = (int) $db->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', (string) $table) . '`')->fetchColumn();
                if ($actual !== $expected) {
                    $diff[(string) $table] = ['esperado' => $expected, 'restaurado' => $actual];
                }
            }
        } finally {
            $server->exec("DROP DATABASE IF EXISTS `{$database}`");
        }
        return [
            'archivo' => $file, 'base' => $database, 'tablas' => count($result['manifiesto']), 'filas' => array_sum($result['manifiesto']),
            'diferencias' => $diff, 'ok' => $diff === [], 'segundos' => round(microtime(true) - $start, 2),
        ];
    }
}
