<?php

declare(strict_types=1);

namespace Tests\Integration\Platform;

/** Aprobar, rechazar, suspender y reactivar barberías (Fase 9). */
final class PlatformTenantsTest extends PlatformTestCase
{
    public function testApprovePendingTenantNotifiesOwner(): void
    {
        $id = $this->tenantId('demo-pendiente');
        $owner = $this->userId('propietario.pendiente@barberapp.test');
        $h = $this->as(self::SUPER);

        $list = $this->get('/barberias?estado=pendiente', $h);
        $this->assertSame(['demo-pendiente'], array_column($this->data($list), 'slug'));
        $this->assertSame(1, $list->decoded()['meta']['resumen']['pendiente']);

        $r = $this->ok($this->post("/barberias/{$id}/aprobar", [], $h));
        $this->assertSame('activo', $r['barberia']['estado']);
        $this->assertNotNull($r['barberia']['aprobado_en']);
        $this->assertSame('activo', $this->scalar('SELECT estado FROM tenants WHERE id = ?', [$id]));
        $this->assertSame(1, $this->auditCount('plataforma.barberia.aprobada', 'exito'));

        $avisos = $this->db->query("SELECT canal, estado, titulo FROM notificaciones WHERE tipo = 'barberia_aprobada' AND usuario_id = {$owner} ORDER BY canal")->fetchAll();
        $this->assertSame(['email', 'interna'], array_column($avisos, 'canal'));
        $this->assertSame('Tu barbería fue aprobada', $avisos[1]['titulo']);
        // El propietario lo ve en su bandeja.
        $inbox = $this->data($this->call('GET', '/me/notificaciones', null, $this->as('propietario.pendiente@barberapp.test')));
        $this->assertContains('Tu barbería fue aprobada', array_column($inbox, 'titulo'));

        $again = $this->post("/barberias/{$id}/aprobar", [], $this->as(self::SUPER));
        $this->assertSame(409, $again->status());
        $this->assertSame('invalid_tenant_state', $this->errorCode($again));
    }

    public function testRejectRequiresReasonAndCancelsTenant(): void
    {
        $id = $this->tenantId('demo-pendiente');
        $h = $this->as(self::SUPER);
        $this->assertSame(422, $this->post("/barberias/{$id}/rechazar", [], $h)->status());
        $this->assertSame(422, $this->post("/barberias/{$id}/rechazar", ['motivo' => 'no'], $h)->status());

        $r = $this->ok($this->post("/barberias/{$id}/rechazar", ['motivo' => 'Los datos del RUC no coinciden'], $h));
        $this->assertSame('cancelado', $r['barberia']['estado']);
        $this->assertSame('Los datos del RUC no coinciden', $r['barberia']['motivo']);
        $cuerpo = (string) $this->scalar("SELECT cuerpo FROM notificaciones WHERE tipo = 'barberia_rechazada' AND canal = 'interna'");
        $this->assertStringContainsString('Los datos del RUC no coinciden', $cuerpo);
        // Una barbería rechazada no recibe suscripciones.
        $sub = $this->post('/suscripciones', ['tenant_id' => $id, 'plan_id' => $this->planId('basico'), 'estado' => 'prueba'], $h);
        $this->assertSame('tenant_cancelled', $this->errorCode($sub));
    }

