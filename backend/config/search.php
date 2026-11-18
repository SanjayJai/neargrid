<?php

declare(strict_types=1);

return [

    'driver' => env('SEARCH_ENGINE_DRIVER', 'mysql'),

    'pagination' => [
        'default_per_page' => (int) env('SEARCH_DEFAULT_PER_PAGE', 20),
        'min_per_page' => 1,
        'max_per_page' => (int) env('SEARCH_MAX_PER_PAGE', 40),
        'max_page' => (int) env('SEARCH_MAX_PAGE', 250),
    ],

    'geo' => [
        'default_radius_km' => (float) env('SEARCH_DEFAULT_RADIUS_KM', 10.0),
        'min_radius_km' => 0.5,
        'max_radius_km' => (float) env('SEARCH_MAX_RADIUS_KM', 50.0),
        'distance_decay_half_life_km' => 5.0,
    ],

    'ranking' => [
        'weights' => [
            'text' => 0.32,
            'category_service' => 0.13,
            'geo' => 0.20,
            'rating' => 0.15,
            'trust' => 0.10,
            'completeness' => 0.06,
            'recency' => 0.04,
        ],
        'bayesian' => [
            'prior_mean' => 3.5,
            'prior_weight' => 10,
        ],
        'recency_half_life_days' => 180,
    ],

    'sponsored' => [
        'max_slots_page_1' => (int) env('SEARCH_SPONSORED_MAX_SLOTS', 2),
        'min_rating_floor' => 3.0,
        'min_reviews_for_rating_floor' => 5,
        'min_completeness_score' => 40,
        'disclosure_label' => 'Sponsored',
    ],

    'autocomplete' => [
        'min_query_length' => (int) env('SEARCH_SUGGESTIONS_MIN_LENGTH', 2),
        'max_query_length' => 80,
        'cache_store' => env('SEARCH_AUTOCOMPLETE_CACHE_STORE', env('CACHE_STORE', 'redis')),
        'cache_ttl_seconds' => (int) env('SEARCH_SUGGESTIONS_CACHE_TTL', 120),
        'max_per_group' => [
            'categories' => 4,
            'services' => 4,
            'localities' => 4,
            'cities' => 3,
            'businesses' => 5,
        ],
        'rate_limit_per_minute' => (int) env('SEARCH_SUGGESTIONS_RATE_LIMIT', 120),
    ],

    'rate_limit_per_minute' => (int) env('SEARCH_RATE_LIMIT', 120),

    'cache' => [
        'filters_ttl_seconds' => 120,
        'synonyms_ttl_seconds' => 300,
        'search_http_max_age' => 30,
        'suggestions_http_max_age' => 60,
        'filters_http_max_age' => 120,
    ],

    'seo' => [
        'site_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/'),
        'max_indexed_page' => (int) env('SEO_MAX_INDEXED_PAGE', 50),
        'min_listings_for_locality_category_index' => (int) env('SEO_MIN_LOCALITY_CATEGORY_LISTINGS', 3),
    ],

    'performance' => [
        'slow_query_threshold_ms' => (int) env('SEARCH_SLOW_QUERY_MS', 250),
        'log_queries' => (bool) env('SEARCH_LOG_QUERIES', true),
    ],

];
