<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Leads;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateOwnerLeadRequest extends FormRequest
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
            'lead_status' => ['sometimes', 'string', 'in:new,contacted,converted,archived'],
            'assigned_user_public_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'merchant_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_read' => ['sometimes', 'boolean'],
        ];
    }
}
