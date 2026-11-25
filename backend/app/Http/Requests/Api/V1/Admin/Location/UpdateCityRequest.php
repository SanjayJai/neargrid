<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Location;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCityRequest extends FormRequest
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
            'state_public_id' => ['sometimes', 'string', 'size:26'],
            'state_slug' => ['sometimes', 'string', 'max:120'],
            'name' => ['sometimes', 'string', 'max:100'],
            'slug' => ['sometimes', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'is_popular' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'is_active' => ['sometimes', 'boolean'],
            'translations' => ['sometimes', 'array'],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'max:120'],
            'translations.*.seo_title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'translations.*.seo_description' => ['sometimes', 'nullable', 'string', 'max:320'],
        ];
    }
}
