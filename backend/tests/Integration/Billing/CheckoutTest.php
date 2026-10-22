<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use App\Core\Config;
use App\Helpers\Clock;
use App\Services\Billing\Gateway\SandboxGateway;
use App\Services\Billing\PaymentProcessor;
use App\Services\Billing\Gateway\Gateways;
use App\Services\Platform\SubscriptionService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Cobro de la suscripción desde el panel con el simulador de pasarela (Fase 10).
 * demo-centro: Profesional activa S/ 79 con ~20 días restantes. demo-norte: prueba Básico.
 */
final class CheckoutTest extends BillingTestCase
{
    public function testQuotesAreComputedByTheServer(): void
    {
        $h = $this->as(self::OWNER);
        $s = $this->sub('demo-centro');
        $ren = $this->ok($this->panel('POST', '/suscripcion/cotizar', $h, ['plan_id' => $this->planId('profesional'), 'ciclo' => 'mensual', 'periodos' => 3]));
        $this->assertSame('renovacion', $ren['tipo']);
        $this->assertSame('237.00', $ren['monto'], '3 × precio pactado');
        $this->assertSame(str_replace(' ', 'T', $s['periodo_fin_en']) . 'Z', $ren['desde'], 'Se extiende desde el fin del periodo actual');

        $mej = $this->ok($this->panel('POST', '/suscripcion/cotizar', $h, ['plan_id' => $this->planId('premium'), 'ciclo' => 'mensual']));
        $this->assertSame('mejora', $mej['tipo']);
        $restante = strtotime($s['periodo_fin_en'] . ' UTC') - Clock::nowUtc()->getTimestamp();
        $this->assertEqualsWithDelta(round(70 * $restante / (30 * 86400), 2), (float) $mej['monto'], 0.02, '(149 − 79) × días restantes ÷ 30');

        $baja = $this->panel('POST', '/suscripcion/cotizar', $h, ['plan_id' => $this->planId('basico'), 'ciclo' => 'mensual']);
        $this->assertSame(409, $baja->status());
        $this->assertSame('schedule_change', $this->errorCode($baja));

        $over = $this->ok($this->panel('GET', '/suscripcion', $h));
        $porCodigo = array_column($over['planes'], null, 'codigo');
        $this->assertSame('renovacion', $porCodigo['profesional']['opciones']['mensual']['accion']);
        $this->assertSame('mejora', $porCodigo['premium']['opciones']['mensual']['accion']);
        $this->assertSame('programar', $porCodigo['basico']['opciones']['mensual']['accion']);
        $this->assertTrue($porCodigo['basico']['excede'], 'Centro tiene 2 sucursales; Básico permite 1');
        $this->assertTrue($over['pasarela']['habilitada']);
    }

