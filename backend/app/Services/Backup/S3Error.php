<?php

declare(strict_types=1);

namespace App\Services\Backup;

use RuntimeException;

/** Error del almacenamiento externo; getCode() es el estado HTTP (0 = sin conexión). */
final class S3Error extends RuntimeException
{
}
