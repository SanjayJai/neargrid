<?php

declare(strict_types=1);

namespace App\Domains\Media\DTOs;

final readonly class ValidatedUpload
{
    public function __construct(
        public string $mimeType,
        public string $extension,
        public int $sizeBytes,
        public ?int $width,
        public ?int $height,
        public string $sha256,
        public bool $isImage,
        public string $realPath,
    ) {}
}
