<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Domains\Location\Models\City;
use App\Domains\Location\Models\CityTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin City
 */
final class AdminCityResource extends JsonResource
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
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'timezone' => $this->timezone,
            'is_popular' => $this->is_popular,
            'sort_order' => $this->sort_order,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'is_active' => $this->is_active,
            'state' => $this->whenLoaded('state', fn (): ?array => $this->state ? [
                'public_id' => $this->state->public_id,
                'slug' => $this->state->slug,
                'name' => $this->state->name,
            ] : null),
            'localities_count' => $this->whenCounted('localities', (int) ($this->localities_count ?? 0)),
            'businesses_count' => $this->whenCounted('businesses', (int) ($this->businesses_count ?? 0)),
            'translations' => $this->whenLoaded('translations', fn (): array => $this->translations
                ->map(static fn (CityTranslation $t): array => [
                    'locale' => $t->locale,
                    'name' => $t->name,
                    'seo_title' => $t->seo_title,
                    'seo_description' => $t->seo_description,
                ])
                ->values()
                ->all()),
            'dependencies' => $this->when($this->dependencies !== null, $this->dependencies),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
