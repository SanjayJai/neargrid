<?php

declare(strict_types=1);

namespace App\Domains\Directory\Exceptions;

use Illuminate\Http\Response;
use RuntimeException;

final class DirectoryException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = Response::HTTP_UNPROCESSABLE_ENTITY,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
