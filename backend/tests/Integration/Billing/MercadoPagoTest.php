<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use App\Core\Response;
use App\Helpers\Clock;
use App\Services\Billing\Gateway\Gateways;
use App\Services\Billing\Gateway\InvalidWebhook;
use App\Services\Billing\Gateway\MercadoPagoGateway;

/**
 * Integración con Mercado Pago Checkout Pro con la API simulada en memoria (Fase 10): forma de la
 * preferencia, verificación de la firma del webhook y consulta del pago antes de aplicarlo.
 */
final class MercadoPagoTest extends BillingTestCase
{
    private const SECRET = 'clave-secreta-de-prueba';

    /** @var list<array{method:string, url:string, headers:list<string>, body:string}> */
    private array $requests = [];

    /** @var array<string, array{status:int, body:string}> respuestas por "MÉTODO ruta" */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requests = [];
        $this->responses = [];
        Gateways::use(new MercadoPagoGateway([
            'access_token' => 'TEST-token', 'webhook_secret' => self::SECRET, 'endpoint' => 'https://api.mercadopago.test',
            'statement_descriptor' => 'BARBERAPP', 'signature_tolerance' => 600, 'timeout' => 5,
        ], function (string $method, string $url, array $headers, string $body, int $timeout): array {
            $this->requests[] = compact('method', 'url', 'headers', 'body');
            $key = $method . ' ' . (string) parse_url($url, PHP_URL_PATH);
            return $this->responses[$key] ?? ['status' => 404, 'body' => '{"message":"not found"}'];
        }));
    }

    protected function tearDown(): void
    {
        Gateways::use(null);
        parent::tearDown();
    }

    /** @return array<string, mixed> orden creada */
    private function checkout(): array
    {
        $this->responses['POST /checkout/preferences'] = ['status' => 201, 'body' => (string) json_encode(['id' => '123-pref', 'init_point' => 'https://www.mercadopago.com.pe/checkout/v1/redirect?pref_id=123-pref'])];
        $co = $this->ok($this->panel('POST', '/suscripcion/pagar', $this->as(self::OWNER), ['plan_id' => $this->planId('profesional'), 'ciclo' => 'mensual']), 201);
        $this->assertSame('https://www.mercadopago.com.pe/checkout/v1/redirect?pref_id=123-pref', $co['url']);
        return $co['orden'];
    }

    private function paymentResponse(string $id, string $status, string $ref, float $amount = 79.0): void
    {
        $this->responses["GET /v1/payments/{$id}"] = ['status' => 200, 'body' => (string) json_encode([
            'id' => (int) $id, 'status' => $status, 'status_detail' => 'accredited', 'transaction_amount' => $amount, 'currency_id' => 'PEN',
            'external_reference' => $ref, 'date_approved' => '2026-10-22T10:00:00.000-05:00',
        ])];
    }

    private function webhook(string $paymentId, string $eventId, ?string $secret = null): Response
    {
        $requestId = 'req-' . $eventId;
        $ts = (string) Clock::nowUtc()->getTimestamp();
        $sig = hash_hmac('sha256', "id:{$paymentId};request-id:{$requestId};ts:{$ts};", $secret ?? self::SECRET);
        $body = ['id' => $eventId, 'type' => 'payment', 'action' => 'payment.updated', 'live_mode' => false, 'data' => ['id' => $paymentId]];
        return $this->send('POST', "/webhooks/pagos/mercadopago?data_id={$paymentId}&type=payment", (string) json_encode($body),
            ['Content-Type' => 'application/json', 'x-signature' => "ts={$ts},v1={$sig}", 'x-request-id' => $requestId]);
    }

    public function testPreferenceCarriesServerAmountReferenceAndWebhookUrl(): void
    {
        $orden = $this->checkout();
        $req = $this->requests[0];
        $this->assertSame('POST', $req['method']);
        $this->assertSame('https://api.mercadopago.test/checkout/preferences', $req['url']);
        $this->assertContains('Authorization: Bearer TEST-token', $req['headers']);
        $this->assertContains('X-Idempotency-Key: ' . $orden['codigo'], $req['headers']);
        $body = json_decode($req['body'], true);
        $this->assertSame($orden['codigo'], $body['external_reference']);
        $this->assertSame(['id' => $orden['codigo'], 'title' => $orden['detalle'], 'quantity' => 1, 'currency_id' => 'PEN', 'unit_price' => 79], $body['items'][0]);
        $this->assertStringEndsWith('/api/v1/webhooks/pagos/mercadopago', $body['notification_url']);
        $this->assertStringContainsString('pages/panel/suscripcion.html?tenant=demo-centro&orden=' . $orden['codigo'], $body['back_urls']['success']);
        $this->assertTrue($body['expires']);
        $this->assertSame('propietario.centro@barberapp.test', $body['payer']['email']);
        $this->assertArrayNotHasKey('auto_return', $body, 'Solo con URL HTTPS');
        $this->assertSame('mercadopago', $this->scalar('SELECT pasarela FROM ordenes_pago WHERE codigo = ?', [$orden['codigo']]));
        $this->assertSame('123-pref', $this->scalar('SELECT checkout_id FROM ordenes_pago WHERE codigo = ?', [$orden['codigo']]));
    }

    public function testSignedWebhookAppliesPaymentAfterQueryingTheApi(): void
    {
        $orden = $this->checkout();
        $antes = $this->sub('demo-centro');
        $this->paymentResponse('987654', 'approved', $orden['codigo']);

        $bad = $this->webhook('987654', 'evt-1', 'otra-clave');
        $this->assertSame(401, $bad->status());
        $this->assertSame(1, $this->auditCount('pagos.webhook_firma_invalida', 'denegado'));
        $this->assertSame($antes['periodo_fin_en'], $this->sub('demo-centro')['periodo_fin_en']);

        $ok = $this->webhook('987654', 'evt-1');
        $this->assertSame(200, $ok->status(), $ok->body());
        $this->assertSame('aplicado', $this->data($ok)['resultado']);
        $this->assertSame('GET', end($this->requests)['method'], 'Se consultó el pago a la API');
        $this->assertNotSame($antes['periodo_fin_en'], $this->sub('demo-centro')['periodo_fin_en']);
        $pago = $this->db->query("SELECT * FROM pagos_saas WHERE metodo = 'pasarela'")->fetch();
        $this->assertSame(['987654', 'confirmado', '2026-10-22 15:00:00'], [$pago['referencia_externa'], $pago['estado'], $pago['pagado_en']]);
        $this->assertSame('procesado', $this->scalar("SELECT estado FROM webhook_eventos WHERE proveedor = 'mercadopago' AND evento_id_externo = 'evt-1'"));

        // Mismo evento repetido y otro evento del mismo pago: no se aplica dos veces.
        $this->assertSame('duplicado', $this->data($this->webhook('987654', 'evt-1'))['resultado']);
        $this->assertSame('duplicado', $this->data($this->webhook('987654', 'evt-2'))['resultado']);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM pagos_saas WHERE metodo = 'pasarela'"));

        // Reembolso en Mercado Pago → el pago queda reembolsado (sin acortar el periodo).
        $fin = $this->sub('demo-centro')['periodo_fin_en'];
        $this->paymentResponse('987654', 'refunded', $orden['codigo']);
        $this->assertSame('reembolsado', $this->data($this->webhook('987654', 'evt-3'))['resultado']);
        $this->assertSame('reembolsado', $this->scalar("SELECT estado FROM pagos_saas WHERE referencia_externa = '987654'"));
        $this->assertSame($fin, $this->sub('demo-centro')['periodo_fin_en']);
    }

    public function testGatewayOutageReturns503SoMercadoPagoRetries(): void
    {
        $orden = $this->checkout();
        $this->responses['GET /v1/payments/555'] = ['status' => 500, 'body' => '{"message":"internal"}'];
        $res = $this->webhook('555', 'evt-x');
        $this->assertSame(503, $res->status());
        $this->assertSame('fallido', $this->scalar("SELECT estado FROM webhook_eventos WHERE evento_id_externo = 'evt-x'"));

        $this->paymentResponse('555', 'approved', $orden['codigo']);
        $this->assertSame('aplicado', $this->data($this->webhook('555', 'evt-x'))['resultado'], 'El reintento del mismo evento se procesa');
    }

    public function testReturnPageSyncsWithPaymentIdWhenWebhookHasNotArrived(): void
    {
        $orden = $this->checkout();
        $this->paymentResponse('777', 'approved', $orden['codigo']);
        $r = $this->ok($this->panel('GET', "/suscripcion/ordenes/{$orden['codigo']}?payment_id=777", $this->as(self::OWNER)));
        $this->assertSame(['aplicado', 'pagada'], [$r['sincronizacion'], $r['orden']['estado']]);

        // Un pago de otra orden no se puede "colgar" de esta.
        $otra = $this->checkout();
        $r2 = $this->ok($this->panel('GET', "/suscripcion/ordenes/{$otra['codigo']}?payment_id=777", $this->as(self::OWNER)));
        $this->assertSame(['sin_cambios', 'pendiente'], [$r2['sincronizacion'], $r2['orden']['estado']]);
    }

    public function testSignatureVerificationRules(): void
    {
        $ts = (string) Clock::nowUtc()->getTimestamp();
        $sig = hash_hmac('sha256', "id:abc123;request-id:r1;ts:{$ts};", 's');
        MercadoPagoGateway::verify("ts={$ts},v1={$sig}", 'r1', 'ABC123', 's', 600); // el id alfanumérico se compara en minúsculas
        $this->addToAssertionCount(1);
        foreach (['', "ts={$ts}", "v1={$sig}", "ts=abc,v1={$sig}"] as $header) {
            try {
                MercadoPagoGateway::verify($header, 'r1', 'abc123', 's', 600);
                $this->fail('Debió rechazar: ' . $header);
            } catch (InvalidWebhook $e) {
                $this->addToAssertionCount(1);
            }
        }
        $old = (string) (Clock::nowUtc()->getTimestamp() - 3600);
        $this->expectException(InvalidWebhook::class);
        MercadoPagoGateway::verify('ts=' . $old . ',v1=' . hash_hmac('sha256', "id:abc123;request-id:r1;ts:{$old};", 's'), 'r1', 'abc123', 's', 600);
    }

    public function testStatusMapping(): void
    {
        $this->assertSame(
            ['aprobado', 'pendiente', 'pendiente', 'rechazado', 'rechazado', 'reembolsado', 'reembolsado'],
            array_map([MercadoPagoGateway::class, 'mapStatus'], ['approved', 'pending', 'in_process', 'rejected', 'cancelled', 'refunded', 'charged_back'])
        );
    }
}
