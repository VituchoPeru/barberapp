<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Seeds\CatalogSeeder;

final class SeedTest extends DatabaseTestCase
{
    public function testCatalogIsCompleteAndIdempotent(): void
    {
        $before = [
            'roles' => (int) $this->scalar('SELECT COUNT(*) FROM roles'),
            'permisos' => (int) $this->scalar('SELECT COUNT(*) FROM permisos'),
            'rol_permisos' => (int) $this->scalar('SELECT COUNT(*) FROM rol_permisos'),
            'planes' => (int) $this->scalar('SELECT COUNT(*) FROM planes'),
        ];
        $this->assertSame(6, $before['roles']);
        $this->assertSame(3, $before['planes']);

        $this->db->commit(); // CatalogSeeder abre su propia transacción
        (new CatalogSeeder($this->db))->run();
        $this->db->beginTransaction();

        $this->assertSame($before['roles'], (int) $this->scalar('SELECT COUNT(*) FROM roles'));
        $this->assertSame($before['permisos'], (int) $this->scalar('SELECT COUNT(*) FROM permisos'));
        $this->assertSame($before['rol_permisos'], (int) $this->scalar('SELECT COUNT(*) FROM rol_permisos'));
    }

    public function testPlanPricesMatchBusinessProposal(): void
    {
        $prices = $this->db->query('SELECT codigo, precio_mensual FROM planes ORDER BY orden')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame(['basico' => '39.00', 'profesional' => '79.00', 'premium' => '149.00'], $prices);
    }

    public function testRolePermissionBoundaries(): void
    {
        $has = function (string $rol, string $permiso): bool {
            return (int) $this->scalar(
                'SELECT COUNT(*) FROM rol_permisos rp JOIN roles r ON r.id = rp.rol_id JOIN permisos p ON p.id = rp.permiso_id
                  WHERE r.codigo = ? AND p.codigo = ?',
                [$rol, $permiso]
            ) > 0;
        };
        $this->assertTrue($has('superadmin', 'plataforma.tenants.gestionar'));
        // El superadmin no tiene acceso por defecto a clientes ni reservas de las barberías.
        $this->assertFalse($has('superadmin', 'clientes.ver'));
        $this->assertFalse($has('superadmin', 'reservas.ver_todas'));
        $this->assertTrue($has('propietario', 'reportes.financieros'));
        $this->assertFalse($has('administrador', 'reportes.financieros'));
        $this->assertTrue($has('barbero', 'reservas.ver_propias'));
        $this->assertFalse($has('barbero', 'reservas.ver_todas'));
        $this->assertFalse($has('barbero', 'reportes.financieros'));
        $this->assertTrue($has('cliente', 'cliente.reservar'));
    }

    public function testDemoDataIsConsistent(): void
    {
        $this->assertSame(6, (int) $this->scalar("SELECT COUNT(*) FROM tenants WHERE slug LIKE 'demo-%'"));
        // Todos los usuarios demo usan el dominio reservado .test
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM usuarios WHERE email NOT LIKE '%@barberapp.test'"));
        // Contraseñas almacenadas con password_hash
        $hash = (string) $this->scalar("SELECT password_hash FROM usuarios WHERE email = 'cliente1@barberapp.test'");
        $this->assertTrue(password_verify((string) (\App\Core\Env::get('DEMO_PASSWORD') ?: 'Demo2026!'), $hash));
        // Un mismo usuario con roles distintos en barberías distintas
        $roles = $this->db->query(
            "SELECT t.slug, r.codigo FROM membresias m JOIN usuarios u ON u.id = m.usuario_id JOIN tenants t ON t.id = m.tenant_id
               JOIN roles r ON r.id = m.rol_id WHERE u.email = 'barbero2.centro@barberapp.test' ORDER BY t.slug"
        )->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame(['demo-centro' => 'barbero', 'demo-norte' => 'administrador'], $roles);
        // La suma de servicios de cada reserva coincide con su duración y precio total
        $bad = (int) $this->scalar(
            'SELECT COUNT(*) FROM reservas r JOIN (SELECT reserva_id, SUM(duracion_minutos) d, SUM(precio) p FROM reserva_servicios GROUP BY reserva_id) s
               ON s.reserva_id = r.id WHERE s.d <> r.duracion_total_minutos OR s.p <> r.precio_total'
        );
        $this->assertSame(0, $bad);
        // Las reservas demo no se solapan para un mismo barbero
        $overlaps = (int) $this->scalar(
            'SELECT COUNT(*) FROM reservas a JOIN reservas b ON a.barbero_id = b.barbero_id AND a.id < b.id
              WHERE a.ocupa_agenda = 1 AND b.ocupa_agenda = 1 AND a.inicio_utc < b.fin_utc AND b.inicio_utc < a.fin_utc'
        );
        $this->assertSame(0, $overlaps);
    }
}
