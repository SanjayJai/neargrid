<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Search;

use App\Domains\Search\DTOs\SuggestionQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SearchSuggestionsRequest extends FormRequest
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
        $maxLen = (int) config('search.autocomplete.max_query_length', 80);

        return [
            'q' => ['required', 'string', "max:{$maxLen}"],
            'city' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'locale' => ['nullable', 'string', Rule::in((array) config('directory.supported_locales', ['en', 'ta']))],
        ];
    }

    public function toSuggestionQuery(): SuggestionQuery
    {
        $v = $this->validated();

        return new SuggestionQuery(
            rawQuery: (string) $v['q'],
            citySlug: isset($v['city']) ? (string) $v['city'] : null,
            locale: isset($v['locale']) ? (string) $v['locale'] : (string) config('directory.default_locale', 'en'),
        );
    }
}