    public function testRenewalPaidInSandboxExtendsThePeriodOnce(): void
    {
        $h = $this->as(self::OWNER);
        $antes = $this->sub('demo-centro');
        $r = $this->payWithSandbox($h, $this->planId('profesional'));
        $this->assertSame('aplicado', $r['resultado']);
        $this->assertSame('pagada', $r['orden']['estado']);

        $s = $this->sub('demo-centro');
        $this->assertSame((int) $antes['id'], (int) $s['id'], 'Renovar con el periodo vigente no crea otra fila');
        $esperado = SubscriptionService::addCycles(new DateTimeImmutable($antes['periodo_fin_en'], new DateTimeZone('UTC')), 'mensual', 1)->format('Y-m-d H:i:s');
        $this->assertSame($esperado, $s['periodo_fin_en']);
        $pago = $this->db->query("SELECT * FROM pagos_saas WHERE metodo = 'pasarela' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertSame('confirmado', $pago['estado']);
        $this->assertSame('79.00', $pago['monto']);
        $this->assertNotNull($pago['orden_id']);
        $this->assertSame(1, $this->notices('pago_confirmado', self::OWNER) > 0 ? 1 : 0);
        $this->assertSame(1, $this->auditCount('suscripcion.pago_en_linea', 'exito'));

        // El mismo webhook otra vez (reintento de la pasarela) no vuelve a aplicar el pago.
        $codigo = $r['orden']['codigo'];
        $wh = SandboxGateway::webhook(SandboxGateway::paymentId(['codigo' => $codigo, 'monto' => '79.00', 'moneda' => 'PEN'], true));
        $res = $this->send('POST', '/webhooks/pagos/sandbox?' . http_build_query($wh['query']), (string) json_encode($wh['body']), $wh['headers'] + ['Content-Type' => 'application/json']);
        $this->assertSame(200, $res->status(), $res->body());
        $this->assertSame('duplicado', $this->data($res)['resultado']);
        $this->assertSame($esperado, $this->sub('demo-centro')['periodo_fin_en']);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM pagos_saas WHERE metodo = 'pasarela'"));
        $this->assertSame('order_closed', $this->errorCode($this->panel('POST', "/suscripcion/ordenes/{$codigo}/simular", $h, ['aprobar' => true])));
    }

    public function testRejectedPaymentChangesNothing(): void
    {
        $h = $this->as(self::OWNER);
        $antes = $this->sub('demo-centro');
        $r = $this->payWithSandbox($h, $this->planId('profesional'), 'mensual', 1, false);
        $this->assertSame('rechazado', $r['resultado']);
        $this->assertSame('fallida', $r['orden']['estado']);
        $this->assertSame($antes['periodo_fin_en'], $this->sub('demo-centro')['periodo_fin_en']);
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM pagos_saas WHERE metodo = 'pasarela'"));
    }

    public function testUpgradeChargesProrationAndKeepsThePeriod(): void
    {
        $h = $this->as(self::OWNER);
        $antes = $this->sub('demo-centro');
        $r = $this->payWithSandbox($h, $this->planId('premium'));
        $this->assertSame('aplicado', $r['resultado']);
        $s = $this->sub('demo-centro');
        $this->assertSame('premium', $s['plan_codigo']);
        $this->assertSame('149.00', $s['precio_pactado']);
        $this->assertSame($antes['periodo_fin_en'], $s['periodo_fin_en']);
        $this->assertSame('mejora', $r['orden']['tipo']);
        // Sin límite de sucursales tras mejorar.
        $this->ok($this->panel('POST', '/sucursales', $h, ['nombre' => 'Sede Tres', 'direccion' => 'Jr. Uno 1', 'distrito' => 'Lince']), 201);
    }

    public function testTrialContractingAnnualStartsAfterTheTrial(): void
    {
        $h = $this->as(self::OWNER_NORTE, 'demo-norte');
        $antes = $this->sub('demo-norte');
        $q = $this->ok($this->panel('POST', '/suscripcion/cotizar', $h, ['plan_id' => $this->planId('profesional'), 'ciclo' => 'anual']));
        $this->assertSame(['contratacion', '790.00'], [$q['tipo'], $q['monto']]);
        $r = $this->payWithSandbox($h, $this->planId('profesional'), 'anual');
        $this->assertSame('aplicado', $r['resultado']);
        $s = $this->sub('demo-norte');
        $this->assertSame([(int) $antes['id'], 'activa', 'profesional', 'anual', '790.00'], [(int) $s['id'], $s['estado'], $s['plan_codigo'], $s['ciclo'], $s['precio_pactado']]);
        $this->assertSame(SubscriptionService::addCycles(new DateTimeImmutable($antes['periodo_fin_en'], new DateTimeZone('UTC')), 'anual', 1)->format('Y-m-d H:i:s'), $s['periodo_fin_en'],
            'Los días de prueba que quedaban no se pierden');
    }

    public function testExpiredSubscriptionContractsFromToday(): void
    {
        $tid = $this->tenantId('demo-centro');
        $this->exec("UPDATE suscripciones SET periodo_fin_en = ? WHERE tenant_id = ?", [$this->utc('-2 days'), $tid]);
        $this->exec('UPDATE tenants SET restringido_en = ? WHERE id = ?', [$this->utc('-1 hour'), $tid]);
        $h = $this->as(self::OWNER);
        $q = $this->ok($this->panel('POST', '/suscripcion/cotizar', $h, ['plan_id' => $this->planId('profesional'), 'ciclo' => 'mensual']));
        $this->assertSame('contratacion', $q['tipo']);
        $this->assertSame('79.00', $q['monto'], 'Precio de lista del plan');
        $r = $this->payWithSandbox($h, $this->planId('profesional'));
        $this->assertSame('aplicado', $r['resultado']);
        $s = $this->sub('demo-centro');
        $this->assertSame('activa', $s['estado']);
        $this->assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM suscripciones WHERE tenant_id = ?', [$tid]), 'La vencida no revive: fila nueva');
        $this->assertNull($this->scalar('SELECT restringido_en FROM tenants WHERE id = ?', [$tid]), 'Pagar levanta la restricción');
    }

    public function testMismatchedAmountGoesToManualReview(): void
    {
        $h = $this->as(self::OWNER);
        $co = $this->ok($this->panel('POST', '/suscripcion/pagar', $h, ['plan_id' => $this->planId('profesional'), 'ciclo' => 'mensual']), 201);
        $codigo = $co['orden']['codigo'];
        $antes = $this->sub('demo-centro');
        [$res] = (new PaymentProcessor())->apply(Gateways::make('sandbox'), [
            'id' => 'sbx_' . $codigo . '_a_100_PEN', 'estado' => 'aprobado', 'monto' => '1.00', 'moneda' => 'PEN', 'referencia' => $codigo, 'pagado_en' => null, 'estado_pasarela' => 'approved',
        ]);
        $this->assertSame('revision', $res);
        $this->assertSame($antes['periodo_fin_en'], $this->sub('demo-centro')['periodo_fin_en'], 'No se aplica solo');
        $this->assertSame('pendiente', $this->scalar("SELECT estado FROM pagos_saas WHERE metodo = 'pasarela' AND referencia_externa LIKE ?", ['sbx_' . $codigo . '%']));
        $orden = $this->ok($this->panel('GET', "/suscripcion/ordenes/{$codigo}", $h))['orden'];
        $this->assertTrue($orden['por_revisar']);
        $this->cookies = [];
        $lista = $this->ok($this->call('GET', '/plataforma/ordenes?por_revisar=1', null, ['X-CSRF-Token' => $this->loginWeb(self::SUPER)]));
        $this->assertSame([$codigo], array_column($lista, 'codigo'));
    }

    public function testDowngradeIsScheduledAndAppliedAtTheEndOfThePaidPeriod(): void
    {
        $tid = $this->tenantId('demo-centro');
        $h = $this->as(self::OWNER);
        $res = $this->panel('POST', '/suscripcion/cambiar-plan', $h, ['plan_id' => $this->planId('basico'), 'ciclo' => 'mensual']);
        $this->assertSame('plan_limit_exceeded', $this->errorCode($res), 'Con 2 sucursales no puede bajar a Básico');

        $this->exec("UPDATE sucursales SET activo = 0 WHERE tenant_id = ? AND es_principal = 0", [$tid]);
        $this->exec("UPDATE barberos SET activo = 1 WHERE tenant_id = ?", [$tid]);
        $r = $this->ok($this->panel('POST', '/suscripcion/cambiar-plan', $h, ['plan_id' => $this->planId('basico'), 'ciclo' => 'mensual']));
        $this->assertSame('programado', $r['accion']);
        $this->assertSame('basico', $r['suscripcion']['cambio_programado']['plan']['codigo']);
        $this->assertSame('profesional', $this->sub('demo-centro')['plan_codigo'], 'Sigue con su plan hasta el fin del periodo');

        // Paga el periodo siguiente al precio del plan programado.
        $q = $this->ok($this->panel('POST', '/suscripcion/cotizar', $h, ['plan_id' => $this->planId('basico'), 'ciclo' => 'mensual']));
        $this->assertSame(['renovacion', '39.00'], [$q['tipo'], $q['monto']]);
        $this->assertSame('aplicado', $this->payWithSandbox($h, $this->planId('basico'))['resultado']);
        $finViejo = (string) $this->scalar('SELECT cambio_programado_en FROM suscripciones WHERE tenant_id = ?', [$tid]);

        $this->freeze('+21 days'); // pasado el fin del periodo original
        $this->assertGreaterThan($finViejo, Clock::nowSql());
        (new \App\Services\Billing\Lifecycle())->run();
        $s = $this->sub('demo-centro');
        $this->assertSame(['basico', '39.00', 'activa'], [$s['plan_codigo'], $s['precio_pactado'], $s['estado']]);
        $this->assertNull($s['plan_programado_id']);
    }

    public function testScheduledChangeCanBeCancelled(): void
    {
        $tid = $this->tenantId('demo-centro');
        $this->exec("UPDATE sucursales SET activo = 0 WHERE tenant_id = ? AND es_principal = 0", [$tid]);
        $h = $this->as(self::OWNER);
        $this->ok($this->panel('POST', '/suscripcion/cambiar-plan', $h, ['plan_id' => $this->planId('profesional'), 'ciclo' => 'anual']));
        $this->assertSame('anual', $this->sub('demo-centro')['ciclo_programado']);
        $r = $this->ok($this->panel('DELETE', '/suscripcion/cambio-programado', $h));
        $this->assertNull($r['suscripcion']['cambio_programado']);
        $this->assertSame('no_scheduled_change', $this->errorCode($this->panel('DELETE', '/suscripcion/cambio-programado', $h)));
        $this->assertSame('payment_required', $this->errorCode($this->panel('POST', '/suscripcion/cambiar-plan', $h, ['plan_id' => $this->planId('premium'), 'ciclo' => 'mensual'])));
    }

    public function testOrdersAreIsolatedPerTenantAndPermissionChecked(): void
    {
        $co = $this->ok($this->panel('POST', '/suscripcion/pagar', $this->as(self::OWNER), ['plan_id' => $this->planId('profesional'), 'ciclo' => 'mensual']), 201);
        $codigo = $co['orden']['codigo'];
        $norte = $this->as(self::OWNER_NORTE, 'demo-norte');
        $this->assertSame(404, $this->panel('GET', "/suscripcion/ordenes/{$codigo}", $norte)->status());
        $this->assertSame(404, $this->panel('POST', "/suscripcion/ordenes/{$codigo}/simular", $norte, ['aprobar' => true])->status());
        $this->assertSame(403, $this->panel('POST', '/suscripcion/pagar', $this->as('admin.centro@barberapp.test'), ['plan_id' => $this->planId('premium'), 'ciclo' => 'mensual'])->status());

        // Una orden nueva anula la anterior sin pagar.
        $this->ok($this->panel('POST', '/suscripcion/pagar', $this->as(self::OWNER), ['plan_id' => $this->planId('profesional'), 'ciclo' => 'mensual']), 201);
        $this->assertSame('anulada', $this->scalar('SELECT estado FROM ordenes_pago WHERE codigo = ?', [$codigo]));
    }

    public function testPaymentsDisabledAndSandboxRefusedInProduction(): void
    {
        Config::set('billing.driver', 'none');
        $res = $this->panel('POST', '/suscripcion/pagar', $this->as(self::OWNER), ['plan_id' => $this->planId('profesional'), 'ciclo' => 'mensual']);
        $this->assertSame(503, $res->status());
        $this->assertSame('payments_disabled', $this->errorCode($res));

        Config::set('billing.driver', 'sandbox');
        Config::set('app.env', 'production');
        try {
            $this->assertSame('none', Gateways::driver(), 'El simulador nunca funciona en producción');
        } finally {
            Config::set('app.env', 'testing');
        }
    }
}
