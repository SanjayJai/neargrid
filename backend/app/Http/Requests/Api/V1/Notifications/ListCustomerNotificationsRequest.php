<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Notifications;

use App\Domains\Notifications\Enums\NotificationCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListCustomerNotificationsRequest extends FormRequest
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
            'status' => ['sometimes', 'string', 'in:all,unread'],
            'category' => ['sometimes', 'string', Rule::in(array_merge(['all'], NotificationCategory::values()))],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
