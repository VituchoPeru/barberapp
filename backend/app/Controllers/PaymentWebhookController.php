<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Billing\PaymentProcessor;

/**
 * Webhooks de la pasarela de pago (Fase 10). Público: la autenticidad se comprueba con la firma
 * y el pago se consulta a la pasarela antes de aplicarlo.
 *   POST /api/v1/webhooks/pagos/{proveedor}   (mercadopago | sandbox)
 * Responde 200 cuando el evento quedó registrado (aunque se ignore), 401 con firma inválida y
 * 503 si la pasarela no respondió (para que reintente).
 */
final class PaymentWebhookController extends BaseController
{
    public function handle(Request $request): Response
    {
        $provider = (string) $request->param('proveedor');
        if (!in_array($provider, ['mercadopago', 'sandbox'], true)) {
            throw new NotFoundException('Recurso no encontrado.');
        }
        $r = (new PaymentProcessor())->handleWebhook($provider, $request);
        return $r['status'] === 200
            ? $this->ok(['resultado' => $r['resultado']])
            : Response::error($r['status'], $r['resultado'], $r['status'] === 401 ? 'Firma inválida.' : 'Pasarela no disponible, reintenta.');
    }
}
