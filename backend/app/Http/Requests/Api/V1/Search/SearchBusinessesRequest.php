<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Search;

use App\Domains\Directory\Services\AttributeTypeRegistry;
use App\Domains\Search\DTOs\SearchQuery;
use App\Domains\Search\Enums\SearchSortOption;
use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\CategoryAttributeDefinition;
use App\Domains\Taxonomy\Models\CategoryFilterConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class SearchBusinessesRequest extends FormRequest
{
    /**
     * @var array<string, mixed>
     */
    private array $normalizedAttributeFilters = [];
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('verified_only')) {
            $merge['verified_only'] = filter_var($this->input('verified_only'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        if ($this->has('open_now')) {
            $merge['open_now'] = filter_var($this->input('open_now'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * Strict typed validation — rejects raw SQL, arbitrary sorting expressions, and invalid WGS-84 coordinates.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $minRadius = (float) config('search.geo.min_radius_km', 0.5);
        $maxRadius = (float) config('search.geo.max_radius_km', 50.0);
        $maxPerPage = (int) config('search.pagination.max_per_page', 40);
        $maxPage = (int) config('search.pagination.max_page', 250);

        return [
            'q' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'category' => ['nullable', 'string', 'max:140', 'regex:/^[a-z0-9-]+$/'],
            'locality' => ['nullable', 'string', 'max:140', 'regex:/^[a-z0-9-]+$/'],
            'localities' => ['nullable', 'array', 'max:10'],
            'localities.*' => ['string', 'max:140', 'regex:/^[a-z0-9-]+$/'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'radius_km' => ['nullable', 'numeric', "min:{$minRadius}", "max:{$maxRadius}"],
            'min_rating' => ['nullable', 'numeric', 'between:1,5'],
            'verified_only' => ['nullable', 'boolean'],
            'open_now' => ['nullable', 'boolean'],
            'amenities' => ['nullable', 'array', 'max:15'],
            'amenities.*' => ['string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'payment_methods' => ['nullable', 'array', 'max:10'],
            'payment_methods.*' => ['string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'sort' => ['nullable', 'string', Rule::in(SearchSortOption::values())],
            'page' => ['nullable', 'integer', 'min:1', "max:{$maxPage}"],
            'per_page' => ['nullable', 'integer', 'min:1', "max:{$maxPerPage}"],
            'locale' => ['nullable', 'string', Rule::in((array) config('directory.supported_locales', ['en', 'ta']))],
            'attr' => ['nullable', 'array', 'max:8'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $rawAttr = $this->input('attr');
            if (! is_array($rawAttr) || $rawAttr === []) {
                $this->normalizedAttributeFilters = [];
                return;
            }

            if (count($rawAttr) > 8) {
                $validator->errors()->add('attr', 'At most 8 attribute filters may be applied simultaneously.');
                return;
            }

            $categorySlug = $this->input('category');
            if (! is_string($categorySlug) || trim($categorySlug) === '') {
                $validator->errors()->add('attr', 'Category-specific attribute filters require a valid category.');
                return;
            }

            $category = Category::query()
                ->where('slug', trim($categorySlug))
                ->where('is_active', true)
                ->first();

            if ($category === null) {
                $validator->errors()->add('category', 'Selected category is invalid or inactive.');
                return;
            }

            $definitions = CategoryAttributeDefinition::query()
                ->where('category_id', $category->id)
                ->where('is_active', true)
                ->where('is_filterable', true)
                ->get()
                ->keyBy('attribute_key');

            $disabledFilterKeys = CategoryFilterConfig::query()
                ->where('category_id', $category->id)
                ->where('is_enabled', false)
                ->pluck('filter_key')
                ->flip();

            $registry = app(AttributeTypeRegistry::class);
            $normalized = [];

            foreach ($rawAttr as $key => $rawValue) {
                if (! is_string($key) || ! preg_match('/^[a-z0-9_]{1,80}$/', $key)) {
                    $validator->errors()->add('attr', 'Invalid attribute filter key.');
                    continue;
                }

                /** @var CategoryAttributeDefinition|null $def */
                $def = $definitions->get($key);
                if ($def === null || $disabledFilterKeys->has($key)) {
                    $validator->errors()->add("attr.{$key}", "Attribute filter '{$key}' is not available or not filterable for this category.");
                    continue;
                }

                switch ($def->data_type) {
                    case 'boolean':
                        $boolVal = filter_var($rawValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($boolVal === null) {
                            $validator->errors()->add("attr.{$key}", "Filter '{$key}' must be a boolean.");
                            break;
                        }
                        $normalized[$key] = ['type' => 'boolean', 'value' => $boolVal];
                        break;

                    case 'enum':
                        if (! is_string($rawValue) || trim($rawValue) === '' || mb_strlen($rawValue) > 100) {
                            $validator->errors()->add("attr.{$key}", "Filter '{$key}' must be a valid option string.");
                            break;
                        }
                        $trimmed = trim($rawValue);
                        $choices = $registry->extractChoices($def);
                        if ($choices !== [] && ! in_array($trimmed, $choices, true)) {
                            $validator->errors()->add("attr.{$key}", "Filter '{$key}' contains an invalid option '{$trimmed}'.");
                            break;
                        }
                        $normalized[$key] = ['type' => 'enum', 'value' => $trimmed];
                        break;

                    case 'multi_enum':
                        $items = is_array($rawValue)
                            ? $rawValue
                            : (is_string($rawValue) ? explode(',', $rawValue) : null);

                        if ($items === null || count($items) > 10) {
                            $validator->errors()->add("attr.{$key}", "Filter '{$key}' must contain between 1 and 10 options.");
                            break;
                        }

                        $choices = $registry->extractChoices($def);
                        $cleanItems = [];
                        $invalid = false;
                        foreach ($items as $item) {
                            if (! is_string($item) || trim($item) === '' || mb_strlen($item) > 100) {
                                $invalid = true;
                                break;
                            }
                            $trimmed = trim($item);
                            if ($choices !== [] && ! in_array($trimmed, $choices, true)) {
                                $invalid = true;
                                break;
                            }
                            $cleanItems[] = $trimmed;
                        }
                        $cleanItems = array_values(array_unique($cleanItems));
                        if ($invalid || $cleanItems === []) {
                            $validator->errors()->add("attr.{$key}", "Filter '{$key}' contains invalid selection options.");
                            break;
                        }
                        $normalized[$key] = ['type' => 'multi_enum', 'values' => $cleanItems];
                        break;

                    case 'number':
                        $minRaw = is_array($rawValue) ? ($rawValue['min'] ?? null) : $rawValue;
                        $maxRaw = is_array($rawValue) ? ($rawValue['max'] ?? null) : $rawValue;
                        try {
                            $bounds = $registry->validateRangeFilterBounds($minRaw, $maxRaw, "attr.{$key}.min", "attr.{$key}.max");
                        } catch (ValidationException $e) {
                            foreach ($e->errors() as $errKey => $messages) {
                                $validator->errors()->add($errKey, $messages[0] ?? 'Invalid range.');
                            }
                            break;
                        }
                        if ($bounds['min'] === null && $bounds['max'] === null) {
                            $validator->errors()->add("attr.{$key}", "Filter '{$key}' requires a min or max numeric value.");
                            break;
                        }
                        $defOpts = $def->options_json ?? [];
                        if (isset($defOpts['min']) && is_numeric($defOpts['min'])) {
                            $defMin = (float) $defOpts['min'];
                            if (($bounds['min'] !== null && $bounds['min'] < $defMin) || ($bounds['max'] !== null && $bounds['max'] < $defMin)) {
                                $validator->errors()->add("attr.{$key}", "Filter '{$key}' cannot be below {$defMin}.");
                                break;
                            }
                        }
                        if (isset($defOpts['max']) && is_numeric($defOpts['max'])) {
                            $defMax = (float) $defOpts['max'];
                            if (($bounds['min'] !== null && $bounds['min'] > $defMax) || ($bounds['max'] !== null && $bounds['max'] > $defMax)) {
                                $validator->errors()->add("attr.{$key}", "Filter '{$key}' cannot exceed {$defMax}.");
                                break;
                            }
                        }
                        $normalized[$key] = ['type' => 'number', 'min' => $bounds['min'], 'max' => $bounds['max']];
                        break;

                    case 'string':
                        if (! is_string($rawValue) || trim($rawValue) === '' || mb_strlen($rawValue) > 120) {
                            $validator->errors()->add("attr.{$key}", "Filter '{$key}' must be a non-empty string up to 120 characters.");
                            break;
                        }
                        $normalized[$key] = ['type' => 'string', 'value' => trim($rawValue)];
                        break;
                }
            }

            $this->normalizedAttributeFilters = $normalized;
        });
    }

    public function toSearchQuery(bool $isNearbyMode = false): SearchQuery
    {
        $v = $this->validated();

        $localities = [];
        if (isset($v['locality']) && is_string($v['locality']) && $v['locality'] !== '') {
            $localities[] = $v['locality'];
        }
        if (isset($v['localities']) && is_array($v['localities'])) {
            foreach ($v['localities'] as $slug) {
                if (is_string($slug) && $slug !== '') {
                    $localities[] = $slug;
                }
            }
        }
        $localities = array_values(array_unique($localities));

        $amenities = isset($v['amenities']) && is_array($v['amenities'])
            ? array_values(array_unique(array_map('strval', $v['amenities'])))
            : [];

        $paymentMethods = isset($v['payment_methods']) && is_array($v['payment_methods'])
            ? array_values(array_unique(array_map('strval', $v['payment_methods'])))
            : [];

        $defaultPerPage = (int) config('search.pagination.default_per_page', 20);

        return new SearchQuery(
            rawQuery: isset($v['q']) ? (string) $v['q'] : null,
            citySlug: isset($v['city']) ? (string) $v['city'] : null,
            categorySlug: isset($v['category']) ? (string) $v['category'] : null,
            localitySlugs: $localities,
            latitude: isset($v['lat']) ? (float) $v['lat'] : null,
            longitude: isset($v['lng']) ? (float) $v['lng'] : null,
            radiusKm: isset($v['radius_km']) ? (float) $v['radius_km'] : null,
            minRating: isset($v['min_rating']) ? (float) $v['min_rating'] : null,
            verifiedOnly: (bool) ($v['verified_only'] ?? false),
            openNow: (bool) ($v['open_now'] ?? false),
            amenitySlugs: $amenities,
            paymentMethodSlugs: $paymentMethods,
            sort: isset($v['sort']) ? SearchSortOption::from((string) $v['sort']) : SearchSortOption::Relevance,
            page: isset($v['page']) ? (int) $v['page'] : 1,
            perPage: isset($v['per_page']) ? (int) $v['per_page'] : $defaultPerPage,
            locale: isset($v['locale']) ? (string) $v['locale'] : (string) config('directory.default_locale', 'en'),
            isNearbyMode: $isNearbyMode,
            attributeFilters: $this->normalizedAttributeFilters,
        );
    }
}
