<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Directory;

use Illuminate\Foundation\Http\FormRequest;

final class ReplaceBusinessHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hours' => ['required', 'array', 'min:1', 'max:14'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'hours.*.slot_index' => ['sometimes', 'integer', 'between:1,2'],
            'hours.*.is_closed' => ['sometimes', 'boolean'],
            'hours.*.is_24_hours' => ['sometimes', 'boolean'],
            'hours.*.open_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'hours.*.close_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
        ];
    }
}
