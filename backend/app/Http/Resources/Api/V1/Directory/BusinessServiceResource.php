<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Directory;

use App\Domains\Directory\Models\BusinessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BusinessService
 */
final class BusinessServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'description' => $this->description,
            'price_min_minor' => $this->price_min_minor,
            'price_max_minor' => $this->price_max_minor,
            'currency' => $this->currency,
            'price_unit' => $this->price_unit,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ];
    }
}
