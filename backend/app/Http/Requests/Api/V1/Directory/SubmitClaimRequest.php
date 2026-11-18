<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Directory;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitClaimRequest extends FormRequest
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
            'proof_file_path_private' => ['required', 'string', 'min:5', 'max:500'],
            'claim_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
