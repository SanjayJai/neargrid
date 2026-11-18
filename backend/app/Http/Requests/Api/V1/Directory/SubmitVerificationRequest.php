<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Directory;

use App\Domains\Directory\Enums\VerificationDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SubmitVerificationRequest extends FormRequest
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
            'document_type' => ['required', 'string', Rule::in(VerificationDocumentType::values())],
            'document_number' => ['required', 'string', 'min:4', 'max:80'],
            'private_file_path' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
