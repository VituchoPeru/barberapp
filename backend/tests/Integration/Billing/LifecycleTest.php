<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use App\Services\Billing\Lifecycle;

/** Cron de suscripciones (Fase 10): avisos, vencimiento, restricción tras la gracia y órdenes. */
final class LifecycleTest extends BillingTestCase
{
    private function cron(): array
    {
        return (new Lifecycle())->run();
    }

    public function testExpiryNoticesAreSentOncePerThreshold(): void
    {
        $tid = $this->tenantId('demo-centro');
        $this->exec('UPDATE suscripciones SET periodo_fin_en = ? WHERE tenant_id = ?', [$this->utc('+2 days +1 hour'), $tid]);
        $this->cron();
        $this->assertSame(2, $this->notices('suscripcion_por_vencer', self::OWNER), 'Bandeja y correo');
        $cuerpo = (string) $this->scalar("SELECT cuerpo FROM notificaciones WHERE tipo = 'suscripcion_por_vencer' AND canal = 'interna'");
        $this->assertStringContainsString('Profesional', $cuerpo);
        $this->assertStringContainsString('demo', strtolower((string) $this->scalar("SELECT titulo FROM notificaciones WHERE tipo = 'suscripcion_por_vencer' AND canal = 'email'")) . 'demo');
        $this->cron();
        $this->assertSame(2, $this->notices('suscripcion_por_vencer', self::OWNER), 'El mismo umbral no se repite');

        $this->freeze('+1 day +2 hours'); // queda menos de 1 día: siguiente umbral
        $this->cron();
        $this->assertSame(4, $this->notices('suscripcion_por_vencer', self::OWNER));

        // Prueba de demo-norte (termina en ~12 días): aún sin aviso con umbrales 7/3/1.
        $this->assertSame(0, $this->notices('prueba_por_vencer'));
    }

    public function testTrialEndingUsesItsOwnTemplate(): void
    {
        $this->exec('UPDATE suscripciones SET periodo_fin_en = ? WHERE tenant_id = ?', [$this->utc('+6 days'), $this->tenantId('demo-norte')]);
        $this->cron();
        $this->assertSame(2, $this->notices('prueba_por_vencer', self::OWNER_NORTE));
        $this->assertStringContainsString('prueba gratuita', (string) $this->scalar("SELECT cuerpo FROM notificaciones WHERE tipo = 'prueba_por_vencer' AND canal = 'interna'"));
    }

    public function testExpiredTenantIsRestrictedAfterGraceAndPaymentLiftsIt(): void
    {
        $tid = $this->tenantId('demo-centro');
        $this->exec('UPDATE suscripciones SET periodo_fin_en = ? WHERE tenant_id = ?', [$this->utc('-1 hour'), $tid]);
        $r = $this->cron();
        $this->assertSame(1, $r['vencidas']);
        $this->assertSame(0, $r['restringidas'], 'Dentro de la gracia (3 días)');
        $this->assertSame(2, $this->notices('suscripcion_vencida', self::OWNER));
        $this->assertSame('vencida', $this->sub('demo-centro')['estado']);

        $this->freeze('+3 days +1 hour');
        $r = $this->cron();
        $this->assertSame(1, $r['restringidas']);
        $this->assertNotNull($this->scalar('SELECT restringido_en FROM tenants WHERE id = ?', [$tid]));
        $this->assertSame(2, $this->notices('suscripcion_restringida', self::OWNER));
        $this->assertSame(0, $this->cron()['restringidas'], 'Idempotente');

        // Restringida: fuera del marketplace, sin reservas nuevas ni altas; el panel sigue funcionando.
        $this->cookies = [];
        $list = $this->data($this->call('GET', '/marketplace/barberias'));
        $this->assertNotContains('demo-centro', array_column($list, 'slug'));
        $this->assertSame(404, $this->call('GET', '/marketplace/barberias/demo-centro')->status());
        $h = $this->as(self::OWNER);
        $alta = $this->panel('POST', '/barberos', $h, ['nombre_publico' => 'Nuevo', 'sucursal_id' => (int) $this->scalar('SELECT id FROM sucursales WHERE tenant_id = ? LIMIT 1', [$tid])]);
        $this->assertSame(402, $alta->status(), $alta->body());
        $this->assertSame('subscription_required', $this->errorCode($alta));
        $manual = $this->call('POST', '/panel/reservas', ['sucursal_id' => 1, 'fecha' => '2030-01-01', 'hora' => '10:00', 'servicio_ids' => [1], 'cliente' => ['nombres' => 'Cliente Mostrador']], $h + ['Idempotency-Key' => 'restringida-001']);
        $this->assertSame('subscription_required', $this->errorCode($manual), $manual->body());
        $this->assertSame(200, $this->panel('GET', '/agenda', $h)->status());
        $ctx = $this->ok($this->panel('GET', '/contexto', $h));
        $this->assertTrue($ctx['suscripcion']['restringida']);

        $this->assertSame('aplicado', $this->payWithSandbox($h, $this->planId('profesional'))['resultado']);
        $this->assertNull($this->scalar('SELECT restringido_en FROM tenants WHERE id = ?', [$tid]));
        $this->cookies = [];
        $this->assertSame(200, $this->call('GET', '/marketplace/barberias/demo-centro')->status());
    }

