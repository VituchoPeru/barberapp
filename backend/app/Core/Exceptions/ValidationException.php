<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class ValidationException extends HttpException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(array $errors, string $message = 'Los datos enviados no son válidos.')
    {
        parent::__construct(422, 'validation_failed', $message, ['fields' => $errors]);
    }
}
