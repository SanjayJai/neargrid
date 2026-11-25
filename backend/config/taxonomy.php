<?php

return [
    'max_depth' => (int) env('TAXONOMY_MAX_DEPTH', 5),
    'cache_ttl_seconds' => (int) env('TAXONOMY_CACHE_TTL_SECONDS', 3600),
];
