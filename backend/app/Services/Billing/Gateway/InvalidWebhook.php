<?php

declare(strict_types=1);

namespace App\Services\Billing\Gateway;

use RuntimeException;

/** Webhook con firma ausente o inválida: se responde 401 y no se procesa. */
final class InvalidWebhook extends RuntimeException
{
}
