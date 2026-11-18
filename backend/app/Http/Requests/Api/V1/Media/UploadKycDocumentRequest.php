<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Media;

use App\Domains\Directory\Enums\VerificationDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UploadKycDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * File content, size, magic-byte signature, and PDF/image structure are validated
     * strictly by FileSignatureValidator.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'document_type' => ['required', 'string', Rule::in(array_map(static fn (VerificationDocumentType $t): string => $t->value, VerificationDocumentType::cases()))],
            'document_number' => ['nullable', 'string', 'min:4', 'max:64'],
        ];
    }
}
