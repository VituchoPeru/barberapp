<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use App\Services\Notifications\NotificationSettings;

/** Límites y funciones según el plan (Fase 10). demo-norte está en prueba del plan Básico. */
final class PlanLimitsTest extends BillingTestCase
{
    private const SUCURSAL = ['nombre' => 'Sede Nueva', 'direccion' => 'Av. Prueba 123', 'distrito' => 'Comas'];

    public function testBasicPlanAllowsOneBranchAndBlocksActivatingAnother(): void
    {
        $h = $this->as(self::OWNER_NORTE, 'demo-norte');
        $res = $this->panel('POST', '/sucursales', $h, self::SUCURSAL);
        $this->assertSame(409, $res->status(), $res->body());
        $this->assertSame('plan_limit', $this->errorCode($res));
        $this->assertSame(['recurso' => 'sucursales', 'limite' => 1, 'uso' => 1, 'plan' => 'basico'], $res->decoded()['error']['details']);
        $this->assertStringContainsString('Básico permite 1 sucursal activa', (string) $res->decoded()['error']['message']);

        // Se puede preparar una sucursal inactiva, pero no activarla mientras se supere el límite.
        $inactiva = $this->ok($this->panel('POST', '/sucursales', $h, self::SUCURSAL + ['activo' => false]), 201);
        $act = $this->panel('PUT', '/sucursales/' . $inactiva['id'], $h, ['activo' => true]);
        $this->assertSame(409, $act->status(), $act->body());
        $this->assertSame('plan_limit', $this->errorCode($act));

        // Con un plan mayor, la misma activación funciona.
        $this->exec('UPDATE suscripciones SET plan_id = ? WHERE tenant_id = ?', [$this->planId('profesional'), $this->tenantId('demo-norte')]);
        \App\Services\Billing\Entitlements::flush();
        $this->ok($this->panel('PUT', '/sucursales/' . $inactiva['id'], $h, ['activo' => true]));
    }

    public function testBarberLimitCountsOnlyActiveBarbers(): void
    {
        $h = $this->as(self::OWNER_NORTE, 'demo-norte');
        $sucursal = (int) $this->scalar('SELECT id FROM sucursales WHERE tenant_id = ? LIMIT 1', [$this->tenantId('demo-norte')]);
        $ids = [];
        foreach (['Barbero Dos', 'Barbero Tres'] as $n) { // ya tiene 1; Básico permite 3
            $ids[] = $this->ok($this->panel('POST', '/barberos', $h, ['nombre_publico' => $n, 'sucursal_id' => $sucursal]), 201)['id'];
        }
        $cuarto = $this->panel('POST', '/barberos', $h, ['nombre_publico' => 'Barbero Cuatro', 'sucursal_id' => $sucursal]);
        $this->assertSame(409, $cuarto->status(), $cuarto->body());
        $this->assertSame('plan_limit', $this->errorCode($cuarto));

        $this->ok($this->panel('PUT', '/barberos/' . $ids[1], $h, ['activo' => false]));
        $this->ok($this->panel('POST', '/barberos', $h, ['nombre_publico' => 'Barbero Cuatro', 'sucursal_id' => $sucursal]), 201);
        $reactivar = $this->panel('PUT', '/barberos/' . $ids[1], $h, ['activo' => true]);
        $this->assertSame('plan_limit', $this->errorCode($reactivar));
    }

