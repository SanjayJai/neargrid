<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Leads;

use Illuminate\Foundation\Http\FormRequest;

final class CancelEnquiryRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:4', 'max:255'],
        ];
    }
}
