<?php

declare(strict_types=1);

namespace App\Domains\Search\DTOs;

use App\Domains\Directory\Models\Business;

final readonly class SearchHit
{
    public function __construct(
        public Business $business,
        public ?float $distanceKm,
        public bool $isSponsored,
        public ?string $sponsorDisclosure,
        public float $relevanceScore,
    ) {}
}
