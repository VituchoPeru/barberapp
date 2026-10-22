<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

/** Prueba gratuita automática (Fase 10). */
final class TrialTest extends BillingTestCase
{
    /** @return array<string, mixed> */
    private function registerOwner(string $email, string $nombre): array
    {
        $this->cookies = [];
        return $this->ok($this->call('POST', '/auth/register', [
            'tipo' => 'propietario', 'email' => $email, 'password' => 'Navaja2026x', 'nombres' => 'Dueña', 'acepta_terminos' => true,
            'barberia' => ['nombre_comercial' => $nombre],
        ]), 201);
    }

    public function testOwnerGetsTrialAtRegistrationWhenNoApprovalIsNeeded(): void
    {
        $this->setting('plataforma.aprobacion_manual_tenants', false);
        $data = $this->registerOwner('rapida@barberapp.test', 'Barbería Rápida');
        $this->assertSame('activo', $data['barberia']['estado']);
        $s = $this->sub($data['barberia']['slug']);
        $this->assertSame('prueba', $s['estado']);
        $this->assertSame('profesional', $s['plan_codigo']);
        $dias = (strtotime($s['periodo_fin_en']) - strtotime($s['inicio_en'])) / 86400;
        $this->assertEquals(14, $dias, 'Los días salen del plan (dias_prueba)');
        $this->assertSame('prueba_automatica', $this->scalar('SELECT tipo FROM suscripcion_eventos WHERE suscripcion_id = ?', [$s['id']]));
    }

    public function testTrialStartsWhenPlatformApprovesAndOnlyOnce(): void
    {
        $data = $this->registerOwner('espera@barberapp.test', 'Barbería en Espera');
        $tid = (int) $data['barberia']['id'];
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM suscripciones WHERE tenant_id = ?', [$tid]), 'Pendiente: la prueba aún no corre');
        $this->assertSame('tenant_pending', $this->errorCode($this->panel('POST', '/suscripcion/cotizar', ['X-CSRF-Token' => (string) $data['csrf_token'], 'X-Tenant' => $data['barberia']['slug']],
            ['plan_id' => $this->planId('basico'), 'ciclo' => 'mensual'])));

        $this->cookies = [];
        $h = ['X-CSRF-Token' => $this->loginWeb(self::SUPER)];
        $r = $this->ok($this->call('POST', "/plataforma/barberias/{$tid}/aprobar", [], $h));
        $this->assertNotNull($r['prueba_suscripcion_id']);
        $this->assertSame('prueba', $this->sub($data['barberia']['slug'])['estado']);

        // Suspender y reactivar la barbería no regala otra prueba.
        $this->ok($this->call('POST', "/plataforma/barberias/{$tid}/suspender", ['motivo' => 'Prueba'], $h));
        $this->ok($this->call('POST', "/plataforma/barberias/{$tid}/reactivar", [], $h));
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM suscripciones WHERE tenant_id = ?', [$tid]));
    }

    public function testTrialCanBeDisabledOrUseAnotherPlan(): void
    {
        $this->setting('plataforma.aprobacion_manual_tenants', false);
        $this->setting('plataforma.prueba_automatica', false);
        $a = $this->registerOwner('sinprueba@barberapp.test', 'Barbería Sin Prueba');
        $this->assertSame([], $this->sub($a['barberia']['slug']));

        $this->setting('plataforma.prueba_automatica', true);
        $this->setting('plataforma.plan_prueba', 'basico');
        $this->exec("UPDATE planes SET dias_prueba = 7 WHERE codigo = 'basico'");
        $b = $this->registerOwner('basica@barberapp.test', 'Barbería Básica');
        $s = $this->sub($b['barberia']['slug']);
        $this->assertSame('basico', $s['plan_codigo']);
        $this->assertEquals(7, (strtotime($s['periodo_fin_en']) - strtotime($s['inicio_en'])) / 86400);
    }

    public function testTrialOwnerSwitchesPlanForFreeKeepingTheTrialEnd(): void
    {
        $h = $this->as(self::OWNER_NORTE, 'demo-norte');
        $antes = $this->sub('demo-norte');
        $r = $this->ok($this->panel('POST', '/suscripcion/cambiar-plan', $h, ['plan_id' => $this->planId('premium'), 'ciclo' => 'mensual']));
        $this->assertSame('prueba', $r['accion']);
        $s = $this->sub('demo-norte');
        $this->assertSame('premium', $s['plan_codigo']);
        $this->assertSame('prueba', $s['estado']);
        $this->assertSame($antes['periodo_fin_en'], $s['periodo_fin_en']);
        $this->assertSame('premium', $r['resumen']['plan']['codigo']);
    }
}
