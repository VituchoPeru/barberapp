<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Pruebas de integridad: las reglas críticas se cumplen en la propia base de datos,
 * aunque una capa superior tenga un error.
 */
final class ConstraintsTest extends DatabaseTestCase
{
    private const CHECK = 3819;
    private const FK = 1452;
    private const DUP = 1062;

    public function testServiceRejectsZeroDurationAndNegativePrice(): void
    {
        $t = $this->tenantId('demo-centro');
        $this->assertSqlFails(self::CHECK, "INSERT INTO servicios (tenant_id, nombre, duracion_minutos, precio) VALUES (?, 'X', 0, 10)", [$t], 'duración cero');
        $this->assertSqlFails(self::CHECK, "INSERT INTO servicios (tenant_id, nombre, duracion_minutos, precio) VALUES (?, 'X', 30, -0.01)", [$t], 'precio negativo');
        $this->exec("INSERT INTO servicios (tenant_id, nombre, duracion_minutos, precio) VALUES (?, 'Servicio 45 min', 45, 0)", [$t]);
        $this->assertSame(45, (int) $this->scalar("SELECT duracion_minutos FROM servicios WHERE nombre = 'Servicio 45 min'"));
    }

    public function testCannotAssignServiceOfAnotherTenantToBarber(): void
    {
        $centro = $this->tenantId('demo-centro');
        $norte = $this->tenantId('demo-norte');
        $barberoCentro = (int) $this->scalar('SELECT id FROM barberos WHERE tenant_id = ? LIMIT 1', [$centro]);
        $servicioNorte = (int) $this->scalar('SELECT id FROM servicios WHERE tenant_id = ? LIMIT 1', [$norte]);

        // Con tenant_id del barbero: el servicio no existe en ese tenant.
        $this->assertSqlFails(self::FK, 'INSERT INTO barbero_servicios (tenant_id, barbero_id, servicio_id) VALUES (?, ?, ?)', [$centro, $barberoCentro, $servicioNorte]);
        // Con tenant_id del servicio: el barbero no existe en ese tenant.
        $this->assertSqlFails(self::FK, 'INSERT INTO barbero_servicios (tenant_id, barbero_id, servicio_id) VALUES (?, ?, ?)', [$norte, $barberoCentro, $servicioNorte]);
    }

    public function testReservationCannotMixTenants(): void
    {
        $centro = $this->tenantId('demo-centro');
        $norte = $this->tenantId('demo-norte');
        $sucCentro = (int) $this->scalar('SELECT id FROM sucursales WHERE tenant_id = ? LIMIT 1', [$centro]);
        $barbNorte = (int) $this->scalar('SELECT id FROM barberos WHERE tenant_id = ? LIMIT 1', [$norte]);
        $barbCentro = (int) $this->scalar('SELECT id FROM barberos WHERE tenant_id = ? LIMIT 1', [$centro]);
        $cliente = (int) $this->scalar("SELECT id FROM clientes WHERE email = 'cliente1@barberapp.test'");

        $sql = "INSERT INTO reservas (tenant_id, codigo, sucursal_id, barbero_id, cliente_id, inicio_utc, fin_utc, zona_horaria, duracion_total_minutos, precio_total, canal)
                VALUES (?, ?, ?, ?, ?, '2030-01-07 15:00:00', '2030-01-07 15:30:00', 'America/Lima', 30, 25, 'app')";

        // Barbero de otra barbería
        $this->assertSqlFails(self::FK, $sql, [$centro, 'TESTCODE01', $sucCentro, $barbNorte, $cliente], 'barbero ajeno');

        // Cliente no vinculado a la barbería (cliente_tenants)
        $clienteNuevo = (int) $this->insertCliente('Cliente Sin Vínculo Demo');
        $this->assertSqlFails(self::FK, $sql, [$centro, 'TESTCODE02', $sucCentro, $barbCentro, $clienteNuevo], 'cliente sin vínculo');

        // Caso válido
        $this->exec($sql, [$centro, 'TESTCODE03', $sucCentro, $barbCentro, $cliente]);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM reservas WHERE codigo = 'TESTCODE03'"));
    }

    public function testReservationRangeAndCancellationRules(): void
    {
        $centro = $this->tenantId('demo-centro');
        $suc = (int) $this->scalar('SELECT id FROM sucursales WHERE tenant_id = ? LIMIT 1', [$centro]);
        $barb = (int) $this->scalar('SELECT id FROM barberos WHERE tenant_id = ? LIMIT 1', [$centro]);
        $cliente = (int) $this->scalar("SELECT id FROM clientes WHERE email = 'cliente1@barberapp.test'");

        $this->assertSqlFails(self::CHECK,
            "INSERT INTO reservas (tenant_id, codigo, sucursal_id, barbero_id, cliente_id, inicio_utc, fin_utc, zona_horaria, duracion_total_minutos, precio_total, canal)
             VALUES (?, 'TESTCODE04', ?, ?, ?, '2030-01-07 15:00:00', '2030-01-07 15:00:00', 'America/Lima', 30, 25, 'app')",
            [$centro, $suc, $barb, $cliente], 'fin <= inicio');
        $this->assertSqlFails(self::CHECK,
            "INSERT INTO reservas (tenant_id, codigo, sucursal_id, barbero_id, cliente_id, estado, inicio_utc, fin_utc, zona_horaria, duracion_total_minutos, precio_total, canal)
             VALUES (?, 'TESTCODE05', ?, ?, ?, 'cancelada', '2030-01-07 15:00:00', '2030-01-07 15:30:00', 'America/Lima', 30, 25, 'app')",
            [$centro, $suc, $barb, $cliente], 'cancelada sin fecha de cancelación');
    }

