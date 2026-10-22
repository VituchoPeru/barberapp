<?php

declare(strict_types=1);

namespace Tests\Integration\Platform;

use App\Services\Platform\SubscriptionService;
use DateTimeImmutable;
use DateTimeZone;

/** Planes, suscripciones y pagos del SaaS (Fase 9). */
final class PlatformBillingTest extends PlatformTestCase
{
    public function testPlanValidationAndCreation(): void
    {
        $h = $this->as(self::SUPER);
        $base = ['codigo' => 'empresa', 'nombre' => 'Empresa', 'precio_mensual' => '299.00'];
        $cases = [
            [['codigo' => 'basico'] + $base, 'codigo'],
            [['precio_mensual' => '-1'] + $base, 'precio_mensual'],
            [['precio_anual' => '5000.00'] + $base, 'precio_anual'],
            [['caracteristicas' => ['canales' => ['fax']]] + $base, 'caracteristicas.canales'],
            [['dias_prueba' => 120] + $base, 'dias_prueba'],
        ];
        foreach ($cases as [$body, $field]) {
            $res = $this->post('/planes', $body, $h);
            $this->assertSame(422, $res->status(), $field);
            $this->assertArrayHasKey($field, $res->decoded()['error']['details']['fields']);
        }
        $p = $this->ok($this->post('/planes', $base + ['max_sucursales' => 5, 'caracteristicas' => ['reportes_avanzados' => true, 'canales' => ['push', 'email']]], $h), 201);
        $this->assertSame('empresa', $p['codigo']);
        $this->assertSame(['push', 'interna', 'email'], $p['caracteristicas']['canales'], 'La bandeja interna siempre está incluida');
        $this->assertSame(5, $p['max_sucursales']);
        $this->assertNull($p['max_barberos'], 'Sin límite');
        $this->assertSame(1, $this->auditCount('plataforma.plan.creado', 'exito'));
    }

    public function testPlanPriceChangeKeepsAgreedPriceOfExistingSubscriptions(): void
    {
        $h = $this->as(self::SUPER);
        $pro = $this->planId('profesional');
        $r = $this->ok($this->put("/planes/{$pro}", ['precio_mensual' => '89.00'], $h));
        $this->assertSame('89.00', $r['precio_mensual']);
        $this->assertArrayHasKey('aviso', $r);
        $this->assertSame('79.00', $this->scalar('SELECT precio_pactado FROM suscripciones WHERE id = ?', [$this->subId('demo-centro')]));
        $this->assertSame(422, $this->put("/planes/{$pro}", ['codigo' => 'otro'], $h)->status(), 'El código no cambia');

        // Debe quedar al menos un plan activo.
        $this->ok($this->put('/planes/' . $this->planId('basico'), ['activo' => false], $h));
        $this->ok($this->put('/planes/' . $this->planId('premium'), ['activo' => false], $h));
        $last = $this->put("/planes/{$pro}", ['activo' => false], $h);
        $this->assertSame(409, $last->status());
        $this->assertSame('last_active_plan', $this->errorCode($last));
    }

    public function testTrialCreationAndSingleActiveSubscription(): void
    {
        $sur = $this->tenantId('demo-sur');
        $h = $this->as(self::SUPER);
        $s = $this->ok($this->post('/suscripciones', ['tenant_id' => $sur, 'plan_id' => $this->planId('basico'), 'estado' => 'prueba'], $h), 201);
        $this->assertSame('prueba', $s['estado']);
        $this->assertSame(14, $s['dias_restantes']);
        $this->assertSame('39.00', $s['precio_pactado']);
        $this->assertSame('0.00', $s['mrr'], 'Una prueba no suma MRR');
        $this->assertSame('alta', $s['eventos'][0]['tipo']);

        $dup = $this->post('/suscripciones', ['tenant_id' => $sur, 'plan_id' => $this->planId('premium'), 'estado' => 'activa'], $h);
        $this->assertSame(409, $dup->status());
        $this->assertSame('subscription_exists', $this->errorCode($dup));
        $this->assertSame(422, $this->post('/suscripciones', ['tenant_id' => 999999, 'plan_id' => $this->planId('basico'), 'estado' => 'activa'], $h)->status());
    }

