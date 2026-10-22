<?php

declare(strict_types=1);

namespace App\Services\Billing\Gateway;

use App\Core\Config;
use App\Core\Exceptions\HttpException;

/** Elige la pasarela según PAYMENT_DRIVER (config/billing.php). */
final class Gateways
{
    private static ?PaymentGateway $override = null;

    /** Pruebas: fuerza una pasarela (p. ej. Mercado Pago con HTTP simulado). */
    public static function use(?PaymentGateway $gateway): void
    {
        self::$override = $gateway;
    }

    public static function driver(): string
    {
        if (self::$override !== null) {
            return self::$override->name();
        }
        $d = (string) Config::get('billing.driver', 'sandbox');
        if ($d === 'sandbox' && Config::get('app.env') === 'production') {
            return 'none';
        }
        return in_array($d, ['sandbox', 'mercadopago'], true) ? $d : 'none';
    }

    public static function enabled(): bool
    {
        return self::driver() !== 'none';
    }

    public static function make(?string $name = null): PaymentGateway
    {
        if (self::$override !== null && ($name === null || $name === self::$override->name())) {
            return self::$override;
        }
        $name = $name ?? self::driver();
        if ($name === 'sandbox' && Config::get('app.env') !== 'production') {
            return new SandboxGateway();
        }
        if ($name === 'mercadopago') {
            return new MercadoPagoGateway((array) Config::get('billing.mercadopago', []));
        }
        throw new HttpException(503, 'payments_disabled', 'El pago en línea no está disponible. Escríbenos a soporte para renovar tu plan.');
    }
}
