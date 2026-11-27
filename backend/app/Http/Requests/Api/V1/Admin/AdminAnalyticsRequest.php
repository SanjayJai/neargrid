<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class AdminAnalyticsRequest extends FormRequest
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
            'range' => ['sometimes', 'string', Rule::in(['7d', '30d', '90d', 'custom'])],
            'from' => ['required_if:range,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:range,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'city_slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', 'exists:cities,slug'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('range') === 'custom') {
                $from = CarbonImmutable::parse((string) $this->input('from'), 'UTC');
                $to = CarbonImmutable::parse((string) $this->input('to'), 'UTC');
                if ($from->diffInDays($to) > 365) {
                    $validator->errors()->add('to', 'Custom analytics date range cannot exceed 365 days.');
                }
            }
        });
    }
}
