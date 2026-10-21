<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected PDO $db;

    protected function setUp(): void
    {
        if (!BARBERAPP_DB_AVAILABLE) {
            $this->markTestSkipped('MySQL no disponible.');
        }
        $this->db = Database::connection();
        // Cada prueba corre en una transacción que se revierte: la BD demo queda intacta.
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    /** @param list<mixed> $params */
    protected function scalar(string $sql, array $params = [])
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** @param list<mixed> $params */
    protected function exec(string $sql, array $params = []): void
    {
        $this->db->prepare($sql)->execute($params);
    }

    protected function tenantId(string $slug): int
    {
        return (int) $this->scalar('SELECT id FROM tenants WHERE slug = ?', [$slug]);
    }

    /**
     * Ejecuta SQL que DEBE fallar con el código de error MySQL indicado.
     * 3819 = CHECK violado, 1452 = FK inexistente, 1062 = duplicado, 1451 = FK en uso.
     *
     * @param list<mixed> $params
     */
    protected function assertSqlFails(int $mysqlCode, string $sql, array $params = [], string $message = ''): void
    {
        $this->db->exec('SAVEPOINT sp_expect_fail');
        try {
            $this->exec($sql, $params);
            $this->fail($message !== '' ? $message : "Se esperaba error MySQL {$mysqlCode}.");
        } catch (PDOException $e) {
            $this->db->exec('ROLLBACK TO SAVEPOINT sp_expect_fail');
            $this->assertSame($mysqlCode, (int) ($e->errorInfo[1] ?? 0), $message . ' → ' . $e->getMessage());
        }
    }
}
