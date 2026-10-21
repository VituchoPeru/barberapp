<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use PDO;

final class SchemaIntegrityTest extends DatabaseTestCase
{
    private const REQUIRED_TABLES = [
        // Núcleo SaaS
        'tenants', 'sucursales', 'usuarios', 'membresias', 'roles', 'permisos', 'planes', 'suscripciones', 'pagos_saas',
        // Gestión de barberías
        'barberos', 'servicios', 'barbero_servicios', 'horarios_barberos', 'bloqueos_agenda', 'politicas_reserva',
        // Clientes
        'clientes', 'cliente_tenants', 'favoritos',
        // Reservas
        'reservas', 'reserva_servicios', 'reserva_eventos',
        // Notificaciones
        'dispositivos_push', 'preferencias_notificacion', 'notificaciones', 'plantillas_notificacion',
        // Sistema
        'auditoria', 'configuraciones', 'webhook_eventos', 'idempotency_keys',
        // Autenticación (Fase 2)
        'sesiones', 'tokens_refresco', 'tokens_usuario', 'limites_intentos',
    ];

    /** @return list<string> */
    private function tables(): array
    {
        return $this->db->query(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testAllRequiredTablesExist(): void
    {
        $missing = array_diff(self::REQUIRED_TABLES, $this->tables());
        $this->assertSame([], array_values($missing), 'Tablas faltantes');
    }

    public function testAllTablesAreInnoDbUtf8mb4(): void
    {
        $rows = $this->db->query(
            "SELECT table_name, engine, table_collation FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
        )->fetchAll();
        foreach ($rows as $row) {
            $this->assertSame('InnoDB', $row['ENGINE'] ?? $row['engine'], $row['TABLE_NAME'] ?? $row['table_name']);
            $this->assertSame('utf8mb4_0900_ai_ci', $row['TABLE_COLLATION'] ?? $row['table_collation']);
        }
    }

    public function testMoneyColumnsAreDecimal(): void
    {
        $rows = $this->db->query(
            "SELECT table_name AS t, column_name AS c, data_type AS d FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND (column_name LIKE 'precio%' OR column_name = 'monto')"
        )->fetchAll();
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('decimal', $row['d'], "{$row['t']}.{$row['c']} debe ser DECIMAL");
        }
    }

    public function testEveryTenantTableIsIndexedByTenantFirst(): void
    {
        $tenantTables = $this->db->query(
            "SELECT DISTINCT table_name FROM information_schema.columns
              WHERE table_schema = DATABASE() AND column_name = 'tenant_id'"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tenantTables as $table) {
            $count = (int) $this->scalar(
                "SELECT COUNT(*) FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'tenant_id' AND seq_in_index = 1",
                [$table]
            );
            $this->assertGreaterThan(0, $count, "{$table} no tiene índice que empiece por tenant_id");
        }
    }

    public function testBusinessTablesReferenceParentsWithCompositeTenantKeys(): void
    {
        // Relaciones hijo → padre que deben incluir tenant_id para impedir mezclas entre barberías.
        $expected = [
            ['barbero_servicios', 'barberos'], ['barbero_servicios', 'servicios'],
            ['horarios_barberos', 'barberos'], ['reservas', 'barberos'], ['reservas', 'sucursales'],
            ['reservas', 'cliente_tenants'], ['reserva_servicios', 'reservas'], ['reserva_servicios', 'servicios'],
            ['reserva_eventos', 'reservas'], ['bloqueos_agenda', 'barberos'], ['pagos_saas', 'suscripciones'],
            ['servicios', 'categorias_servicio'], ['barberos', 'sucursales'],
        ];
        foreach ($expected as [$child, $parent]) {
            $cols = $this->db->prepare(
                "SELECT GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position)
                   FROM information_schema.key_column_usage k
                  WHERE k.table_schema = DATABASE() AND k.table_name = ? AND k.referenced_table_name = ?
                  GROUP BY k.constraint_name"
            );
            $cols->execute([$child, $parent]);
            $fks = $cols->fetchAll(PDO::FETCH_COLUMN);
            $this->assertNotEmpty($fks, "{$child} → {$parent} sin FK");
            $this->assertStringStartsWith('tenant_id,', $fks[0], "{$child} → {$parent} debe ser FK compuesta (tenant_id, …)");
        }
    }

    public function testSchemaSqlIsInSyncWithMigrations(): void
    {
        $generated = Migrator::buildSchema(BARBERAPP_BASE . '/database/migrations');
        $committed = (string) file_get_contents(BARBERAPP_BASE . '/database/schema.sql');
        $this->assertSame($generated, $committed, 'database/schema.sql desactualizado: ejecute php bin/console schema:build');
    }

    public function testSchemaSqlInstallsSameStructureAsMigrations(): void
    {
        // DDL hace commit implícito: se usa una conexión aparte a otra base de datos.
        $this->db->rollBack();
        $name = BARBERAPP_TEST_DB . '_schema';
        $server = Database::connect(Config::get('database'), false);
        $server->exec("DROP DATABASE IF EXISTS `{$name}`");
        try {
            $sql = str_replace('`barberapp`', "`{$name}`", (string) file_get_contents(BARBERAPP_BASE . '/database/schema.sql'));
            foreach (Migrator::splitStatements($sql) as $statement) {
                $server->exec($statement);
            }
            $columns = static function (PDO $pdo, string $schema): array {
                $st = $pdo->prepare(
                    'SELECT CONCAT(table_name, ".", column_name, ":", column_type, ":", is_nullable)
                       FROM information_schema.columns WHERE table_schema = ? ORDER BY 1'
                );
                $st->execute([$schema]);
                return $st->fetchAll(PDO::FETCH_COLUMN);
            };
            $this->assertSame($columns($server, BARBERAPP_TEST_DB), $columns($server, $name));
            $expected = count(glob(BARBERAPP_BASE . '/database/migrations/*.sql') ?: []);
            $this->assertSame($expected, (int) $server->query("SELECT COUNT(*) FROM `{$name}`.migraciones")->fetchColumn());
        } finally {
            $server->exec("DROP DATABASE IF EXISTS `{$name}`");
            $this->db->beginTransaction();
        }
    }
}
