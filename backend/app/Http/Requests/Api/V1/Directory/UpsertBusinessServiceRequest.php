<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Directory;

use Illuminate\Foundation\Http\FormRequest;

final class UpsertBusinessServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'price_min_minor' => ['nullable', 'integer', 'min:0'],
            'price_max_minor' => ['nullable', 'integer', 'min:0', 'gte:price_min_minor'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'price_unit' => ['nullable', 'string', 'max:40'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
