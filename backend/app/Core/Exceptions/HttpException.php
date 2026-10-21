<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;
use Throwable;

/** Error con código HTTP y código de error estable para el cliente. */
class HttpException extends RuntimeException
{
    /** @var array<string, mixed>|null */
    private ?array $details;

    /** @var array<string, string> */
    private array $headers;

    /**
     * @param array<string, mixed>|null $details
     * @param array<string, string>     $headers
     */
    public function __construct(
        private int $status,
        private string $errorCode,
        string $message,
        ?array $details = null,
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->details = $details;
        $this->headers = $headers;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, mixed>|null */
    public function details(): ?array
    {
        return $this->details;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
