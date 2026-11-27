<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Directory;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateBusinessAttributesRequest extends FormRequest
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
            'validate_required' => ['sometimes', 'boolean'],
            'attributes' => ['required', 'array', 'max:100'],
            'attributes.*.attribute_key' => ['required_without:attributes.*.definition_public_id', 'string', 'max:80'],
            'attributes.*.definition_public_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'attributes.*.value' => ['present'],
        ];
    }
}
