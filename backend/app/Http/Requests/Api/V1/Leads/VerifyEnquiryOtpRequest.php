<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Leads;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyEnquiryOtpRequest extends FormRequest
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
            'otp' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
