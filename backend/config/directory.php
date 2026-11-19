<?php

return [

    'api_version' => env('DIRECTORY_API_VERSION', 'v1'),

    'supported_locales' => ['en', 'ta'],

    'default_locale' => env('DIRECTORY_DEFAULT_LOCALE', 'en'),

    'default_currency' => env('DIRECTORY_DEFAULT_CURRENCY', 'INR'),

    'default_timezone' => env('DIRECTORY_DEFAULT_TIMEZONE', 'Asia/Kolkata'),

    'otp' => [
        'length' => 6,
        'ttl_seconds' => (int) env('OTP_TTL_SECONDS', 300),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 45),
        'pepper' => env('APP_OTP_PEPPER', env('APP_KEY')),
        'allowed_country_prefixes' => array_filter(array_map(
            'trim',
            explode(',', (string) env('OTP_ALLOWED_COUNTRY_PREFIXES', '+91'))
        )),
        'rate_limits' => [
            'phone_10m' => (int) env('OTP_LIMIT_PHONE_10M', 3),
            'phone_24h' => (int) env('OTP_LIMIT_PHONE_24H', 10),
            'ip_1h' => (int) env('OTP_LIMIT_IP_1H', 15),
            'verify_ip_10m' => (int) env('OTP_VERIFY_LIMIT_IP_10M', 30),
        ],
    ],

    'enquiries' => [
        'max_broadcast_recipients' => (int) env('ENQUIRY_MAX_BROADCAST_RECIPIENTS', 4),
    ],

    'search' => [
        'default_per_page' => 20,
        'max_per_page' => 40,
        'max_page' => 250,
        'default_radius_km' => 10,
        'max_radius_km' => 50,
    ],

    'seo' => [
        'min_listings_for_locality_category_index' => 3,
        'min_completeness_score_for_profile_index' => 40,
        'max_indexed_pagination_page' => 50,
    ],

    'revalidation' => [
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
        'secret' => env('REVALIDATION_WEBHOOK_SECRET', env('APP_KEY')),
        'queue' => env('REVALIDATION_QUEUE', 'notifications'),
    ],

];
