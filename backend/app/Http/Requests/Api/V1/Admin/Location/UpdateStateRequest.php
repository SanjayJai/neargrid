<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Location;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateStateRequest extends FormRequest
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
            'country_code' => ['sometimes', 'string', 'size:2'],
            'name' => ['sometimes', 'string', 'max:100'],
            'slug' => ['sometimes', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'translations' => ['sometimes', 'array'],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'max:140'],
        ];
    }
}
