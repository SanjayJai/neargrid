<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class ReviewVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:approved,rejected'],
            'rejection_reason' => ['nullable', 'string', 'max:1000', 'required_if:decision,rejected'],
            'grant_verified_badge' => ['sometimes', 'boolean'],
            'grant_trust_badge' => ['sometimes', 'boolean'],
        ];
    }
}