    public function testRestrictionCanBeTurnedOffAndTenantsWithoutHistoryAreNeverRestricted(): void
    {
        $tid = $this->tenantId('demo-centro');
        $this->exec('UPDATE suscripciones SET periodo_fin_en = ? WHERE tenant_id = ?', [$this->utc('-1 hour'), $tid]);
        $this->freeze('+10 days');
        $this->cron();
        $this->assertNotNull($this->scalar('SELECT restringido_en FROM tenants WHERE id = ?', [$tid]));
        $this->assertNull($this->scalar("SELECT restringido_en FROM tenants WHERE slug = 'demo-sur'"), 'demo-sur nunca tuvo suscripción');

        $this->setting('plataforma.restringir_sin_suscripcion', false);
        $this->assertSame(1, $this->cron()['liberadas']);
        $this->assertNull($this->scalar('SELECT restringido_en FROM tenants WHERE id = ?', [$tid]));
    }

    public function testOpenOrdersExpire(): void
    {
        $co = $this->ok($this->panel('POST', '/suscripcion/pagar', $this->as(self::OWNER), ['plan_id' => $this->planId('profesional'), 'ciclo' => 'mensual']), 201);
        $this->freeze('+25 hours');
        $this->assertSame(1, $this->cron()['ordenes_expiradas']);
        $this->assertSame('expirada', $this->scalar('SELECT estado FROM ordenes_pago WHERE codigo = ?', [$co['orden']['codigo']]));
    }

    public function testPlatformSettingsValidateBillingKeys(): void
    {
        $this->cookies = [];
        $h = ['X-CSRF-Token' => $this->loginWeb(self::SUPER)];
        $bad = $this->call('PUT', '/plataforma/configuracion', ['parametros' => [
            'plataforma.dias_gracia' => 45, 'plataforma.plan_prueba' => 'inexistente', 'plataforma.avisos_vencimiento' => [7, 0],
        ]], $h);
        $this->assertSame(422, $bad->status());
        $this->assertEqualsCanonicalizing(['plataforma.dias_gracia', 'plataforma.plan_prueba', 'plataforma.avisos_vencimiento'], array_keys($bad->decoded()['error']['details']['fields']));

        $ok = $this->ok($this->call('PUT', '/plataforma/configuracion', ['parametros' => [
            'plataforma.dias_gracia' => 5, 'plataforma.plan_prueba' => 'premium', 'plataforma.avisos_vencimiento' => [1, 10, 3],
        ]], $h));
        $valores = array_column($ok['parametros'], 'valor', 'clave');
        $this->assertSame([5, 'premium', [10, 3, 1]], [$valores['plataforma.dias_gracia'], $valores['plataforma.plan_prueba'], $valores['plataforma.avisos_vencimiento']]);
        $this->assertSame('sandbox', $ok['pasarela']['nombre']);
    }
}
