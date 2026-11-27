<?php

declare(strict_types=1);

namespace App\Domains\Directory\Services;

use App\Domains\Directory\Jobs\DispatchRevalidationWebhookJob;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessAttributeValue;
use App\Domains\Governance\Services\AuditLogger;
use App\Domains\Taxonomy\Models\CategoryAttributeDefinition;
use App\Domains\Taxonomy\Models\CategoryProfileSection;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BusinessAttributeService
{
    public function __construct(
        private readonly AttributeValueValidator $validator,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Resolve all allowed category IDs using the business's existing
     * primaryCategory and categories() relationships.
     *
     * @return list<int>
     */
    public function allowedCategoryIds(Business $business): array
    {
        $ids = [];

        if ($business->primary_category_id !== null) {
            $ids[] = (int) $business->primary_category_id;
        }

        $attachedIds = $business->relationLoaded('categories')
            ? $business->categories->pluck('id')->all()
            : $business->categories()->pluck('categories.id')->all();

        foreach ($attachedIds as $id) {
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Return active attribute definitions, sections, current values,
     * missing required keys, and any orphaned values for the owner UI/API.
     *
     * @return array<string, mixed>
     */
    public function getOwnerAttributes(Business $business): array
    {
        $allowedCategoryIds = $this->allowedCategoryIds($business);

        $definitions = $allowedCategoryIds === []
            ? collect()
            : CategoryAttributeDefinition::query()
                ->whereIn('category_id', $allowedCategoryIds)
                ->where('is_active', true)
                ->with('category')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

        $sections = $allowedCategoryIds === []
            ? collect()
            : CategoryProfileSection::query()
                ->whereIn('category_id', $allowedCategoryIds)
                ->where('is_enabled', true)
                ->with('category')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

        $allValues = BusinessAttributeValue::query()
            ->where('business_id', $business->id)
            ->with(['definition.category'])
            ->get();

        $activeDefIds = $definitions->pluck('id')->flip();
        $activeValuesByDefId = [];
        $orphanedValues = [];

        foreach ($allValues as $val) {
            $def = $val->definition;
            if ($def !== null && $activeDefIds->has($def->id)) {
                $activeValuesByDefId[$def->id] = $val;
            } else {
                $reason = $def === null || ! $def->is_active ? 'definition_inactive' : 'category_changed';
                $orphanedValues[] = [
                    'public_id' => $val->public_id,
                    'attribute_definition_public_id' => $def?->public_id,
                    'attribute_key' => $def?->attribute_key,
                    'label_en' => $def?->label_en,
                    'label_ta' => $def?->label_ta,
                    'data_type' => $def?->data_type,
                    'unit' => $def?->unit,
                    'category_slug' => $def?->category?->slug,
                    'category_name' => $def?->category?->name,
                    'reason' => $reason,
                    'value' => $val->typedValue(),
                    'updated_at' => $val->updated_at?->utc()->toIso8601ZuluString(),
                ];
            }
        }

        $missingRequired = [];
        $formattedAttributes = [];

        foreach ($definitions as $def) {
            $val = $activeValuesByDefId[$def->id] ?? null;
            $typed = $val?->typedValue();
            if ($def->is_required && $typed === null) {
                $missingRequired[] = [
                    'attribute_key' => $def->attribute_key,
                    'label_en' => $def->label_en,
                    'category_slug' => $def->category?->slug,
                ];
            }

            $formattedAttributes[] = [
                'definition_public_id' => $def->public_id,
                'attribute_key' => $def->attribute_key,
                'section_key' => $def->section_key,
                'label_en' => $def->label_en,
                'label_ta' => $def->label_ta,
                'data_type' => $def->data_type,
                'options_json' => $def->options_json,
                'unit' => $def->unit,
                'is_filterable' => $def->is_filterable,
                'is_required' => $def->is_required,
                'show_on_profile' => $def->show_on_profile,
                'sort_order' => $def->sort_order,
                'category_public_id' => $def->category?->public_id,
                'category_slug' => $def->category?->slug,
                'value_public_id' => $val?->public_id,
                'value' => $typed,
                'updated_at' => $val?->updated_at?->utc()->toIso8601ZuluString(),
            ];
        }

        return [
            'business_public_id' => $business->public_id,
            'sections' => $sections->map(static fn (CategoryProfileSection $sec): array => [
                'section_key' => $sec->section_key,
                'display_label_en' => $sec->display_label_en,
                'display_label_ta' => $sec->display_label_ta,
                'is_required' => $sec->is_required,
                'suppress_when_empty' => $sec->suppress_when_empty,
                'sort_order' => $sec->sort_order,
                'category_public_id' => $sec->category?->public_id,
                'category_slug' => $sec->category?->slug,
            ])->values()->all(),
            'attributes' => $formattedAttributes,
            'orphaned_values' => $orphanedValues,
            'missing_required' => $missingRequired,
            'is_complete_for_publish' => $missingRequired === [],
        ];
    }

    /**
     * Partially upsert or clear owner attribute values.
     *
     * @param  list<array{attribute_key: string, value: mixed, definition_public_id?: ?string}>  $items
     * @return array<string, mixed>
     */
    public function upsertOwnerAttributes(
        User $actor,
        Business $business,
        array $items,
        bool $validateRequiredForPublish = false,
        ?string $ipAddress = null,
    ): array {
        $allowedCategoryIds = $this->allowedCategoryIds($business);

        /** @var Collection<int, CategoryAttributeDefinition> $allowedDefs */
        $allowedDefs = $allowedCategoryIds === []
            ? collect()
            : CategoryAttributeDefinition::query()
                ->whereIn('category_id', $allowedCategoryIds)
                ->where('is_active', true)
                ->get();

        $resolvedOps = [];
        foreach ($items as $idx => $item) {
            $errorKey = "attributes.{$idx}.value";
            $definition = $this->resolveAllowedDefinition($business, $allowedDefs, $item, $idx);

            if ($item['value'] === null) {
                $resolvedOps[] = [
                    'definition' => $definition,
                    'delete' => true,
                    'columns' => null,
                ];
            } else {
                $columns = $this->validator->validateAndNormalize($definition, $item['value'], $errorKey);
                $resolvedOps[] = [
                    'definition' => $definition,
                    'delete' => false,
                    'columns' => $columns,
                ];
            }
        }

        DB::transaction(function () use ($actor, $business, $resolvedOps, $validateRequiredForPublish, $ipAddress): void {
            $changedKeys = [];
            $oldSnapshot = [];
            $newSnapshot = [];

            foreach ($resolvedOps as $op) {
                /** @var CategoryAttributeDefinition $def */
                $def = $op['definition'];
                $existing = BusinessAttributeValue::query()
                    ->where('business_id', $business->id)
                    ->where('attribute_definition_id', $def->id)
                    ->first();

                if ($existing !== null) {
                    $existing->setRelation('definition', $def);
                    $oldSnapshot[$def->attribute_key] = $existing->typedValue();
                } else {
                    $oldSnapshot[$def->attribute_key] = null;
                }

                if ($op['delete']) {
                    if ($existing !== null) {
                        $existing->delete();
                        $changedKeys[] = $def->attribute_key;
                    }
                    $newSnapshot[$def->attribute_key] = null;
                } else {
                    /** @var array<string, mixed> $columns */
                    $columns = $op['columns'];
                    $saved = BusinessAttributeValue::query()->updateOrCreate(
                        [
                            'business_id' => $business->id,
                            'attribute_definition_id' => $def->id,
                        ],
                        $columns,
                    );
                    $saved->setRelation('definition', $def);
                    $newSnapshot[$def->attribute_key] = $saved->typedValue();
                    $changedKeys[] = $def->attribute_key;
                }
            }

            if ($validateRequiredForPublish) {
                $this->validateRequiredAttributesForPublish($business);
            }

            $this->auditLogger->record(
                actor: $actor,
                action: 'business.attributes_updated',
                auditable: $business,
                oldValues: $oldSnapshot,
                newValues: [
                    'changed_keys' => array_values(array_unique($changedKeys)),
                    'values' => $newSnapshot,
                ],
                ipAddress: $ipAddress,
            );
        });

        $this->dispatchBusinessRevalidation($business, 'business_attributes_updated');

        return $this->getOwnerAttributes($business);
    }

    /**
     * Explicitly validate all required active attributes for the business's
     * allowed categories before completion/publish.
     */
    public function validateRequiredAttributesForPublish(Business $business): void
    {
        $allowedCategoryIds = $this->allowedCategoryIds($business);
        if ($allowedCategoryIds === []) {
            return;
        }

        $requiredDefs = CategoryAttributeDefinition::query()
            ->whereIn('category_id', $allowedCategoryIds)
            ->where('is_active', true)
            ->where('is_required', true)
            ->orderBy('sort_order')
            ->get();

        if ($requiredDefs->isEmpty()) {
            return;
        }

        $presentDefIds = BusinessAttributeValue::query()
            ->where('business_id', $business->id)
            ->whereIn('attribute_definition_id', $requiredDefs->pluck('id')->all())
            ->pluck('attribute_definition_id')
            ->map(static fn ($id): int => (int) $id)
            ->flip();

        $errors = [];
        foreach ($requiredDefs as $def) {
            if (! $presentDefIds->has($def->id)) {
                $errors["required_attributes.{$def->attribute_key}"] = [
                    "Required attribute '{$def->label_en}' ({$def->attribute_key}) must be provided before publishing.",
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Delete orphaned attribute values belonging to the business.
     *
     * @param  list<string>  $valuePublicIds  Empty deletes all orphaned values for this business.
     */
    public function deleteOrphanedAttributes(
        User $actor,
        Business $business,
        array $valuePublicIds = [],
        ?string $ipAddress = null,
    ): int {
        $allowedCategoryIds = $this->allowedCategoryIds($business);
        $activeDefIds = $allowedCategoryIds === []
            ? []
            : CategoryAttributeDefinition::query()
                ->whereIn('category_id', $allowedCategoryIds)
                ->where('is_active', true)
                ->pluck('id')
                ->all();

        $query = BusinessAttributeValue::query()
            ->where('business_id', $business->id)
            ->when($activeDefIds !== [], static fn ($q) => $q->whereNotIn('attribute_definition_id', $activeDefIds));

        if ($valuePublicIds !== []) {
            $query->whereIn('public_id', $valuePublicIds);
        }

        $orphans = $query->with('definition')->get();
        if ($orphans->isEmpty()) {
            return 0;
        }

        $deletedKeys = $orphans->map(static fn (BusinessAttributeValue $v): string => $v->definition?->attribute_key ?? $v->public_id)->values()->all();

        DB::transaction(function () use ($actor, $business, $orphans, $deletedKeys, $ipAddress): void {
            BusinessAttributeValue::query()
                ->whereIn('id', $orphans->pluck('id')->all())
                ->delete();

            $this->auditLogger->record(
                actor: $actor,
                action: 'business.orphaned_attributes_cleared',
                auditable: $business,
                oldValues: ['cleared_keys' => $deletedKeys],
                newValues: ['cleared_count' => count($deletedKeys)],
                ipAddress: $ipAddress,
            );
        });

        return count($deletedKeys);
    }

    /**
     * @param  Collection<int, CategoryAttributeDefinition>  $allowedDefs
     * @param  array<string, mixed>  $item
     */
    private function resolveAllowedDefinition(
        Business $business,
        Collection $allowedDefs,
        array $item,
        int $idx,
    ): CategoryAttributeDefinition {
        $defPublicId = isset($item['definition_public_id']) && is_string($item['definition_public_id'])
            ? trim($item['definition_public_id'])
            : null;

        if ($defPublicId !== null && $defPublicId !== '') {
            $match = $allowedDefs->firstWhere('public_id', $defPublicId);
            if ($match instanceof CategoryAttributeDefinition) {
                return $match;
            }

            throw ValidationException::withMessages([
                "attributes.{$idx}.definition_public_id" => ['Attribute definition is inactive or does not belong to an allowed category of this business.'],
            ]);
        }

        $key = trim((string) ($item['attribute_key'] ?? ''));
        $matches = $allowedDefs->where('attribute_key', $key);

        if ($matches->isEmpty()) {
            throw ValidationException::withMessages([
                "attributes.{$idx}.attribute_key" => ["Attribute '{$key}' is not an active attribute for any category assigned to this business."],
            ]);
        }

        // Prefer primary category definition when multiple assigned categories share an attribute_key.
        $primaryMatch = $matches->firstWhere('category_id', $business->primary_category_id);

        return $primaryMatch instanceof CategoryAttributeDefinition
            ? $primaryMatch
            : $matches->firstOrFail();
    }

    private function dispatchBusinessRevalidation(Business $business, string $reason): void
    {
        if (! $business->slug) {
            return;
        }

        $business->loadMissing(['city', 'primaryCategory']);
        $citySlug = $business->city?->slug ?? 'chennai';
        $paths = ["/{$citySlug}/business/{$business->slug}", "/{$citySlug}"];
        if ($business->primaryCategory?->slug) {
            $paths[] = "/{$citySlug}/{$business->primaryCategory->slug}";
        }

        DispatchRevalidationWebhookJob::dispatch($paths, $reason)->afterCommit();
    }
}
