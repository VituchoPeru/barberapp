<?php

declare(strict_types=1);

namespace App\Services\Billing\Gateway;

use App\Core\Request;
use App\Helpers\Clock;
use App\Services\Notifications\Push\FcmTransport;
use RuntimeException;

/**
 * Mercado Pago Checkout Pro (Perú, soles). Implementación propia con la API REST (sin SDK, para
 * mantener compatibilidad con PHP 8.0 y no sumar dependencias):
 *   - POST /checkout/preferences crea la preferencia; el propietario paga en init_point.
 *   - El webhook (notification_url) llega con x-signature: ts=…,v1=HMAC-SHA256 de
 *     "id:{data.id};request-id:{x-request-id};ts:{ts};" con la clave secreta de webhooks.
 *   - El estado del pago se consulta siempre con GET /v1/payments/{id}: el cuerpo del webhook
 *     nunca se usa como prueba de pago.
 */
final class MercadoPagoGateway implements PaymentGateway
{
    /** @var callable(string, string, array<int, string>, string, int): array{status:int, body:string} */
    private $http;

    /**
     * @param array<string, mixed> $cfg billing.mercadopago
     * @param callable|null        $http (método, url, cabeceras, cuerpo, timeout) → {status, body}; por defecto streams de PHP
     */
    public function __construct(private array $cfg, ?callable $http = null)
    {
        $this->http = $http ?? [FcmTransport::class, 'request'];
    }

    public function name(): string
    {
        return 'mercadopago';
    }

    public function createCheckout(array $order, array $payer, array $urls): array
    {
        $body = [
            'items' => [[
                'id' => (string) $order['codigo'],
                'title' => (string) $order['detalle'],
                'quantity' => 1,
                'currency_id' => (string) $order['moneda'],
                'unit_price' => (float) $order['monto'],
            ]],
            'payer' => array_filter(['email' => $payer['email'] ?? null, 'name' => $payer['nombre'] ?? null]),
            'external_reference' => (string) $order['codigo'],
            'notification_url' => $urls['webhook'],
            'back_urls' => ['success' => $urls['success'], 'failure' => $urls['failure'], 'pending' => $urls['pending']],
            'expires' => true,
            'expiration_date_to' => (new \DateTimeImmutable((string) $order['expira_en'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.vP'),
            'statement_descriptor' => substr((string) ($this->cfg['statement_descriptor'] ?? 'BARBERAPP'), 0, 22),
            'binary_mode' => false,
            'metadata' => ['orden' => (string) $order['codigo']],
        ];
        // Mercado Pago solo vuelve solo (auto_return) a URLs públicas con HTTPS.
        if (str_starts_with($urls['success'], 'https://')) {
            $body['auto_return'] = 'approved';
        }
        $res = $this->call('POST', '/checkout/preferences', $body, ['X-Idempotency-Key: ' . $order['codigo']]);
        if (empty($res['id']) || empty($res['init_point'])) {
            throw new GatewayError('Mercado Pago no devolvió la preferencia de pago.');
        }
        return ['checkout_id' => (string) $res['id'], 'url' => (string) $res['init_point']];
    }

    public function fetchPayment(string $paymentId): array
    {
        if (!preg_match('/^[0-9A-Za-z_-]{1,64}$/', $paymentId)) {
            throw new GatewayError('Identificador de pago inválido.');
        }
        $p = $this->call('GET', '/v1/payments/' . $paymentId, null);
        $status = (string) ($p['status'] ?? '');
        return [
            'id' => (string) ($p['id'] ?? $paymentId),
            'estado' => self::mapStatus($status),
            'monto' => number_format((float) ($p['transaction_amount'] ?? 0), 2, '.', ''),
            'moneda' => (string) ($p['currency_id'] ?? ''),
            'referencia' => isset($p['external_reference']) && $p['external_reference'] !== '' ? (string) $p['external_reference'] : null,
            'pagado_en' => isset($p['date_approved']) && $p['date_approved'] ? gmdate('Y-m-d H:i:s', (int) strtotime((string) $p['date_approved'])) : null,
            'estado_pasarela' => $status . (isset($p['status_detail']) ? ':' . $p['status_detail'] : ''),
        ];
    }

    public static function mapStatus(string $status): string
    {
        switch ($status) {
            case 'approved':
                return 'aprobado';
            case 'refunded':
            case 'charged_back':
                return 'reembolsado';
            case 'rejected':
            case 'cancelled':
                return 'rechazado';
            default: // pending, in_process, authorized, in_mediation
                return 'pendiente';
        }
    }

    public function parseWebhook(Request $request): array
    {
        $body = json_decode($request->rawBody(), true);
        $body = is_array($body) ? $body : [];
        // PHP convierte "data.id" de la query en "data_id".
        $dataId = (string) ($request->query('data_id') ?? $request->query('data.id') ?? ($body['data']['id'] ?? ''));
        $tipo = (string) ($body['type'] ?? $request->query('type') ?? $request->query('topic') ?? '');
        $requestId = (string) ($request->header('x-request-id') ?? '');
        self::verify((string) ($request->header('x-signature') ?? ''), $requestId, $dataId, (string) ($this->cfg['webhook_secret'] ?? ''), (int) ($this->cfg['signature_tolerance'] ?? 0));
        $evento = isset($body['id']) && $body['id'] !== '' ? (string) $body['id'] : ($requestId !== '' ? $requestId : sha1($request->rawBody()));
        return ['evento_id' => $evento, 'tipo' => $tipo, 'pago_id' => $tipo === 'payment' && $dataId !== '' ? $dataId : null];
    }

    /** Valida x-signature (HMAC-SHA256 en tiempo constante). */
    public static function verify(string $header, string $requestId, string $dataId, string $secret, int $tolerance = 0): void
    {
        if ($secret === '') {
            throw new InvalidWebhook('MP_WEBHOOK_SECRET no está configurado.');
        }
        $ts = null;
        $v1 = null;
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) !== 2) {
                continue;
            }
            $k = strtolower(trim($kv[0]));
            if ($k === 'ts') {
                $ts = trim($kv[1]);
            } elseif ($k === 'v1') {
                $v1 = trim($kv[1]);
            }
        }
        if ($ts === null || $v1 === null || !ctype_digit($ts)) {
            throw new InvalidWebhook('Firma ausente o mal formada.');
        }
        $manifest = self::manifest($dataId, $requestId, $ts);
        if (!hash_equals(hash_hmac('sha256', $manifest, $secret), strtolower($v1))) {
            throw new InvalidWebhook('Firma inválida.');
        }
        if ($tolerance > 0) {
            // ts puede venir en segundos o en milisegundos.
            $sec = strlen($ts) > 11 ? intdiv((int) $ts, 1000) : (int) $ts;
            if (abs(Clock::nowUtc()->getTimestamp() - $sec) > $tolerance) {
                throw new InvalidWebhook('Firma fuera de tiempo.');
            }
        }
    }

