<?php

declare(strict_types=1);

namespace App\Services\Billing\Gateway;

use RuntimeException;

/** La pasarela no respondió o respondió con error (se reintenta; el mensaje no expone secretos). */
final class GatewayError extends RuntimeException
{
}
