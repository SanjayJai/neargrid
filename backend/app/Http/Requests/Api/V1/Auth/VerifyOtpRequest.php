<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Domains\Identity\Enums\OtpPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class VerifyOtpRequest extends FormRequest
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
            'challenge_id' => ['required', 'string', 'size:26'],
            'phone_e164' => ['required', 'string', 'max:25'],
            'purpose' => ['required', 'string', Rule::in(OtpPurpose::authAllowedValues())],
            'otp' => ['required', 'digits:6'],
            'name' => ['nullable', 'string', 'min:2', 'max:120'],
            'device_name' => ['nullable', 'string', 'min:2', 'max:120'],
            'platform' => ['nullable', 'string', Rule::in(['web', 'android', 'ios']), 'required_with:device_identifier'],
            'device_identifier' => ['nullable', 'string', 'min:4', 'max:191', 'required_with:platform'],
            'fcm_token' => ['nullable', 'string', 'max:512'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ];
    }
}