    public static function manifest(string $dataId, string $requestId, string $ts): string
    {
        $parts = [];
        if (trim($dataId) !== '') {
            $parts[] = 'id:' . strtolower(trim($dataId));
        }
        if (trim($requestId) !== '') {
            $parts[] = 'request-id:' . trim($requestId);
        }
        $parts[] = 'ts:' . $ts;
        return implode(';', $parts) . ';';
    }

    /**
     * @param array<string, mixed>|null $body
     * @param list<string>              $extraHeaders
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, ?array $body, array $extraHeaders = []): array
    {
        $token = (string) ($this->cfg['access_token'] ?? '');
        if ($token === '') {
            throw new GatewayError('MP_ACCESS_TOKEN no está configurado.');
        }
        $headers = array_merge(['Authorization: Bearer ' . $token, 'Accept: application/json'], $body !== null ? ['Content-Type: application/json'] : [], $extraHeaders);
        try {
            $res = ($this->http)($method, rtrim((string) ($this->cfg['endpoint'] ?? 'https://api.mercadopago.com'), '/') . $path, $headers,
                $body !== null ? (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '', (int) ($this->cfg['timeout'] ?? 15));
        } catch (RuntimeException $e) {
            throw new GatewayError('No se pudo conectar con Mercado Pago: ' . $e->getMessage(), 0, $e);
        }
        $data = json_decode((string) $res['body'], true);
        if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($data)) {
            $msg = is_array($data) ? (string) ($data['message'] ?? $data['error'] ?? '') : '';
            throw new GatewayError("Mercado Pago respondió {$res['status']}" . ($msg !== '' ? ": {$msg}" : '') . '.');
        }
        return $data;
    }
}
