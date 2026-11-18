<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Stateless Bearer token APIs do not rely on cross-site browser cookies.
    | Next.js calls Laravel server-side via BFF Route Handlers; Flutter clients
    | call Laravel directly with Authorization: Bearer <token>.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000'))
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-Client-Platform',
        'X-Request-ID',
    ],

    'exposed_headers' => [
        'X-Request-ID',
        'Retry-After',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
    ],

    'max_age' => 3600,

    'supports_credentials' => false,

];
