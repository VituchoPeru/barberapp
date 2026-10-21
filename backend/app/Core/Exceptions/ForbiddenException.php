<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'No tiene permiso para realizar esta acción.')
    {
        parent::__construct(403, 'forbidden', $message);
    }
}
