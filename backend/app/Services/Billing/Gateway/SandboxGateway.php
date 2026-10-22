<?php

declare(strict_types=1);

namespace App\Services\Billing\Gateway;

use App\Core\Config;
use App\Core\Request;
use App\Helpers\Clock;

/**
 * Simulador local de pasarela (desarrollo y pruebas; Gateways lo niega en producción).
 *
 * La "página de pago" es pages/panel/pago-simulado.html: el propietario elige aprobar o rechazar
 * y el backend genera un webhook firmado igual que Mercado Pago (x-signature HMAC-SHA256), que
 * recorre exactamente el mismo camino de verificación, consulta del pago y aplicación.
 * El identificador del pago lleva su resultado: sbx_{orden}_{a|r}_{centavos}_{moneda}.
 */
final class SandboxGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'sandbox';
    }

    public function createCheckout(array $order, array $payer, array $urls): array
    {
        return ['checkout_id' => 'sbx-' . $order['codigo'], 'url' => $urls['sandbox']];
    }

    /** Identificador del pago simulado de una orden. */
    public static function paymentId(array $order, bool $aprobar): string
    {
        return sprintf('sbx_%s_%s_%d_%s', $order['codigo'], $aprobar ? 'a' : 'r', (int) round((float) $order['monto'] * 100), $order['moneda']);
    }

    public function fetchPayment(string $paymentId): array
    {
        if (!preg_match('/^sbx_([A-Za-z0-9]{24})_([ar])_(\d{1,9})_([A-Z]{3})$/', $paymentId, $m)) {
            throw new GatewayError('Pago simulado desconocido.');
        }
        return [
            'id' => $paymentId,
            'estado' => $m[2] === 'a' ? 'aprobado' : 'rechazado',
            'monto' => number_format((int) $m[3] / 100, 2, '.', ''),
            'moneda' => $m[4],
            'referencia' => $m[1],
            'pagado_en' => $m[2] === 'a' ? Clock::nowSql() : null,
            'estado_pasarela' => $m[2] === 'a' ? 'approved' : 'rejected',
        ];
    }

    public function parseWebhook(Request $request): array
    {
        $body = json_decode($request->rawBody(), true);
        $body = is_array($body) ? $body : [];
        $dataId = (string) ($request->query('data_id') ?? ($body['data']['id'] ?? ''));
        $requestId = (string) ($request->header('x-request-id') ?? '');
        MercadoPagoGateway::verify((string) ($request->header('x-signature') ?? ''), $requestId, $dataId, self::secret(), 600);
        return ['evento_id' => (string) ($body['id'] ?? $requestId), 'tipo' => (string) ($body['type'] ?? ''), 'pago_id' => $dataId !== '' ? $dataId : null];
    }

    /**
     * Webhook firmado que enviaría la pasarela.
     *
     * @return array{query: array<string, string>, headers: array<string, string>, body: array<string, mixed>}
     */
    public static function webhook(string $paymentId): array
    {
        $requestId = bin2hex(random_bytes(8));
        $ts = (string) Clock::nowUtc()->getTimestamp();
        $sig = hash_hmac('sha256', MercadoPagoGateway::manifest($paymentId, $requestId, $ts), self::secret());
        return [
            'query' => ['data_id' => $paymentId, 'type' => 'payment'],
            'headers' => ['x-signature' => "ts={$ts},v1={$sig}", 'x-request-id' => $requestId],
            'body' => ['id' => 'sbx-evt-' . $requestId, 'type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => $paymentId]],
        ];
    }

    private static function secret(): string
    {
        return hash_hmac('sha256', 'barberapp-sandbox-webhook', (string) Config::get('auth.key', ''));
    }
}
