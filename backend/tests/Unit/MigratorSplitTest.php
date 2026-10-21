<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorSplitTest extends TestCase
{
    public function testSplitsStatementsAndSkipsComments(): void
    {
        $sql = "-- encabezado\nCREATE TABLE a (\n  id INT -- columna\n);\n\n-- otro\nCREATE TABLE b (id INT);\n";
        $parts = Migrator::splitStatements($sql);
        $this->assertCount(2, $parts);
        $this->assertStringStartsWith('CREATE TABLE a', $parts[0]);
        $this->assertSame('CREATE TABLE b (id INT)', $parts[1]);
    }

    public function testEveryMigrationFileProducesStatements(): void
    {
        $files = glob(BARBERAPP_BASE . '/database/migrations/*.sql') ?: [];
        $this->assertGreaterThanOrEqual(6, count($files));
        foreach ($files as $file) {
            $this->assertMatchesRegularExpression('/^\d{4}_[a-z0-9_]+\.sql$/', basename($file));
            $this->assertNotEmpty(Migrator::splitStatements((string) file_get_contents($file)), basename($file));
        }
    }
}