    public function testOccupiesAgendaGeneratedColumn(): void
    {
        $rows = $this->db->query('SELECT estado, ocupa_agenda FROM reservas')->fetchAll();
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $this->assertSame(in_array($r['estado'], ['pendiente', 'confirmada'], true) ? 1 : 0, (int) $r['ocupa_agenda'], $r['estado']);
        }
    }

    public function testOnlyOneActiveSubscriptionPerTenant(): void
    {
        $centro = $this->tenantId('demo-centro');
        $plan = (int) $this->scalar("SELECT id FROM planes WHERE codigo = 'premium'");
        $this->assertSqlFails(self::DUP,
            "INSERT INTO suscripciones (tenant_id, plan_id, estado, precio_pactado, inicio_en, periodo_fin_en) VALUES (?, ?, 'activa', 149, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL 30 DAY)",
            [$centro, $plan]);
        // Una suscripción vencida adicional sí se permite (historial).
        $this->exec(
            "INSERT INTO suscripciones (tenant_id, plan_id, estado, precio_pactado, inicio_en, periodo_fin_en) VALUES (?, ?, 'vencida', 149, '2025-01-01', '2025-02-01')",
            [$centro, $plan]
        );
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM suscripciones WHERE tenant_id = ? AND estado IN ('prueba','activa')", [$centro]));
    }

    public function testTenantSlugAndCoordinatesValidation(): void
    {
        $this->assertSqlFails(self::DUP, "INSERT INTO tenants (slug, nombre_comercial) VALUES ('demo-centro', 'Duplicada')");
        $this->assertSqlFails(self::CHECK, "INSERT INTO tenants (slug, nombre_comercial) VALUES ('Slug Inválido', 'X')");
        $this->assertSqlFails(self::CHECK, "INSERT INTO tenants (slug, nombre_comercial, ruc) VALUES ('ruc-malo', 'X', '12345678901')");

        $t = $this->tenantId('demo-norte');
        $this->assertSqlFails(self::CHECK,
            "INSERT INTO sucursales (tenant_id, nombre, slug, direccion, distrito, latitud, longitud) VALUES (?, 'S', 's-geo', 'D', 'Lima', -12.05, NULL)",
            [$t], 'coordenada incompleta');
        $this->assertSqlFails(self::CHECK,
            "INSERT INTO sucursales (tenant_id, nombre, slug, direccion, distrito, latitud, longitud) VALUES (?, 'S', 's-geo', 'D', 'Lima', 120, -77)",
            [$t], 'latitud fuera de rango');
    }

    public function testDefaultBookingPolicyIsUniquePerTenant(): void
    {
        $this->assertSqlFails(self::DUP, 'INSERT INTO politicas_reserva (tenant_id) VALUES (?)', [$this->tenantId('demo-centro')]);
    }

    public function testScheduleRangesAreValidated(): void
    {
        $t = $this->tenantId('demo-centro');
        $b = (int) $this->scalar('SELECT id FROM barberos WHERE tenant_id = ? LIMIT 1', [$t]);
        $s = (int) $this->scalar('SELECT id FROM sucursales WHERE tenant_id = ? LIMIT 1', [$t]);
        $this->assertSqlFails(self::CHECK, "INSERT INTO horarios_barberos (tenant_id, barbero_id, sucursal_id, dia_semana, hora_inicio, hora_fin) VALUES (?, ?, ?, 8, '09:00', '10:00')", [$t, $b, $s], 'día 8');
        $this->assertSqlFails(self::CHECK, "INSERT INTO horarios_barberos (tenant_id, barbero_id, sucursal_id, dia_semana, hora_inicio, hora_fin) VALUES (?, ?, ?, 1, '18:00', '09:00')", [$t, $b, $s], 'fin antes de inicio');
        $this->assertSqlFails(self::CHECK, "INSERT INTO bloqueos_agenda (tenant_id, barbero_id, tipo, inicio_utc, fin_utc) VALUES (?, ?, 'bloqueo', '2030-01-01 10:00', '2030-01-01 09:00')", [$t, $b], 'bloqueo invertido');
    }

    public function testNotificationDedupeAndIdempotencyKeys(): void
    {
        $existing = (string) $this->scalar('SELECT dedupe_key FROM notificaciones LIMIT 1');
        $this->assertSqlFails(self::DUP,
            "INSERT INTO notificaciones (canal, tipo, cuerpo, programada_para_utc, dedupe_key) VALUES ('push', 'x', 'x', UTC_TIMESTAMP(), ?)",
            [$existing]);
        $this->assertSqlFails(self::CHECK,
            "INSERT INTO notificaciones (tenant_id, reserva_id, canal, tipo, cuerpo, programada_para_utc) VALUES (NULL, 1, 'push', 'x', 'x', UTC_TIMESTAMP())",
            [], 'reserva sin tenant');

        $u = (int) $this->scalar("SELECT id FROM usuarios WHERE email = 'cliente1@barberapp.test'");
        $sql = "INSERT INTO idempotency_keys (usuario_id, clave, metodo, endpoint, request_hash, expira_en)
                VALUES (?, 'k-123', 'POST', '/api/v1/reservas', REPEAT('a', 64), UTC_TIMESTAMP() + INTERVAL 1 DAY)";
        $this->exec($sql, [$u]);
        $this->assertSqlFails(self::DUP, $sql, [$u], 'clave de idempotencia repetida');
    }

    public function testTenantWithDataCannotBeHardDeleted(): void
    {
        $this->assertSqlFails(1451, 'DELETE FROM tenants WHERE slug = ?', ['demo-centro']);
    }

    private function insertCliente(string $nombre): int
    {
        $this->exec('INSERT INTO clientes (nombres) VALUES (?)', [$nombre]);
        return (int) $this->db->lastInsertId();
    }
}