    public function testSuspendHidesTenantAndBlocksBookingsUntilReactivated(): void
    {
        $id = $this->tenantId('demo-centro');
        $this->assertSame(200, $this->call('GET', '/marketplace/barberias/demo-centro')->status());
        $futuras = (int) $this->scalar("SELECT COUNT(*) FROM reservas WHERE tenant_id = ? AND estado IN ('pendiente','confirmada') AND inicio_utc >= UTC_TIMESTAMP()", [$id]);
        $h = $this->as(self::SUPER);

        $this->assertSame(409, $this->post("/barberias/{$id}/reactivar", [], $h)->status(), 'Solo se reactiva una suspendida');
        $r = $this->ok($this->post("/barberias/{$id}/suspender", ['motivo' => 'Incumplimiento de los términos de uso'], $h));
        $this->assertSame('suspendido', $r['barberia']['estado']);
        $this->assertSame($futuras, $r['reservas_futuras'], 'Informa cuántas citas futuras quedan (no se cancelan solas)');
        $this->assertSame($futuras, (int) $this->scalar("SELECT COUNT(*) FROM reservas WHERE tenant_id = ? AND estado IN ('pendiente','confirmada') AND inicio_utc >= UTC_TIMESTAMP()", [$id]));

        $this->assertSame(404, $this->call('GET', '/marketplace/barberias/demo-centro')->status(), 'Sale del marketplace');
        $this->assertNotContains('demo-centro', array_column($this->data($this->call('GET', '/marketplace/barberias')), 'slug'));
        $disp = $this->call('GET', '/disponibilidad?' . http_build_query(['slug' => 'demo-centro',
            'sucursal_id' => (int) $this->scalar('SELECT id FROM sucursales WHERE tenant_id = ? ORDER BY id LIMIT 1', [$id]),
            'servicio_ids' => (string) $this->scalar('SELECT id FROM servicios WHERE tenant_id = ? AND activo = 1 ORDER BY id LIMIT 1', [$id]), 'desde' => gmdate('Y-m-d', time() + 86400 * 3), 'hasta' => gmdate('Y-m-d', time() + 86400 * 3)]));
        $this->assertContains($disp->status(), [404, 409], 'Sin horarios para reservar mientras está suspendida: ' . $disp->body());

        // El propietario sigue entrando a su panel y ve el motivo.
        $ctx = $this->data($this->call('GET', '/panel/contexto', null, $this->as(self::PROPIETARIO) + ['X-Tenant' => 'demo-centro']));
        $this->assertSame('suspendido', $ctx['aviso_estado']['estado']);
        $this->assertSame('Incumplimiento de los términos de uso', $ctx['aviso_estado']['motivo']);

        $r = $this->ok($this->post("/barberias/{$id}/reactivar", [], $this->as(self::SUPER)));
        $this->assertSame('activo', $r['barberia']['estado']);
        $this->assertNull($r['barberia']['motivo']);
        $this->assertSame(200, $this->call('GET', '/marketplace/barberias/demo-centro')->status());
        $ctx = $this->data($this->call('GET', '/panel/contexto', null, $this->as(self::PROPIETARIO) + ['X-Tenant' => 'demo-centro']));
        $this->assertNull($ctx['aviso_estado']);
        $this->assertSame(1, $this->auditCount('plataforma.barberia.suspendida', 'exito'));
        $this->assertSame(1, $this->auditCount('plataforma.barberia.reactivada', 'exito'));
    }

    public function testNewOwnerRegistrationAppearsAsPendingAndCanBeFiltered(): void
    {
        $reg = $this->call('POST', '/auth/register', [
            'tipo' => 'propietario', 'email' => 'nueva.duena@barberapp.test', 'password' => 'Navaja2026x', 'nombres' => 'Nueva', 'apellidos' => 'Dueña',
            'acepta_terminos' => true, 'barberia' => ['nombre_comercial' => 'Barbería Real Uno'],
        ]);
        $this->assertSame(201, $reg->status(), $reg->body());
        $h = $this->as(self::SUPER);
        $items = $this->data($this->get('/barberias?q=Real%20Uno', $h));
        $this->assertCount(1, $items);
        $this->assertSame('pendiente', $items[0]['estado']);
        $this->assertFalse($items[0]['es_demo']);
        $this->assertSame('nueva.duena@barberapp.test', $items[0]['propietario']['email']);
        $this->assertNull($items[0]['suscripcion']);

        // Filtro por demo y búsqueda por correo del propietario.
        $this->assertSame(['barberia-real-uno'], array_column($this->data($this->get('/barberias?demo=0', $h)), 'slug'));
        $this->assertSame(['barberia-real-uno'], array_column($this->data($this->get('/barberias?q=nueva.duena', $h)), 'slug'));
        $this->assertSame(6, count($this->data($this->get('/barberias?demo=1', $h))));
    }

    public function testInternalNoteAndDemoFlagAreAudited(): void
    {
        $id = $this->tenantId('demo-sur');
        $h = $this->as(self::SUPER);
        $r = $this->ok($this->put("/barberias/{$id}", ['es_demo' => false, 'nota_interna' => 'Cliente piloto: llamar antes de suspender'], $h));
        $this->assertFalse($r['es_demo']);
        $this->assertSame('Cliente piloto: llamar antes de suspender', $r['nota_interna']);
        $this->assertSame(1, $this->auditCount('plataforma.barberia.actualizada', 'exito'));
        // La nota no se ve en el panel de la barbería ni en el marketplace.
        $this->assertStringNotContainsString('Cliente piloto', $this->call('GET', '/marketplace/barberias/demo-sur')->body());
        $this->assertSame(404, $this->get('/barberias/999999', $h)->status());
    }
}
