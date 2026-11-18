<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Directory;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateBusinessRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'min:2', 'max:180'],
            'slug' => ['sometimes', 'string', 'min:2', 'max:200'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'search_keywords' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'year_established' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:'.((int) date('Y'))],
            'primary_category_slug' => ['sometimes', 'string', 'max:140'],
            'secondary_category_slugs' => ['sometimes', 'array', 'max:5'],
            'secondary_category_slugs.*' => ['string', 'max:140'],
            'city_slug' => ['sometimes', 'string', 'max:120'],
            'locality_slug' => ['sometimes', 'string', 'max:140'],
            'address_line_1' => ['sometimes', 'string', 'min:4', 'max:255'],
            'address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'landmark' => ['sometimes', 'nullable', 'string', 'max:180'],
            'pincode' => ['sometimes', 'string', 'min:4', 'max:12'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'primary_phone_e164' => ['sometimes', 'string', 'max:25'],
            'secondary_phone_e164' => ['sometimes', 'nullable', 'string', 'max:25'],
            'whatsapp_e164' => ['sometimes', 'nullable', 'string', 'max:25'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'website_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'amenity_slugs' => ['sometimes', 'array', 'max:20'],
            'amenity_slugs.*' => ['string', 'max:100'],
            'payment_method_slugs' => ['sometimes', 'array', 'max:10'],
            'payment_method_slugs.*' => ['string', 'max:80'],
        ];
    }
}
