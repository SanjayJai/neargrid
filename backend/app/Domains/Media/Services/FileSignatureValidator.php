<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Media\DTOs\ValidatedUpload;
use App\Domains\Media\Exceptions\MediaException;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;

/**
 * Validates uploads exclusively by raw magic bytes, finfo inspection, and GD decode.
 * Completely ignores client-provided filenames, extensions, and Content-Type headers.
 */
final class FileSignatureValidator
{
    public function validatePublicImage(UploadedFile $file): ValidatedUpload
    {
        $maxBytes = (int) config('media.limits.public_max_bytes', 5 * 1024 * 1024);

        return $this->validate($file, $maxBytes, allowPdf: false);
    }

    public function validateKycDocument(UploadedFile $file): ValidatedUpload
    {
        $maxBytes = (int) config('media.limits.kyc_max_bytes', 10 * 1024 * 1024);

        return $this->validate($file, $maxBytes, allowPdf: true);
    }

    private function validate(UploadedFile $file, int $maxBytes, bool $allowPdf): ValidatedUpload
    {
        if (! $file->isValid()) {
            throw new MediaException('INVALID_UPLOAD', 'The uploaded file is incomplete or invalid.');
        }

        $path = $file->getRealPath();
        if ($path === false || ! is_file($path)) {
            throw new MediaException('INVALID_UPLOAD', 'Unable to read uploaded file stream.');
        }

        $sizeBytes = (int) filesize($path);
        if ($sizeBytes <= 0) {
            throw new MediaException('EMPTY_FILE', 'Uploaded file cannot be empty.');
        }

        if ($sizeBytes > $maxBytes) {
            throw new MediaException(
                'FILE_TOO_LARGE',
                'Uploaded file exceeds the maximum permitted size.',
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
                ['max_bytes' => $maxBytes, 'actual_bytes' => $sizeBytes],
            );
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new MediaException('INVALID_UPLOAD', 'Unable to inspect uploaded file bytes.');
        }

        $header = (string) fread($handle, 64);
        fclose($handle);

        $this->rejectDangerousSignatures($header);

        $detected = $this->detectFormatByMagicBytes($header);
        if ($detected === null) {
            throw new MediaException(
                'UNSUPPORTED_FILE_SIGNATURE',
                'File signature is unsupported or does not match an allowed format.'
            );
        }

        [$mimeType, $extension, $isImage] = $detected;

        if (! $isImage && ! $allowPdf) {
            throw new MediaException(
                'UNSUPPORTED_FILE_SIGNATURE',
                'Only JPEG, PNG, and WebP images are permitted for public business media.'
            );
        }

        // Cross-check with PHP finfo to ensure header isn't a trivial polyglot prefix.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $finfoMime = (string) $finfo->file($path);
        if ($finfoMime !== $mimeType) {
            throw new MediaException(
                'MIME_SIGNATURE_MISMATCH',
                'Detected file content does not match its header signature.'
            );
        }

        $width = null;
        $height = null;

        if ($isImage) {
            [$width, $height] = $this->verifyDecodableImage($path, $mimeType);
        } else {
            $this->verifyPdfStructure($path);
        }

        $sha256 = (string) hash_file('sha256', $path);

        return new ValidatedUpload(
            mimeType: $mimeType,
            extension: $extension,
            sizeBytes: $sizeBytes,
            width: $width,
            height: $height,
            sha256: $sha256,
            isImage: $isImage,
            realPath: $path,
        );
    }

    private function rejectDangerousSignatures(string $header): void
    {
        $trimmed = ltrim($header);
        $lower = strtolower($trimmed);

        $dangerousPrefixes = [
            'MZ',          // Windows PE executable / DLL
            "\x7FELF",     // Linux ELF binary
            '#!/',         // Shell / script shebang
            '<?php',       // PHP script
            '<?=',         // PHP short echo tag
            '<script',     // Embedded JS
            '<svg',        // SVG (XSS vector)
            '<!doctype',   // HTML
            '<html',       // HTML
            "PK\x03\x04",  // ZIP / JAR / Office macro archive
        ];

        foreach ($dangerousPrefixes as $prefix) {
            $needle = ctype_print($prefix) ? strtolower($prefix) : $prefix;
            $haystack = ctype_print($prefix) ? $lower : $header;
            if (str_starts_with($haystack, $needle)) {
                throw new MediaException(
                    'DISALLOWED_EXECUTABLE_OR_SCRIPT',
                    'Executable, script, or archive files are strictly prohibited.'
                );
            }
        }
    }

    /**
     * @return array{0: string, 1: string, 2: bool}|null
     */
    private function detectFormatByMagicBytes(string $header): ?array
    {
        // JPEG: FF D8 FF
        if (strlen($header) >= 3 && str_starts_with($header, "\xFF\xD8\xFF")) {
            return ['image/jpeg', 'jpg', true];
        }

        // PNG: 89 50 4E 47 0D 0A 1A 0A
        if (strlen($header) >= 8 && str_starts_with($header, "\x89PNG\r\n\x1A\n")) {
            return ['image/png', 'png', true];
        }

        // WebP: RIFF....WEBP
        if (strlen($header) >= 12 && substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') {
            return ['image/webp', 'webp', true];
        }

        // PDF: %PDF-
        if (strlen($header) >= 5 && str_starts_with($header, '%PDF-')) {
            return ['application/pdf', 'pdf', false];
        }

        return null;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function verifyDecodableImage(string $path, string $mimeType): array
    {
        $info = @getimagesize($path);
        if ($info === false || ! isset($info[0], $info[1])) {
            throw new MediaException('MALFORMED_IMAGE', 'Uploaded image is corrupted or unreadable.');
        }

        $width = (int) $info[0];
        $height = (int) $info[1];

        $minW = (int) config('media.limits.min_image_width', 50);
        $minH = (int) config('media.limits.min_image_height', 50);
        $maxW = (int) config('media.limits.max_image_width', 8000);
        $maxH = (int) config('media.limits.max_image_height', 8000);

        if ($width < $minW || $height < $minH || $width > $maxW || $height > $maxH) {
            throw new MediaException(
                'INVALID_IMAGE_DIMENSIONS',
                "Image dimensions must be between {$minW}x{$minH} and {$maxW}x{$maxH} pixels.",
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['width' => $width, 'height' => $height],
            );
        }

        $resource = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };

        if ($resource === false) {
            throw new MediaException('MALFORMED_IMAGE', 'Uploaded image failed decoding verification.');
        }

        imagedestroy($resource);

        return [$width, $height];
    }

    private function verifyPdfStructure(string $path): void
    {
        $content = @file_get_contents($path);
        if ($content === false || ! str_contains($content, '%%EOF')) {
            throw new MediaException('MALFORMED_PDF', 'Uploaded PDF file is malformed or truncated.');
        }
    }
}
