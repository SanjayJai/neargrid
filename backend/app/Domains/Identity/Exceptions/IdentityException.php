<?php

declare(strict_types=1);

namespace App\Domains\Identity\Exceptions;

use Illuminate\Http\Response;
use RuntimeException;

final class IdentityException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = Response::HTTP_BAD_REQUEST,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
