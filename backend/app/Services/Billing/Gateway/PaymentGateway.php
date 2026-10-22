<?php

declare(strict_types=1);

namespace App\Services\Billing\Gateway;

use App\Core\Request;

/**
 * Pasarela de pago para las suscripciones (Fase 10). La pasarela muestra su propia página de
 * pago: BARBERAPP nunca recibe datos de tarjeta. El pago se da por bueno solo cuando el
 * servidor consulta el pago a la pasarela (fetchPayment) tras un webhook verificado.
 */
interface PaymentGateway
{
    public function name(): string;

    /**
     * Crea la sesión de pago de una orden.
     *
     * @param array<string, mixed>  $order fila de ordenes_pago
     * @param array<string, string> $payer email, nombre
     * @param array<string, string> $urls  success, failure, pending, webhook
     * @return array{checkout_id:string, url:string}
     */
    public function createCheckout(array $order, array $payer, array $urls): array;

    /**
     * Estado real de un pago en la pasarela.
     *
     * @return array{id:string, estado:string, monto:string, moneda:string, referencia:?string, pagado_en:?string, estado_pasarela:string}
     *         estado: aprobado | pendiente | rechazado | reembolsado
     */
    public function fetchPayment(string $paymentId): array;

    /**
     * Verifica la autenticidad del webhook (firma) y extrae el evento. Lanza
     * InvalidWebhook si la firma no es válida.
     *
     * @return array{evento_id:string, tipo:string, pago_id:?string}
     */
    public function parseWebhook(Request $request): array;
}
