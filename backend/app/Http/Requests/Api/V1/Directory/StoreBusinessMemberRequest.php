<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Directory;

use App\Domains\Directory\Enums\BusinessMemberRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreBusinessMemberRequest extends FormRequest
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
            'user_public_id' => ['required', 'string', 'size:26'],
            'role' => ['required', 'string', Rule::in(BusinessMemberRole::assignableStaffRoles())],
            'permissions' => ['sometimes', 'nullable', 'array', 'max:10'],
            'permissions.*' => ['string', Rule::in([
                'business.view',
                'profile.edit',
                'hours.edit',
                'services.edit',
                'leads.manage',
                'reviews.reply',
                'verification.submit',
            ])],
        ];
    }
}
