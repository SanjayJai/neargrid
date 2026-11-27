<?php

declare(strict_types=1);

namespace App\Domains\Directory\Services;

use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessAttributeValue;
use App\Domains\Media\Models\BusinessMedia;
use App\Domains\Taxonomy\Models\CategoryAttributeDefinition;
use App\Domains\Taxonomy\Models\CategoryProfileSection;

final class ProfileSectionResolverService
{
    public function __construct(
        private readonly BusinessAttributeService $attributeService,
    ) {}

    /**
     * Resolve public profile sections and visible attributes for a business.
     * Preserves default storefront behavior (empty profile_sections) when no
     * category_profile_sections are configured.
     *
     * @return array{
     *     profile_sections: list<array<string, mixed>>,
     *     attributes: list<array<string, mixed>>,
     * }
     */
    public function resolve(Business $business, string $locale = 'en'): array
    {
        $publicAttributes = $this->resolvePublicAttributes($business, $locale);
        $profileSections = $this->resolveProfileSections($business, $publicAttributes, $locale);

        return [
            'profile_sections' => $profileSections,
            'attributes' => $publicAttributes,
        ];
    }

    /**
     * Expose only active definitions with show_on_profile = true belonging to
     * the business's primary or permitted secondary categories.
     *
     * @return list<array<string, mixed>>
     */
    public function resolvePublicAttributes(Business $business, string $locale = 'en'): array
    {
        $allowedCategoryIds = $this->attributeService->allowedCategoryIds($business);
        if ($allowedCategoryIds === []) {
            return [];
        }

        $definitions = CategoryAttributeDefinition::query()
            ->whereIn('category_id', $allowedCategoryIds)
            ->where('is_active', true)
            ->where('show_on_profile', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        if ($definitions->isEmpty()) {
            return [];
        }

        $values = $business->relationLoaded('attributeValues')
            ? $business->attributeValues->whereIn('attribute_definition_id', $definitions->keys()->all())
            : BusinessAttributeValue::query()
                ->where('business_id', $business->id)
                ->whereIn('attribute_definition_id', $definitions->keys()->all())
                ->get();

        $valuesByDefId = $values->keyBy('attribute_definition_id');
        $resolved = [];

        foreach ($definitions as $def) {
            /** @var BusinessAttributeValue|null $val */
            $val = $valuesByDefId->get($def->id);
            if ($val === null) {
                continue;
            }

            $val->setRelation('definition', $def);
            $typed = $val->typedValue();
            if ($typed === null) {
                continue;
            }

            $label = $locale === 'ta' && is_string($def->label_ta) && trim($def->label_ta) !== ''
                ? $def->label_ta
                : $def->label_en;

            $resolved[] = [
                'definition_public_id' => $def->public_id,
                'attribute_key' => $def->attribute_key,
                'section_key' => $def->section_key,
                'label' => $label,
                'label_en' => $def->label_en,
                'label_ta' => $def->label_ta,
                'data_type' => $def->data_type,
                'unit' => $def->unit,
                'value' => $typed,
                'formatted_value' => $this->formatDisplayValue($def, $typed),
                'sort_order' => $def->sort_order,
            ];
        }

        return $resolved;
    }

    /**
     * @param  list<array<string, mixed>>  $publicAttributes
     * @return list<array<string, mixed>>
     */
    private function resolveProfileSections(Business $business, array $publicAttributes, string $locale): array
    {
        $allowedCategoryIds = $this->attributeService->allowedCategoryIds($business);
        if ($allowedCategoryIds === []) {
            return [];
        }

        $sections = CategoryProfileSection::query()
            ->whereIn('category_id', $allowedCategoryIds)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($sections->isEmpty()) {
            return [];
        }

        // Deduplicate by section_key, prioritizing primary_category_id first, then sort_order.
        $primaryCategoryId = (int) ($business->primary_category_id ?? 0);
        $sorted = $sections->sortBy(static fn (CategoryProfileSection $sec): array => [
            $sec->sort_order,
            (int) $sec->category_id === $primaryCategoryId ? 0 : 1,
            $sec->id,
        ]);

        $deduped = [];
        foreach ($sorted as $sec) {
            if (! array_key_exists($sec->section_key, $deduped)) {
                $deduped[$sec->section_key] = $sec;
            }
        }

        $resolved = [];
        foreach ($deduped as $sec) {
            if (! $sec->is_enabled) {
                continue;
            }

            $sectionAttributes = array_values(array_filter(
                $publicAttributes,
                static fn (array $attr): bool => $attr['section_key'] === $sec->section_key
                    || ($sec->section_key === 'attributes' && $attr['section_key'] === null)
            ));

            $hasContent = $this->hasContentForSection($business, $sec->section_key, $sectionAttributes);
            if ($sec->suppress_when_empty && ! $hasContent) {
                continue;
            }

            $label = $locale === 'ta' && is_string($sec->display_label_ta) && trim($sec->display_label_ta) !== ''
                ? $sec->display_label_ta
                : $sec->display_label_en;

            $resolved[] = [
                'section_key' => $sec->section_key,
                'label' => $label,
                'display_label_en' => $sec->display_label_en,
                'display_label_ta' => $sec->display_label_ta,
                'is_required' => $sec->is_required,
                'suppress_when_empty' => $sec->suppress_when_empty,
                'sort_order' => $sec->sort_order,
                'has_content' => $hasContent,
                'attributes' => $sectionAttributes,
            ];
        }

        return $resolved;
    }

    /**
     * @param  list<array<string, mixed>>  $sectionAttributes
     */
    private function hasContentForSection(Business $business, string $sectionKey, array $sectionAttributes): bool
    {
        if ($sectionAttributes !== []) {
            return true;
        }

        return match ($sectionKey) {
            'overview' => ($business->description !== null && trim($business->description) !== '')
                || $business->categories->isNotEmpty()
                || $business->amenities->isNotEmpty()
                || $business->paymentMethods->isNotEmpty(),
            'services' => $business->services->where('is_active', true)->isNotEmpty(),
            'photos', 'gallery' => $business->media
                ->filter(static fn (BusinessMedia $item): bool => $item->isPubliclyDeliverable())
                ->isNotEmpty(),
            'hours' => $business->hours->isNotEmpty(),
            'faqs' => $business->faqs->where('is_active', true)->isNotEmpty(),
            'reviews' => (int) $business->review_count > 0,
            default => false,
        };
    }

    /**
     * @param  string|float|bool|array<int, string>  $typed
     */
    private function formatDisplayValue(CategoryAttributeDefinition $def, string|float|bool|array $typed): string
    {
        if (is_bool($typed)) {
            return $typed ? 'Yes' : 'No';
        }

        if (is_array($typed)) {
            return implode(', ', $typed);
        }

        if (is_float($typed)) {
            $formatted = floor($typed) === $typed ? (string) ((int) $typed) : rtrim(rtrim(number_format($typed, 2, '.', ''), '0'), '.');

            return $def->unit ? "{$formatted} {$def->unit}" : $formatted;
        }

        return $def->unit ? "{$typed} {$def->unit}" : $typed;
    }
}
