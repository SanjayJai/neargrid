<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Media;

use App\Domains\Media\Enums\BusinessMediaType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UploadBusinessMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * File content, size, magic-byte signature, and image integrity are validated
     * strictly by FileSignatureValidator rather than client-provided MIME/extension rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'media_type' => ['required', 'string', Rule::in(array_map(static fn (BusinessMediaType $t): string => $t->value, BusinessMediaType::cases()))],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:500'],
        ];
    }
}
