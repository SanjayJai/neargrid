<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Domains\Location\Models\State;
use App\Domains\Location\Models\StateTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin State
 */
final class AdminStateResource extends JsonResource
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
            'country_code' => $this->country_code,
            'name' => $this->name,
            'slug' => $this->slug,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'cities_count' => $this->whenCounted('cities', (int) ($this->cities_count ?? 0)),
            'translations' => $this->whenLoaded('translations', fn (): array => $this->translations
                ->map(static fn (StateTranslation $t): array => [
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
