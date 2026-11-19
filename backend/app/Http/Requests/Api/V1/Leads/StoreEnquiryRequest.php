<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Leads;

use Illuminate\Foundation\Http\FormRequest;

final class StoreEnquiryRequest extends FormRequest
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
            'customer_name' => ['required', 'string', 'min:2', 'max:120'],
            'phone_e164' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:191'],
            'requirement' => ['required', 'string', 'min:10', 'max:2000'],
            'urgency' => ['sometimes', 'string', 'in:immediate,within_week,flexible'],
            'category_slug' => ['required', 'string', 'max:120'],
            'city_slug' => ['required', 'string', 'max:120'],
            'locality_slug' => ['nullable', 'string', 'max:120'],
            'target_business_slug' => ['nullable', 'string', 'max:220'],
            'consent_given' => ['required', 'accepted'],
            'consent_version' => ['required', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'consent_given.accepted' => 'You must affirmatively accept the enquiry data sharing consent notice.',
            'consent_given.required' => 'You must affirmatively accept the enquiry data sharing consent notice.',
            'requirement.min' => 'Please provide at least 10 characters describing your requirement.',
        ];
    }
}
