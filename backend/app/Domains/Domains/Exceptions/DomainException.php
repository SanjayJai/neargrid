<?php

declare(strict_types=1);

namespace App\Domains\Domains\Exceptions;

use RuntimeException;

final class DomainException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message, $httpStatus);
    }
}
