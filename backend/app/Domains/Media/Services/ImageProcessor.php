<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Media\Exceptions\MediaException;
use GdImage;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Decodes validated image uploads through GD, auto-applies EXIF orientation
 * before discarding all EXIF/GPS/XMP/IPTC metadata, and encodes clean WebP outputs.
 */
final class ImageProcessor
{
    /**
     * Generate thumb, card, and full WebP variants without upscaling.
     *
     * @return array{
     *     source_width: int,
     *     source_height: int,
     *     variants: array<string, array{bytes: string, width: int, height: int}>
     * }
     */
    public function generateWebpVariantsFromBytes(string $rawBytes, string $mimeType): array
    {
        $source = $this->decodeImage($rawBytes, $mimeType);
        $srcWidth = imagesx($source);
        $srcHeight = imagesy($source);

        /** @var array<string, array{max_width: int, quality: int}> $variantConfigs */
        $variantConfigs = (array) config('media.variants', [
            'thumb' => ['max_width' => 320, 'quality' => 80],
            'card' => ['max_width' => 640, 'quality' => 82],
            'full' => ['max_width' => 1280, 'quality' => 84],
        ]);

        $variants = [];

        foreach ($variantConfigs as $name => $cfg) {
            $maxWidth = max(1, (int) ($cfg['max_width'] ?? 1280));
            $quality = max(1, min(100, (int) ($cfg['quality'] ?? 82)));

            $variants[$name] = $this->resizeAndEncodeWebp($source, $srcWidth, $srcHeight, $maxWidth, $quality);
        }

        unset($source);

        return [
            'source_width' => $srcWidth,
            'source_height' => $srcHeight,
            'variants' => $variants,
        ];
    }

    /**
     * Re-encode a KYC image upload into clean EXIF/GPS-stripped WebP bytes.
     *
     * @return array{bytes: string, width: int, height: int, mime_type: string}
     */
    public function stripExifToWebpBytes(string $rawBytes, string $mimeType, int $maxWidth = 2048, int $quality = 88): array
    {
        $source = $this->decodeImage($rawBytes, $mimeType);
        $srcWidth = imagesx($source);
        $srcHeight = imagesy($source);

        $encoded = $this->resizeAndEncodeWebp($source, $srcWidth, $srcHeight, $maxWidth, $quality);
        unset($source);

        return [
            'bytes' => $encoded['bytes'],
            'width' => $encoded['width'],
            'height' => $encoded['height'],
            'mime_type' => 'image/webp',
        ];
    }

    private function decodeImage(string $rawBytes, string $mimeType): GdImage
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            throw new RuntimeException('GD extension with WebP support is required for media processing.');
        }

        $image = @imagecreatefromstring($rawBytes);
        if (! $image instanceof GdImage) {
            throw new MediaException(
                'CORRUPT_IMAGE',
                'The uploaded image could not be decoded.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        if ($mimeType === 'image/jpeg') {
            $image = $this->applyJpegOrientation($image, $rawBytes);
        }

        return $image;
    }

    private function applyJpegOrientation(GdImage $image, string $rawBytes): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $stream = @fopen('php://memory', 'rb+');
        if ($stream === false) {
            return $image;
        }

        try {
            fwrite($stream, $rawBytes);
            rewind($stream);
            $exif = @exif_read_data($stream);
        } finally {
            fclose($stream);
        }

        if (! is_array($exif) || ! isset($exif['Orientation'])) {
            return $image;
        }

        $rotated = match ((int) $exif['Orientation']) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => false,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    /**
     * @return array{bytes: string, width: int, height: int}
     */
    private function resizeAndEncodeWebp(
        GdImage $source,
        int $srcWidth,
        int $srcHeight,
        int $maxWidth,
        int $quality,
    ): array {
        if ($srcWidth <= $maxWidth) {
            $targetWidth = $srcWidth;
            $targetHeight = $srcHeight;
        } else {
            $targetWidth = $maxWidth;
            $targetHeight = max(1, (int) round($srcHeight * ($maxWidth / $srcWidth)));
        }

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if (! $canvas instanceof GdImage) {
            throw new RuntimeException('Failed to allocate GD image canvas.');
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
        }

        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $srcWidth,
            $srcHeight,
        );

        ob_start();
        $ok = imagewebp($canvas, null, $quality);
        $webpBytes = ob_get_clean();

        unset($canvas);

        if (! $ok || ! is_string($webpBytes) || $webpBytes === '') {
            throw new RuntimeException('Failed to encode WebP image variant.');
        }

        return [
            'bytes' => $webpBytes,
            'width' => $targetWidth,
            'height' => $targetHeight,
        ];
    }
}
