<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Domains\Location\Models\Locality;
use App\Domains\Location\Models\LocalityTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Locality
 */
final class AdminLocalityResource extends JsonResource
{
    /**
     * @param  array<string, int>|null  $dependencies
     */
    public function __construct(
        mixed $resource,
        private readonly ?array $dependencies = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'pincode' => $this->pincode,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'is_popular' => $this->is_popular,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'city' => $this->whenLoaded('city', fn (): ?array => $this->city ? [
                'public_id' => $this->city->public_id,
                'slug' => $this->city->slug,
                'name' => $this->city->name,
                'state' => $this->city->relationLoaded('state') && $this->city->state ? [
                    'public_id' => $this->city->state->public_id,
                    'slug' => $this->city->state->slug,
                    'name' => $this->city->state->name,
                ] : null,
            ] : null),
            'businesses_count' => $this->whenCounted('businesses', (int) ($this->businesses_count ?? 0)),
            'translations' => $this->whenLoaded('translations', fn (): array => $this->translations
                ->map(static fn (LocalityTranslation $t): array => [
                    'locale' => $t->locale,
                    'name' => $t->name,
                ])
                ->values()
                ->all()),
            'dependencies' => $this->when($this->dependencies !== null, $this->dependencies),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
