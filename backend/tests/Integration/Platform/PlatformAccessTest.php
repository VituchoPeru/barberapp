<?php

declare(strict_types=1);

namespace Tests\Integration\Platform;

/** Acceso al panel SaaS y privacidad de los datos de las barberías (Fase 9). */
final class PlatformAccessTest extends PlatformTestCase
{
    public function testOnlyPlatformTeamCanEnter(): void
    {
        $this->assertSame(401, $this->get('/dashboard')->status());
        foreach ([self::PROPIETARIO, 'admin.centro@barberapp.test', 'barbero1.centro@barberapp.test', self::CLIENTE] as $email) {
            $before = $this->auditCount('authz.plataforma_denegado', 'denegado');
            $h = $this->as($email);
            foreach (['/dashboard', '/barberias', '/contexto', '/usuarios/buscar?q=demo'] as $path) {
                $res = $this->get($path, $h);
                $this->assertSame(403, $res->status(), "{$email} {$path}");
            }
            $this->assertSame(403, $this->post('/barberias/' . $this->tenantId('demo-pendiente') . '/aprobar', [], $h)->status());
            $this->assertGreaterThan($before, $this->auditCount('authz.plataforma_denegado', 'denegado'), 'El intento queda auditado');
        }
        $this->assertSame('pendiente', $this->scalar("SELECT estado FROM tenants WHERE slug = 'demo-pendiente'"));
    }

    public function testSuperadminContextAndPermissions(): void
    {
        $ctx = $this->ok($this->get('/contexto', $this->as(self::SUPER)));
        $this->assertSame('superadmin', $ctx['rol']['codigo']);
        $this->assertContains('plataforma.usuarios.gestionar', $ctx['permisos']);
        $this->assertSame('America/Lima', $ctx['plataforma']['zona_horaria']);
    }

    public function testOperatorHasPartialAccess(): void
    {
        $h = $this->as(self::OPERADOR);
        $ctx = $this->ok($this->get('/contexto', $h));
        $this->assertSame('operador', $ctx['rol']['codigo']);
        foreach (['/dashboard', '/barberias', '/suscripciones', '/pagos', '/planes', '/auditoria', '/reportes', '/sistema'] as $path) {
            $this->assertSame(200, $this->get($path, $h)->status(), $path);
        }
        foreach (['/configuracion', '/usuarios', '/plantillas'] as $path) {
            $this->assertSame(403, $this->get($path, $h)->status(), $path);
        }
        $this->assertSame(403, $this->post('/planes', ['codigo' => 'nuevo', 'nombre' => 'Nuevo', 'precio_mensual' => '10.00'], $h)->status());
        $this->assertSame(403, $this->put('/planes/' . $this->planId('basico'), ['precio_mensual' => '1.00'], $h)->status());
        $this->assertSame('39.00', $this->scalar("SELECT precio_mensual FROM planes WHERE codigo = 'basico'"));
    }

    public function testPlatformViewsNeverExposeClientData(): void
    {
        $h = $this->as(self::SUPER);
        $clientes = $this->db->query(
            "SELECT DISTINCT c.email, c.telefono, c.nombres FROM clientes c WHERE c.email IS NOT NULL OR c.telefono IS NOT NULL"
        )->fetchAll();
        $this->assertNotEmpty($clientes);
        $centro = $this->tenantId('demo-centro');
        $bodies = [];
        foreach (['/dashboard', '/reportes?meses=3', '/barberias', "/barberias/{$centro}", '/suscripciones', '/pagos', '/sistema'] as $path) {
            $res = $this->get($path, $h);
            $this->assertSame(200, $res->status(), $path);
            $bodies[$path] = $res->body();
        }
        foreach ($bodies as $path => $body) {
            foreach ($clientes as $c) {
                foreach (['email', 'telefono'] as $k) {
                    if (!empty($c[$k])) {
                        $this->assertStringNotContainsString((string) $c[$k], $body, "{$path} no debe mostrar {$k} de clientes");
                    }
                }
            }
            $this->assertStringNotContainsString('"cliente":', $body, $path);
        }
        // La ficha muestra solo el número de clientes, no la lista.
        $ficha = $this->data($this->get("/barberias/{$centro}", $h));
        $this->assertSame((int) $this->scalar('SELECT COUNT(*) FROM cliente_tenants WHERE tenant_id = ?', [$centro]), $ficha['uso']['clientes']);
        // Y el superadministrador no entra al panel de una barbería de la que no es miembro.
        $this->assertSame(404, $this->call('GET', '/panel/clientes', null, $h + ['X-Tenant' => 'demo-centro'])->status());
    }
}