    public function testConfirmedPaymentExtendsPeriodAndRejectsDuplicateReference(): void
    {
        $id = $this->subId('demo-centro');
        $fin = (string) $this->scalar('SELECT periodo_fin_en FROM suscripciones WHERE id = ?', [$id]);
        $h = $this->as(self::SUPER);
        $pago = $this->ok($this->post("/suscripciones/{$id}/pagos", ['monto' => '79.00', 'metodo' => 'transferencia', 'referencia' => 'OP-778899'], $h), 201);
        $this->assertSame('confirmado', $pago['estado']);
        $esperado = SubscriptionService::addCycles(new DateTimeImmutable($fin, new DateTimeZone('UTC')), 'mensual', 1)->format('Y-m-d H:i:s');
        $this->assertSame($esperado, $this->scalar('SELECT periodo_fin_en FROM suscripciones WHERE id = ?', [$id]));
        $this->assertSame(substr($fin, 0, 10), $pago['periodo_inicio']);
        $this->assertSame(substr($esperado, 0, 10), $pago['periodo_fin']);

        $dup = $this->post("/suscripciones/{$id}/pagos", ['monto' => '79.00', 'metodo' => 'transferencia', 'referencia' => 'OP-778899'], $h);
        $this->assertSame(409, $dup->status());
        $this->assertSame('duplicate_reference', $this->errorCode($dup));
        $this->assertSame(422, $this->post("/suscripciones/{$id}/pagos", ['monto' => '0', 'metodo' => 'yape'], $h)->status());
        $this->assertSame(422, $this->post("/suscripciones/{$id}/pagos", ['monto' => '10.00', 'metodo' => 'tarjeta'], $h)->status());
        $futuro = (new DateTimeImmutable('+3 days'))->format('Y-m-d');
        $this->assertSame(422, $this->post("/suscripciones/{$id}/pagos", ['monto' => '10.00', 'metodo' => 'yape', 'pagado_en' => $futuro], $h)->status());

        $tipos = array_column($this->data($this->get("/suscripciones/{$id}", $h))['eventos'], 'tipo');
        $this->assertSame(['pago', 'renovacion'], array_slice($tipos, 0, 2));
    }

    public function testPendingPaymentConfirmRejectAndRefund(): void
    {
        $id = $this->subId('demo-centro');
        $fin = (string) $this->scalar('SELECT periodo_fin_en FROM suscripciones WHERE id = ?', [$id]);
        $h = $this->as(self::SUPER);
        $p1 = $this->ok($this->post("/suscripciones/{$id}/pagos", ['monto' => '79.00', 'metodo' => 'yape', 'estado' => 'pendiente', 'referencia' => 'Y-1'], $h), 201);
        $this->assertSame('pendiente', $p1['estado']);
        $this->assertNull($p1['pagado_en']);
        $this->assertSame($fin, $this->scalar('SELECT periodo_fin_en FROM suscripciones WHERE id = ?', [$id]), 'Un pago pendiente no extiende nada');

        $c = $this->ok($this->post("/pagos/{$p1['id']}/confirmar", ['periodos' => 2], $h));
        $this->assertSame('confirmado', $c['estado']);
        $esperado = SubscriptionService::addCycles(new DateTimeImmutable($fin, new DateTimeZone('UTC')), 'mensual', 2)->format('Y-m-d H:i:s');
        $this->assertSame($esperado, $this->scalar('SELECT periodo_fin_en FROM suscripciones WHERE id = ?', [$id]));
        $this->assertSame(409, $this->post("/pagos/{$p1['id']}/confirmar", [], $h)->status());

        $p2 = $this->ok($this->post("/suscripciones/{$id}/pagos", ['monto' => '79.00', 'metodo' => 'plin', 'estado' => 'pendiente'], $h), 201);
        $this->assertSame('rechazado', $this->ok($this->post("/pagos/{$p2['id']}/rechazar", ['motivo' => 'La operación no existe'], $h))['estado']);
        $this->assertSame(409, $this->post("/pagos/{$p2['id']}/reembolsar", ['motivo' => 'No corresponde'], $h)->status());

        $ref = $this->ok($this->post("/pagos/{$p1['id']}/reembolsar", ['motivo' => 'Cobro duplicado por error'], $h));
        $this->assertSame('reembolsado', $ref['estado']);
        $this->assertSame($esperado, $this->scalar('SELECT periodo_fin_en FROM suscripciones WHERE id = ?', [$id]), 'El reembolso no acorta el periodo');

        $list = $this->get('/pagos?estado=reembolsado', $h);
        $this->assertSame([$p1['id']], array_column($this->data($list), 'id'));
        $this->assertSame('reembolsado', $list->decoded()['meta']['totales'][0]['estado']);
    }

