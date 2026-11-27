<?php

declare(strict_types=1);

namespace App\Domains\Promotions\DTOs;

final readonly class PromoNoticeDeliveryContext
{
    /**
     * @param  list<int>  $categoryAncestorIds
     */
    public function __construct(
        public string $routePath = '/',
        public string $locale = 'en',
        public string $device = 'desktop',
        public string $audience = 'guest',
        public ?int $domainId = null,
        public ?int $stateId = null,
        public ?int $cityId = null,
        public ?int $localityId = null,
        public ?int $categoryId = null,
        public array $categoryAncestorIds = [],
        public ?int $businessId = null,
        public ?int $userId = null,
        public string $sessionIdentifier = '',
    ) {}
}
