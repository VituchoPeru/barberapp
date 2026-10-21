<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Recurso no encontrado.')
    {
        parent::__construct(404, 'not_found', $message);
    }
}
