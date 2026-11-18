<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Directory;

use Illuminate\Foundation\Http\FormRequest;

final class StoreBusinessRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:180'],
            'tagline' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'search_keywords' => ['nullable', 'string', 'max:1000'],
            'year_established' => ['nullable', 'integer', 'min:1900', 'max:'.((int) date('Y'))],
            'primary_category_slug' => ['required', 'string', 'max:140'],
            'secondary_category_slugs' => ['sometimes', 'array', 'max:5'],
            'secondary_category_slugs.*' => ['string', 'max:140'],
            'city_slug' => ['required', 'string', 'max:120'],
            'locality_slug' => ['required', 'string', 'max:140'],
            'address_line_1' => ['required', 'string', 'min:4', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:180'],
            'pincode' => ['required', 'string', 'min:4', 'max:12'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'primary_phone_e164' => ['required', 'string', 'max:25'],
            'secondary_phone_e164' => ['nullable', 'string', 'max:25'],
            'whatsapp_e164' => ['nullable', 'string', 'max:25'],
            'email' => ['nullable', 'email', 'max:191'],
            'website_url' => ['nullable', 'url', 'max:255'],
            'amenity_slugs' => ['sometimes', 'array', 'max:20'],
            'amenity_slugs.*' => ['string', 'max:100'],
            'payment_method_slugs' => ['sometimes', 'array', 'max:10'],
            'payment_method_slugs.*' => ['string', 'max:80'],
        ];
    }
}
