<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Reviews;

use Illuminate\Foundation\Http\FormRequest;

final class ModerateReviewRequest extends FormRequest
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
            'status' => ['required', 'string', 'in:approved,hidden,rejected,flagged,pending'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
