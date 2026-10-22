<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * Cobro de suscripciones en línea (Fase 10).
 *   PAYMENT_DRIVER=sandbox      → simulador local: una página de BARBERAPP aprueba o rechaza el
 *                                 pago y envía un webhook firmado (desarrollo/XAMPP; se niega en producción)
 *   PAYMENT_DRIVER=mercadopago  → Mercado Pago Checkout Pro (PEN): el propietario paga en la página de
 *                                 Mercado Pago y el webhook confirma el pago
 *   PAYMENT_DRIVER=none         → sin pago en línea (solo activación y pagos manuales desde la plataforma)
 * Nunca se reciben ni guardan datos de tarjeta: la pasarela los gestiona en su propia página.
 */
return [
    'driver' => (string) Env::get('PAYMENT_DRIVER', 'sandbox'),
    // Minutos que una orden de pago queda abierta (y la preferencia de la pasarela aceptando pagos).
    'order_ttl_minutes' => (int) Env::get('PAYMENT_ORDER_TTL_MINUTES', 1440),
    // Importe mínimo de un cobro (las mejoras de plan por debajo de este importe se aplican sin cobro).
    'min_amount' => '1.00',
    'mercadopago' => [
        'access_token' => (string) Env::get('MP_ACCESS_TOKEN', ''),
        // Clave secreta de la firma de webhooks ("Tus integraciones" → Webhooks → Clave secreta).
        'webhook_secret' => (string) Env::get('MP_WEBHOOK_SECRET', ''),
        'endpoint' => (string) Env::get('MP_ENDPOINT', 'https://api.mercadopago.com'),
        'statement_descriptor' => (string) Env::get('MP_STATEMENT_DESCRIPTOR', 'BARBERAPP'),
        // Tolerancia (segundos) entre el ts de la firma y el reloj del servidor. 0 = no comprobar.
        'signature_tolerance' => (int) Env::get('MP_SIGNATURE_TOLERANCE', 600),
        'timeout' => (int) Env::get('MP_TIMEOUT', 15),
    ],
];