    public function testAdvancedReportsDependOnPlan(): void
    {
        $basico = $this->ok($this->panel('GET', '/reportes', $this->as(self::OWNER_NORTE, 'demo-norte')));
        $this->assertNull($basico['ocupacion']);
        $this->assertNull($basico['evolucion_mensual']);
        $this->assertSame(['reportes_avanzados' => false, 'max_dias' => 31], $basico['plan']);
        $this->assertArrayHasKey('por_dia', $basico, 'Los reportes básicos siguen disponibles');
        $this->assertNull($basico['demanda'], 'Demanda por hora: reporte avanzado (Fase 11)');
        $this->assertNull($basico['productividad']);
        $this->assertIsArray($basico['confirmacion']);
        $this->assertIsArray($basico['clientes']);
        $this->assertNotEmpty($basico['barberos'], 'El filtro por barbero también está en el plan básico');
        $csv = $this->panel('GET', '/reportes/exportar?formato=xlsx', $this->as(self::OWNER_NORTE, 'demo-norte'));
        $this->assertSame(200, $csv->status(), 'Exportar está en todos los planes');

        $largo = $this->panel('GET', '/reportes?desde=2026-01-01&hasta=2026-03-31', $this->as(self::OWNER_NORTE, 'demo-norte'));
        $this->assertSame(403, $largo->status());
        $this->assertSame('plan_feature', $this->errorCode($largo));

        $pro = $this->ok($this->panel('GET', '/reportes', $this->as(self::OWNER)));
        $this->assertIsArray($pro['ocupacion']);
        $this->assertCount(12, $pro['evolucion_mensual']);
        $this->assertIsArray($pro['demanda']);
        $this->assertIsArray($pro['productividad']);
        $this->assertTrue($pro['plan']['reportes_avanzados']);
    }

    public function testCustomColorsRequirePersonalization(): void
    {
        $h = $this->as(self::OWNER_NORTE, 'demo-norte');
        $actual = $this->ok($this->panel('GET', '/negocio', $h));
        $this->assertFalse($actual['personalizacion']);
        $res = $this->panel('PUT', '/negocio', $h, ['color_primario' => '#AA0000']);
        $this->assertSame(403, $res->status());
        $this->assertSame('plan_feature', $this->errorCode($res));
        // Guardar el nombre reenviando los mismos colores sí se permite.
        $ok = $this->ok($this->panel('PUT', '/negocio', $h, ['nombre_comercial' => 'Norte Renovada', 'color_primario' => $actual['colores']['primario']]));
        $this->assertSame('Norte Renovada', $ok['nombre_comercial']);

        $pro = $this->ok($this->panel('PUT', '/negocio', $this->as(self::OWNER), ['color_primario' => '#AA0000']));
        $this->assertSame('#AA0000', $pro['colores']['primario']);
    }

    public function testEmailChannelOnlyWhenPlanIncludesIt(): void
    {
        $norte = new NotificationSettings($this->tenantId('demo-norte'));
        $this->assertTrue($norte->channelEnabled('push'));
        $this->assertFalse($norte->channelEnabled('email'), 'Básico: push e interna');
        $this->assertTrue((new NotificationSettings($this->tenantId('demo-centro')))->channelEnabled('email'));
        $reglas = $this->ok($this->panel('GET', '/notificaciones/reglas', $this->as(self::OWNER_NORTE, 'demo-norte')));
        $this->assertSame(['push'], $reglas['canales_plan']);
        $this->assertTrue($reglas['canal_email'], 'La regla guardada no cambia; el plan decide si se envía');
    }

    public function testPanelContextShowsPlanAndLimitsToOwnerOnly(): void
    {
        $ctx = $this->ok($this->panel('GET', '/contexto', $this->as(self::OWNER)));
        $this->assertSame('profesional', $ctx['suscripcion']['plan']['codigo']);
        $this->assertSame('activa', $ctx['suscripcion']['estado']);
        $this->assertSame(['limite' => 2, 'uso' => 2, 'excedido' => false], $ctx['suscripcion']['limites']['sucursales']);
        $this->assertFalse($ctx['suscripcion']['restringida']);

        $barbero = $this->ok($this->panel('GET', '/contexto', $this->as('barbero1.centro@barberapp.test')));
        $this->assertArrayNotHasKey('limites', $barbero['suscripcion']);
        $this->assertSame(403, $this->panel('GET', '/suscripcion', $this->as('admin.centro@barberapp.test'))->status(), 'Solo el propietario ve la suscripción');
        $this->assertSame(403, $this->panel('GET', '/suscripcion', $this->as('barbero1.centro@barberapp.test'))->status());
    }

    public function testTenantWithoutAnySubscriptionUsesTrialPlanAndIsNotRestricted(): void
    {
        $e = \App\Services\Billing\Entitlements::for($this->tenantId('demo-sur'));
        $this->assertSame('sin_suscripcion', $e->estado);
        $this->assertSame('profesional', $e->plan['codigo']);
        $this->assertFalse($e->restricted());
    }
}
