<?php

declare(strict_types=1);

return [

    'public_disk' => env('MEDIA_PUBLIC_DISK', 'media_public'),

    'kyc_disk' => env('MEDIA_KYC_DISK', 'kyc_private'),

    'preserve_originals' => (bool) env('MEDIA_PRESERVE_ORIGINALS', false),

    'async_threshold_bytes' => (int) env('MEDIA_ASYNC_THRESHOLD_BYTES', 524288), // 512 KB

    'queue' => env('MEDIA_QUEUE_NAME', 'media'),

    'kyc_signed_url_ttl_seconds' => (int) env('MEDIA_KYC_SIGNED_URL_TTL_SECONDS', 60),

    'limits' => [
        'public_max_bytes' => (int) env('MEDIA_PUBLIC_MAX_BYTES', 5 * 1024 * 1024), // 5 MB
        'kyc_max_bytes' => (int) env('MEDIA_KYC_MAX_BYTES', 10 * 1024 * 1024), // 10 MB
        'max_gallery_per_business' => (int) env('MEDIA_MAX_GALLERY_PER_BUSINESS', 20),
        'max_kyc_docs_per_business' => (int) env('MEDIA_MAX_KYC_DOCS_PER_BUSINESS', 10),
        'min_image_width' => 50,
        'min_image_height' => 50,
        'max_image_width' => 8000,
        'max_image_height' => 8000,
    ],

    'variants' => [
        'thumb' => ['max_width' => 320, 'quality' => 80],
        'card' => ['max_width' => 640, 'quality' => 82],
        'full' => ['max_width' => 1280, 'quality' => 84],
    ],

];
