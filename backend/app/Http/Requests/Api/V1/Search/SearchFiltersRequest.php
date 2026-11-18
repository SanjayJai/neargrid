<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Search;

use App\Domains\Search\DTOs\FilterQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SearchFiltersRequest extends FormRequest
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
            'city' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'category' => ['nullable', 'string', 'max:140', 'regex:/^[a-z0-9-]+$/'],
            'locale' => ['nullable', 'string', Rule::in((array) config('directory.supported_locales', ['en', 'ta']))],
        ];
    }

    public function toFilterQuery(): FilterQuery
    {
        $v = $this->validated();

        return new FilterQuery(
            citySlug: isset($v['city']) ? (string) $v['city'] : null,
            categorySlug: isset($v['category']) ? (string) $v['category'] : null,
            locale: isset($v['locale']) ? (string) $v['locale'] : (string) config('directory.default_locale', 'en'),
        );
    }
}
