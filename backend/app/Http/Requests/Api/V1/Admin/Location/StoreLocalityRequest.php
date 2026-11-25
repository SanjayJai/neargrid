<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Location;

use Illuminate\Foundation\Http\FormRequest;

final class StoreLocalityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'city_public_id' => ['required_without:city_slug', 'string', 'size:26'],
            'city_slug' => ['required_without:city_public_id', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['sometimes', 'string', 'max:140', 'regex:/^[a-z0-9-]+$/'],
            'pincode' => ['sometimes', 'nullable', 'string', 'max:12'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'is_popular' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'translations' => ['sometimes', 'array'],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'max:140'],
        ];
    }
}
