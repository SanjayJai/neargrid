<?php

declare(strict_types=1);

namespace App\Domains\Seo\Exceptions;

use Illuminate\Http\Response;
use RuntimeException;

final class SeoException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct($message);
    }
}