    public function testChangePlanSuspendAndReactivateKeepHistory(): void
    {
        $id = $this->subId('demo-centro');
        $fin = (string) $this->scalar('SELECT periodo_fin_en FROM suscripciones WHERE id = ?', [$id]);
        $h = $this->as(self::SUPER);
        $s = $this->ok($this->post("/suscripciones/{$id}/cambiar-plan", ['plan_id' => $this->planId('premium')], $h));
        $this->assertSame('premium', $s['plan']['codigo']);
        $this->assertSame('149.00', $s['precio_pactado']);
        $this->assertSame('149.00', $s['mrr']);
        $this->assertSame(409, $this->post("/suscripciones/{$id}/cambiar-plan", ['plan_id' => $this->planId('premium')], $h)->status());

        $anual = $this->ok($this->post("/suscripciones/{$id}/cambiar-plan", ['plan_id' => $this->planId('premium'), 'ciclo' => 'anual'], $h));
        $this->assertSame('1490.00', $anual['precio_pactado']);
        $this->assertSame('124.17', $anual['mrr'], 'Anual ÷ 12');

        $s = $this->ok($this->post("/suscripciones/{$id}/suspender", ['motivo' => 'Pago observado por el banco'], $h));
        $this->assertSame('suspendida', $s['estado']);
        $this->assertNotNull($s['terminada_en']);
        $this->assertSame(409, $this->post("/suscripciones/{$id}/suspender", ['motivo' => 'Otra vez'], $h)->status());

        $n = $this->ok($this->post("/suscripciones/{$id}/reactivar", [], $h));
        $this->assertNotSame($id, $n['id'], 'Reactivar crea un nuevo periodo vigente');
        $this->assertSame('activa', $n['estado']);
        $this->assertSame(str_replace(' ', 'T', $fin) . 'Z', $n['periodo_fin_en'], 'Con el tiempo que le quedaba');
        $this->assertSame('suspendida', $this->scalar('SELECT estado FROM suscripciones WHERE id = ?', [$id]), 'La anterior queda en el historial');
        $this->assertSame('reactivacion', $n['eventos'][0]['tipo']);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM suscripciones WHERE tenant_id = ? AND estado IN ('prueba','activa')", [$this->tenantId('demo-centro')]));

        $c = $this->ok($this->post("/suscripciones/{$n['id']}/cancelar", ['motivo' => 'La barbería cerró el local'], $h));
        $this->assertSame('cancelada', $c['estado']);
        $this->assertNotNull($c['cancelada_en']);
        $this->assertSame(409, $this->post("/suscripciones/{$n['id']}/pagos", ['monto' => '10.00', 'metodo' => 'yape'], $h)->status());
    }

    public function testExpirationAndRenewalAfterExpiry(): void
    {
        $tid = $this->realTenant('barberia-vence');
        $sid = $this->subscription($tid, 'basico', 'activa', '39.00', '-40 days', '-10 days');
        $h = $this->as(self::SUPER);

        // Antes del cron ya se muestra como vencida.
        $this->assertSame('vencida', $this->data($this->get("/suscripciones/{$sid}", $h))['estado_efectivo']);
        $this->assertContains($sid, array_column($this->data($this->get('/suscripciones?estado=vencida', $h)), 'id'));

        $n = (new SubscriptionService())->expireDue();
        $this->assertGreaterThanOrEqual(1, $n);
        $this->assertSame('vencida', $this->scalar('SELECT estado FROM suscripciones WHERE id = ?', [$sid]));
        $this->assertSame($this->scalar('SELECT periodo_fin_en FROM suscripciones WHERE id = ?', [$sid]), $this->scalar('SELECT terminada_en FROM suscripciones WHERE id = ?', [$sid]));
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM suscripcion_eventos WHERE suscripcion_id = ? AND tipo = 'vencimiento' AND usuario_id IS NULL", [$sid]));

        // Un pago confirmado sobre una vencida abre un periodo nuevo desde hoy.
        $pago = $this->ok($this->post("/suscripciones/{$sid}/pagos", ['monto' => '39.00', 'metodo' => 'efectivo'], $h), 201);
        $this->assertNotSame($sid, $pago['suscripcion_id']);
        $nueva = $this->data($this->get("/suscripciones/{$pago['suscripcion_id']}", $h));
        $this->assertSame('activa', $nueva['estado']);
        $this->assertSame(gmdate('Y-m-d'), substr($nueva['inicio_en'], 0, 10));
        $this->assertSame('vencida', $this->scalar('SELECT estado FROM suscripciones WHERE id = ?', [$sid]));
    }

    public function testAddCyclesNeverOverflowsMonth(): void
    {
        $utc = new DateTimeZone('UTC');
        $this->assertSame('2027-02-28 10:00:00', SubscriptionService::addCycles(new DateTimeImmutable('2027-01-31 10:00:00', $utc), 'mensual', 1)->format('Y-m-d H:i:s'));
        $this->assertSame('2028-02-29 10:00:00', SubscriptionService::addCycles(new DateTimeImmutable('2028-01-31 10:00:00', $utc), 'mensual', 1)->format('Y-m-d H:i:s'));
        $this->assertSame('2027-12-15 00:00:00', SubscriptionService::addCycles(new DateTimeImmutable('2027-03-15 00:00:00', $utc), 'mensual', 9)->format('Y-m-d H:i:s'));
        $this->assertSame('2029-02-28 00:00:00', SubscriptionService::addCycles(new DateTimeImmutable('2028-02-29 00:00:00', $utc), 'anual', 1)->format('Y-m-d H:i:s'));
    }
}
