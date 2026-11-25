<?php

declare(strict_types=1);

namespace App\Domains\Taxonomy\Services;

use App\Domains\Directory\Models\Business;
use App\Domains\Governance\Services\AuditLogger;
use App\Domains\Leads\Models\Enquiry;
use App\Domains\Seo\Services\RedirectLifecycleService;
use App\Domains\Taxonomy\Exceptions\CategoryException;
use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\CategoryAttributeDefinition;
use App\Domains\Taxonomy\Models\CategoryCollection;
use App\Domains\Taxonomy\Models\CategoryFilterConfig;
use App\Domains\Taxonomy\Models\CategoryProfileSection;
use App\Domains\Taxonomy\Models\CategoryRevision;
use App\Domains\Taxonomy\Models\CategoryTranslation;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CategoryManagementService
{
    public const NODE_TYPES = [
        'main_category',
        'category_group',
        'subcategory',
        'speciality',
        'sub_speciality',
    ];

    public const LIFECYCLE_STATUSES = [
        'draft',
        'review',
        'published',
        'archived',
    ];

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly RedirectLifecycleService $redirects,
        private readonly CategoryTreeService $treeService,
    ) {}

    public function maxDepth(): int
    {
        return max(1, (int) config('taxonomy.max_depth', 5));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCategory(array $data, User $actor, ?string $ipAddress = null): Category
    {
        $parent = $this->resolveParent($data['parent_id'] ?? null);
        $depth = $parent === null ? 0 : $parent->depth + 1;
        $this->assertDepthAllowed($depth);

        $nodeType = (string) ($data['node_type'] ?? ($parent === null ? 'main_category' : 'subcategory'));
        $this->assertValidNodeType($nodeType);

        $slug = Str::slug((string) ($data['slug'] ?? $data['name']));
        $this->assertSlugUnique($slug);

        $category = DB::transaction(function () use ($data, $parent, $depth, $nodeType, $slug, $actor, $ipAddress): Category {
            $category = Category::query()->create([
                'parent_id' => $parent?->id,
                'node_type' => $nodeType,
                'name' => trim((string) $data['name']),
                'slug' => $slug,
                'schema_org_type' => (string) ($data['schema_org_type'] ?? 'LocalBusiness'),
                'icon_name' => $data['icon_name'] ?? null,
                'image_url' => $data['image_url'] ?? null,
                'depth' => $depth,
                'lifecycle_status' => (string) ($data['lifecycle_status'] ?? 'draft'),
                'is_system' => (bool) ($data['is_system'] ?? false),
                'is_assignable' => array_key_exists('is_assignable', $data)
                    ? (bool) $data['is_assignable']
                    : ($nodeType !== 'category_group'),
                'has_landing_page' => (bool) ($data['has_landing_page'] ?? true),
                'is_featured' => (bool) ($data['is_featured'] ?? false),
                'show_on_homepage' => (bool) ($data['show_on_homepage'] ?? false),
                'is_popular' => (bool) ($data['is_popular'] ?? false),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            if (isset($data['translations']) && is_array($data['translations'])) {
                $this->syncTranslations($category, $data['translations']);
            }

            $isPublished = $category->lifecycle_status === 'published';
            $revision = $this->recordRevision(
                $category,
                $actor,
                'Initial creation',
                $isPublished ? 'published' : null,
                $isPublished ? $actor : null,
            );

            if ($isPublished) {
                $category->published_version_id = $revision->id;
                $category->save();
            }

            $this->auditLogger->record(
                actor: $actor,
                action: 'category.created',
                auditable: $category,
                oldValues: null,
                newValues: $revision->snapshot_json,
                ipAddress: $ipAddress,
                actorRole: 'super_admin',
            );

            return $category;
        });

        $this->treeService->invalidateCache();

        return $category->fresh(['translations', 'revisions']) ?? $category;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(Category $category, array $data, User $actor, ?string $ipAddress = null): Category
    {
        $oldPath = $this->treeService->canonicalPathFor($category);
        $oldSlug = $category->slug;
        $oldSnapshot = $this->snapshot($category);

        if (isset($data['slug'])) {
            $newSlug = Str::slug((string) $data['slug']);
            if ($newSlug !== $oldSlug) {
                if ($category->is_system) {
                    throw new CategoryException(
                        'SYSTEM_CATEGORY_PROTECTED',
                        'System categories cannot change their slug.',
                        Response::HTTP_UNPROCESSABLE_ENTITY,
                    );
                }
                $this->assertSlugUnique($newSlug, $category->id);
                $data['slug'] = $newSlug;
            }
        }

        if (isset($data['node_type'])) {
            $this->assertValidNodeType((string) $data['node_type']);
        }

        $updated = DB::transaction(function () use ($category, $data, $oldPath, $oldSlug, $oldSnapshot, $actor, $ipAddress): Category {
            foreach ([
                'name', 'slug', 'node_type', 'schema_org_type', 'icon_name', 'image_url',
                'is_assignable', 'has_landing_page', 'is_featured', 'show_on_homepage',
                'is_popular', 'sort_order', 'is_active',
            ] as $field) {
                if (array_key_exists($field, $data)) {
                    $category->setAttribute($field, $data[$field]);
                }
            }

            $category->save();

            if (isset($data['translations']) && is_array($data['translations'])) {
                $this->syncTranslations($category, $data['translations']);
            }

            if ($category->slug !== $oldSlug) {
                $newPath = $this->treeService->canonicalPathFor($category);
                $this->redirects->registerRedirect($oldPath, $newPath, 'category', (int) $category->id);
            }

            $revision = $this->recordRevision($category, $actor, (string) ($data['change_summary'] ?? 'Updated category'));

            $this->auditLogger->record(
                actor: $actor,
                action: 'category.updated',
                auditable: $category,
                oldValues: $oldSnapshot,
                newValues: $revision->snapshot_json,
                ipAddress: $ipAddress,
                actorRole: 'super_admin',
            );

            return $category;
        });

        $this->treeService->invalidateCache();

        return $updated->fresh(['translations', 'revisions']) ?? $updated;
    }

    public function moveCategory(Category $category, int|string|null $newParentIdentifier, User $actor, ?string $ipAddress = null): Category
    {
        if ($category->is_system) {
            throw new CategoryException('SYSTEM_CATEGORY_PROTECTED', 'System categories cannot be moved.');
        }

        $newParent = $this->resolveParent($newParentIdentifier);
        if ($newParent !== null && (int) $newParent->id === (int) $category->id) {
            throw new CategoryException('CATEGORY_SELF_PARENT', 'A category cannot be its own parent.');
        }

        if ($newParent !== null && $this->isDescendant($category, $newParent)) {
            throw new CategoryException('CATEGORY_CYCLE_DETECTED', 'Cannot move a category under one of its own descendants.');
        }

        $newDepth = $newParent === null ? 0 : $newParent->depth + 1;
        $subtreeHeight = $this->subtreeHeight($category);
        $this->assertDepthAllowed($newDepth + $subtreeHeight);

        $oldPath = $this->treeService->canonicalPathFor($category);

        DB::transaction(function () use ($category, $newParent, $newDepth, $oldPath, $actor, $ipAddress): void {
            $category->parent_id = $newParent?->id;
            $category->depth = $newDepth;
            $category->save();

            $this->recalculateDescendantDepths($category);

            $newPath = $this->treeService->canonicalPathFor($category);
            if ($oldPath !== $newPath) {
                $this->redirects->registerRedirect($oldPath, $newPath, 'category', (int) $category->id);
            }

            $this->recordRevision($category, $actor, 'Moved category hierarchy');
            $this->auditLogger->record(
                actor: $actor,
                action: 'category.moved',
                auditable: $category,
                oldValues: ['path' => $oldPath],
                newValues: ['path' => $newPath, 'depth' => $newDepth],
                ipAddress: $ipAddress,
                actorRole: 'super_admin',
            );
        });

        $this->treeService->invalidateCache();

        return $category->fresh(['translations', 'revisions']) ?? $category;
    }

    /**
     * @param  list<array{public_id: string, sort_order: int}>  $items
     */
    public function reorderCategories(array $items, User $actor, ?string $ipAddress = null): void
    {
        DB::transaction(function () use ($items, $actor, $ipAddress): void {
            foreach ($items as $item) {
                Category::query()
                    ->where('public_id', $item['public_id'])
                    ->update(['sort_order' => $item['sort_order']]);
            }

            if ($items !== []) {
                $first = Category::query()->where('public_id', $items[0]['public_id'])->first();
                if ($first !== null) {
                    $this->auditLogger->record(
                        actor: $actor,
                        action: 'category.reordered',
                        auditable: $first,
                        oldValues: null,
                        newValues: ['items' => $items],
                        ipAddress: $ipAddress,
                        actorRole: 'super_admin',
                    );
                }
            }
        });

        $this->treeService->invalidateCache();
    }

    public function cloneCategory(Category $source, User $actor, ?string $ipAddress = null): Category
    {
        $source->loadMissing(['translations', 'attributeDefinitions', 'filterConfigs', 'profileSections']);
        $slug = $this->uniqueCopySlug($source->slug);

        $clone = DB::transaction(function () use ($source, $slug, $actor, $ipAddress): Category {
            $clone = Category::query()->create([
                'parent_id' => $source->parent_id,
                'node_type' => $source->node_type,
                'name' => "{$source->name} (Copy)",
                'slug' => $slug,
                'schema_org_type' => $source->schema_org_type,
                'icon_name' => $source->icon_name,
                'image_url' => $source->image_url,
                'depth' => $source->depth,
                'lifecycle_status' => 'draft',
                'is_system' => false,
                'is_assignable' => $source->is_assignable,
                'has_landing_page' => $source->has_landing_page,
                'is_featured' => false,
                'show_on_homepage' => false,
                'is_popular' => false,
                'sort_order' => $source->sort_order + 1,
                'is_active' => false,
            ]);

            foreach ($source->translations as $tr) {
                CategoryTranslation::query()->create([
                    'category_id' => $clone->id,
                    'locale' => $tr->locale,
                    'name' => $tr->name,
                    'description' => $tr->description,
                    'synonyms' => $tr->synonyms,
                    'seo_title_template' => $tr->seo_title_template,
                    'seo_description_template' => $tr->seo_description_template,
                ]);
            }

            foreach ($source->attributeDefinitions as $attr) {
                CategoryAttributeDefinition::query()->create([
                    'category_id' => $clone->id,
                    'attribute_key' => $attr->attribute_key,
                    'label_en' => $attr->label_en,
                    'label_ta' => $attr->label_ta,
                    'data_type' => $attr->data_type,
                    'options_json' => $attr->options_json,
                    'unit' => $attr->unit,
                    'is_filterable' => $attr->is_filterable,
                    'is_required' => $attr->is_required,
                    'show_on_profile' => $attr->show_on_profile,
                    'sort_order' => $attr->sort_order,
                    'is_active' => $attr->is_active,
                ]);
            }

            foreach ($source->filterConfigs as $filter) {
                CategoryFilterConfig::query()->create([
                    'category_id' => $clone->id,
                    'filter_key' => $filter->filter_key,
                    'filter_type' => $filter->filter_type,
                    'label_en' => $filter->label_en,
                    'label_ta' => $filter->label_ta,
                    'sort_order' => $filter->sort_order,
                    'is_enabled' => $filter->is_enabled,
                ]);
            }

            foreach ($source->profileSections as $sec) {
                CategoryProfileSection::query()->create([
                    'category_id' => $clone->id,
                    'section_key' => $sec->section_key,
                    'display_label_en' => $sec->display_label_en,
                    'display_label_ta' => $sec->display_label_ta,
                    'is_enabled' => $sec->is_enabled,
                    'is_required' => $sec->is_required,
                    'suppress_when_empty' => $sec->suppress_when_empty,
                    'sort_order' => $sec->sort_order,
                ]);
            }

            $this->recordRevision($clone, $actor, "Cloned from {$source->public_id}");
            $this->auditLogger->record(
                actor: $actor,
                action: 'category.cloned',
                auditable: $clone,
                oldValues: ['source_public_id' => $source->public_id],
                newValues: ['slug' => $clone->slug],
                ipAddress: $ipAddress,
                actorRole: 'super_admin',
            );

            return $clone;
        });

        $this->treeService->invalidateCache();

        return $clone->fresh(['translations', 'revisions']) ?? $clone;
    }

    public function publishCategory(Category $category, User $actor, ?string $ipAddress = null): Category
    {
        DB::transaction(function () use ($category, $actor, $ipAddress): void {
            $category->lifecycle_status = 'published';
            $category->is_active = true;
            $category->archived_at = null;
            $category->save();

            $revision = $this->recordRevision($category, $actor, 'Published category', 'published', $actor);
            $category->published_version_id = $revision->id;
            $category->save();

            $this->auditLogger->record(
                actor: $actor,
                action: 'category.published',
                auditable: $category,
                oldValues: null,
                newValues: ['version_number' => $revision->version_number],
                ipAddress: $ipAddress,
                actorRole: 'super_admin',
            );
        });

        $this->treeService->invalidateCache();

        return $category->fresh(['publishedVersion', 'revisions']) ?? $category;
    }

    public function archiveCategory(Category $category, User $actor, ?string $ipAddress = null): Category
    {
        if ($category->is_system) {
            throw new CategoryException('SYSTEM_CATEGORY_PROTECTED', 'System categories cannot be archived.');
        }

        $deps = $this->dependencies($category);
        if ($deps['active_children_count'] > 0 || $deps['active_businesses_count'] > 0) {
            throw new CategoryException(
                'CATEGORY_HAS_DEPENDENCIES',
                'Cannot archive category while active child categories or active businesses depend on it.',
                Response::HTTP_CONFLICT,
            );
        }

        DB::transaction(function () use ($category, $actor, $ipAddress): void {
            $category->lifecycle_status = 'archived';
            $category->is_active = false;
            $category->archived_at = now();
            $category->save();

            $this->recordRevision($category, $actor, 'Archived category', 'archived');
            $this->auditLogger->record(
                actor: $actor,
                action: 'category.archived',
                auditable: $category,
                oldValues: null,
                newValues: ['lifecycle_status' => 'archived'],
                ipAddress: $ipAddress,
                actorRole: 'super_admin',
            );
        });

        $this->treeService->invalidateCache();

        return $category->fresh() ?? $category;
    }

    public function restoreCategory(Category $category, User $actor, ?string $ipAddress = null): Category
    {
        DB::transaction(function () use ($category, $actor, $ipAddress): void {
            if ($category->trashed()) {
                $category->restore();
            }
            $category->lifecycle_status = 'draft';
            $category->archived_at = null;
            $category->save();

            $this->recordRevision($category, $actor, 'Restored category');
            $this->auditLogger->record(
                actor: $actor,
                action: 'category.restored',
                auditable: $category,
                oldValues: null,
                newValues: ['lifecycle_status' => 'draft'],
                ipAddress: $ipAddress,
                actorRole: 'super_admin',
            );
        });

        $this->treeService->invalidateCache();

        return $category->fresh() ?? $category;
    }

    public function softDeleteCategory(Category $category, User $actor, ?string $ipAddress = null): void
    {
        if ($category->is_system) {
            throw new CategoryException('SYSTEM_CATEGORY_PROTECTED', 'System categories cannot be deleted.');
        }

        $deps = $this->dependencies($category);
        if ($deps['children_count'] > 0 || $deps['businesses_count'] > 0 || $deps['enquiries_count'] > 0) {
            throw new CategoryException(
                'CATEGORY_HAS_DEPENDENCIES',
                'Category has dependent child categories, businesses, or enquiries and cannot be deleted.',
                Response::HTTP_CONFLICT,
            );
        }

        DB::transaction(function () use ($category, $actor, $ipAddress): void {
            $category->is_active = false;
            $category->lifecycle_status = 'archived';
            $category->archived_at = now();
            $category->save();
            $category->delete();

            $this->auditLogger->record(
                actor: $actor,
                action: 'category.soft_deleted',
                auditable: $category,
                oldValues: ['slug' => $category->slug],
                newValues: null,
                ipAddress: $ipAddress,
                actorRole: 'super_admin',
            );
        });

        $this->treeService->invalidateCache();
    }

    /**
     * @return array<string, int>
     */
    public function dependencies(Category $category): array
    {
        $childrenCount = Category::query()->where('parent_id', $category->id)->count();
        $activeChildrenCount = Category::query()->where('parent_id', $category->id)->where('is_active', true)->count();

        $businessesCount = Business::query()
            ->withTrashed()
            ->where(static function ($q) use ($category): void {
                $q->where('primary_category_id', $category->id)
                    ->orWhereHas('categories', static fn ($c) => $c->where('categories.id', $category->id));
            })
            ->count();

        $activeBusinessesCount = Business::query()
            ->where('status', 'active')
            ->where(static function ($q) use ($category): void {
                $q->where('primary_category_id', $category->id)
                    ->orWhereHas('categories', static fn ($c) => $c->where('categories.id', $category->id));
            })
            ->count();

        $enquiriesCount = Enquiry::query()->withTrashed()->where('category_id', $category->id)->count();
        $collectionsCount = DB::table('category_collection_items')->where('category_id', $category->id)->count();

        return [
            'children_count' => $childrenCount,
            'active_children_count' => $activeChildrenCount,
            'businesses_count' => $businessesCount,
            'active_businesses_count' => $activeBusinessesCount,
            'enquiries_count' => $enquiriesCount,
            'collections_count' => $collectionsCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $schemas
     */
    public function syncSchemas(Category $category, array $schemas, User $actor, ?string $ipAddress = null): Category
    {
        DB::transaction(function () use ($category, $schemas, $actor, $ipAddress): void {
            if (isset($schemas['attributes']) && is_array($schemas['attributes'])) {
                CategoryAttributeDefinition::query()->where('category_id', $category->id)->delete();
                foreach ($schemas['attributes'] as $idx => $attr) {
                    CategoryAttributeDefinition::query()->create([
                        'category_id' => $category->id,
                        'attribute_key' => (string) $attr['attribute_key'],
                        'label_en' => (string) $attr['label_en'],
                        'label_ta' => $attr['label_ta'] ?? null,
                        'data_type' => (string) $attr['data_type'],
                        'options_json' => $attr['options_json'] ?? null,
                        'unit' => $attr['unit'] ?? null,
                        'is_filterable' => (bool) ($attr['is_filterable'] ?? false),
                        'is_required' => (bool) ($attr['is_required'] ?? false),
                        'show_on_profile' => (bool) ($attr['show_on_profile'] ?? true),
                        'sort_order' => (int) ($attr['sort_order'] ?? $idx),
                        'is_active' => (bool) ($attr['is_active'] ?? true),
                    ]);
                }
            }

            if (isset($schemas['filters']) && is_array($schemas['filters'])) {
                CategoryFilterConfig::query()->where('category_id', $category->id)->delete();
                foreach ($schemas['filters'] as $idx => $filter) {
                    CategoryFilterConfig::query()->create([
                        'category_id' => $category->id,
                        'filter_key' => (string) $filter['filter_key'],
                        'filter_type' => (string) $filter['filter_type'],
                        'label_en' => (string) $filter['label_en'],
                        'label_ta' => $filter['label_ta'] ?? null,
                        'sort_order' => (int) ($filter['sort_order'] ?? $idx),
                        'is_enabled' => (bool) ($filter['is_enabled'] ?? true),
                    ]);
                }
            }

            if (isset($schemas['profile_sections']) && is_array($schemas['profile_sections'])) {
                CategoryProfileSection::query()->where('category_id', $category->id)->delete();
                foreach ($schemas['profile_sections'] as $idx => $sec) {
                    CategoryProfileSection::query()->create([
                        'category_id' => $category->id,
                        'section_key' => (string) $sec['section_key'],
                        'display_label_en' => (string) $sec['display_label_en'],
                        'display_label_ta' => $sec['display_label_ta'] ?? null,
                        'is_enabled' => (bool) ($sec['is_enabled'] ?? true),
                        'is_required' => (bool) ($sec['is_required'] ?? false),
                        'suppress_when_empty' => (bool) ($sec['suppress_when_empty'] ?? true),
                        'sort_order' => (int) ($sec['sort_order'] ?? $idx),
                    ]);
                }
            }

            $this->recordRevision($category, $actor, 'Updated category attribute/filter/section schemas');
            $this->auditLogger->record(
                actor: $actor,
                action: 'category.schemas_synced',
                auditable: $category,
                oldValues: null,
                newValues: ['keys' => array_keys($schemas)],
                ipAddress: $ipAddress,
                actorRole: 'super_admin',
            );
        });

        $this->treeService->invalidateCache();

        return $category->fresh(['attributeDefinitions', 'filterConfigs', 'profileSections']) ?? $category;
    }

    public function rollbackRevision(Category $category, CategoryRevision $revision, User $actor, ?string $ipAddress = null): Category
    {
        if ((int) $revision->category_id !== (int) $category->id) {
            throw new CategoryException('CATEGORY_REVISION_MISMATCH', 'Revision does not belong to this category.');
        }

        $snapshot = $revision->snapshot_json;
        $snapshot['change_summary'] = "Rollback to revision v{$revision->version_number}";

        return $this->updateCategory($category, $snapshot, $actor, $ipAddress);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsertCollection(?CategoryCollection $collection, array $data, User $actor, ?string $ipAddress = null): CategoryCollection
    {
        $slug = Str::slug((string) ($data['slug'] ?? $data['name_en']));
        $dup = CategoryCollection::query()->withTrashed()->where('slug', $slug);
        if ($collection !== null) {
            $dup->whereKeyNot($collection->id);
        }
        if ($dup->exists()) {
            throw new CategoryException('SLUG_ALREADY_TAKEN', "Collection slug '{$slug}' is already in use.", Response::HTTP_CONFLICT);
        }

        $saved = DB::transaction(function () use ($collection, $data, $slug, $actor, $ipAddress): CategoryCollection {
            $oldSlug = $collection?->slug;

            if ($collection === null) {
                $collection = CategoryCollection::query()->create([
                    'slug' => $slug,
                    'name_en' => (string) $data['name_en'],
                    'name_ta' => $data['name_ta'] ?? null,
                    'description_en' => $data['description_en'] ?? null,
                    'description_ta' => $data['description_ta'] ?? null,
                    'icon_name' => $data['icon_name'] ?? null,
                    'lifecycle_status' => (string) ($data['lifecycle_status'] ?? 'draft'),
                    'show_on_homepage' => (bool) ($data['show_on_homepage'] ?? false),
                    'is_popular' => (bool) ($data['is_popular'] ?? false),
                    'sort_order' => (int) ($data['sort_order'] ?? 0),
                    'is_active' => (bool) ($data['is_active'] ?? true),
                ]);
            } else {
                $collection->fill([
                    'slug' => $slug,
                    'name_en' => $data['name_en'] ?? $collection->name_en,
                    'name_ta' => array_key_exists('name_ta', $data) ? $data['name_ta'] : $collection->name_ta,
                    'description_en' => array_key_exists('description_en', $data) ? $data['description_en'] : $collection->description_en,
                    'description_ta' => array_key_exists('description_ta', $data) ? $data['description_ta'] : $collection->description_ta,
                    'icon_name' => array_key_exists('icon_name', $data) ? $data['icon_name'] : $collection->icon_name,
                    'lifecycle_status' => $data['lifecycle_status'] ?? $collection->lifecycle_status,
                    'show_on_homepage' => $data['show_on_homepage'] ?? $collection->show_on_homepage,
                    'is_popular' => $data['is_popular'] ?? $collection->is_popular,
                    'sort_order' => $data['sort_order'] ?? $collection->sort_order,
                    'is_active' => $data['is_active'] ?? $collection->is_active,
                ]);
                $collection->save();

                if ($oldSlug !== null && $oldSlug !== $collection->slug) {
                    $this->redirects->registerRedirect(
                        "/c/collections/{$oldSlug}",
                        "/c/collections/{$collection->slug}",
                        'category_collection',
                        (int) $collection->id,
                    );
                }
            }

            if (isset($data['category_public_ids']) && is_array($data['category_public_ids'])) {
                $categories = Category::query()->whereIn('public_id', $data['category_public_ids'])->get()->keyBy('public_id');
                $sync = [];
                foreach (array_values($data['category_public_ids']) as $idx => $pid) {
                    if (isset($categories[$pid])) {
                        $sync[$categories[$pid]->id] = ['sort_order' => $idx];
                    }
                }
                $collection->categories()->sync($sync);
            }

            $this->auditLogger->record(
                actor: $actor,
                action: $oldSlug === null ? 'category_collection.created' : 'category_collection.updated',
                auditable: $collection,
                oldValues: $oldSlug ? ['slug' => $oldSlug] : null,
                newValues: ['slug' => $collection->slug],
                ipAddress: $ipAddress,
                actorRole: 'super_admin',
            );

            return $collection;
        });

        $this->treeService->invalidateCache();

        return $saved->fresh('categories') ?? $saved;
    }

    private function resolveParent(int|string|null $identifier): ?Category
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        $parent = is_numeric($identifier)
            ? Category::query()->find((int) $identifier)
            : Category::query()->where('public_id', (string) $identifier)->first();

        if ($parent === null || $parent->lifecycle_status === 'archived') {
            throw new CategoryException('INVALID_PARENT_CATEGORY', 'Selected parent category does not exist or is archived.');
        }

        return $parent;
    }

    private function assertDepthAllowed(int $depth): void
    {
        $max = $this->maxDepth();
        if ($depth < 0 || $depth >= $max) {
            throw new CategoryException(
                'CATEGORY_MAX_DEPTH_EXCEEDED',
                "Category nesting depth cannot exceed {$max} levels.",
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
    }

    private function assertValidNodeType(string $nodeType): void
    {
        if (! in_array($nodeType, self::NODE_TYPES, true)) {
            throw new CategoryException('INVALID_NODE_TYPE', "Invalid category node_type '{$nodeType}'.");
        }
    }

    private function assertSlugUnique(string $slug, ?int $ignoreId = null): void
    {
        $query = Category::query()->withTrashed()->where('slug', $slug);
        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }
        if ($query->exists()) {
            throw new CategoryException('SLUG_ALREADY_TAKEN', "Category slug '{$slug}' is already in use.", Response::HTTP_CONFLICT);
        }
    }

    private function isDescendant(Category $ancestor, Category $candidate): bool
    {
        $current = $candidate;
        $guard = 0;

        while ($current !== null && $guard < 12) {
            if ((int) $current->parent_id === (int) $ancestor->id) {
                return true;
            }
            $current = $current->parent_id ? Category::query()->withTrashed()->find($current->parent_id) : null;
            $guard++;
        }

        return false;
    }

    private function subtreeHeight(Category $root): int
    {
        $children = Category::query()->where('parent_id', $root->id)->get();
        if ($children->isEmpty()) {
            return 0;
        }

        $max = 0;
        foreach ($children as $child) {
            $max = max($max, 1 + $this->subtreeHeight($child));
        }

        return $max;
    }

    private function recalculateDescendantDepths(Category $parent): void
    {
        $children = Category::query()->where('parent_id', $parent->id)->get();
        foreach ($children as $child) {
            $child->depth = $parent->depth + 1;
            $child->save();
            $this->recalculateDescendantDepths($child);
        }
    }

    private function uniqueCopySlug(string $baseSlug): string
    {
        $candidate = "{$baseSlug}-copy";
        $i = 2;
        while (Category::query()->withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = "{$baseSlug}-copy-{$i}";
            $i++;
        }

        return $candidate;
    }

    /**
     * @param  array<string, array<string, mixed>>  $translations
     */
    private function syncTranslations(Category $category, array $translations): void
    {
        foreach ($translations as $locale => $fields) {
            if (! in_array($locale, ['en', 'ta'], true) || ! is_array($fields)) {
                continue;
            }

            CategoryTranslation::query()->updateOrCreate(
                ['category_id' => $category->id, 'locale' => $locale],
                [
                    'name' => (string) ($fields['name'] ?? $category->name),
                    'description' => $fields['description'] ?? null,
                    'synonyms' => $fields['synonyms'] ?? null,
                    'seo_title_template' => $fields['seo_title_template'] ?? null,
                    'seo_description_template' => $fields['seo_description_template'] ?? null,
                ]
            );
        }
    }

    private function recordRevision(
        Category $category,
        User $actor,
        string $summary,
        ?string $statusOverride = null,
        ?User $approver = null,
    ): CategoryRevision {
        $nextVersion = ((int) CategoryRevision::query()->where('category_id', $category->id)->max('version_number')) + 1;

        return CategoryRevision::query()->create([
            'category_id' => $category->id,
            'version_number' => $nextVersion,
            'status' => $statusOverride ?? $category->lifecycle_status,
            'snapshot_json' => $this->snapshot($category),
            'change_summary' => $summary,
            'created_by_user_id' => $actor->id,
            'approved_by_user_id' => $approver?->id,
            'published_at' => $statusOverride === 'published' ? now() : null,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Category $category): array
    {
        return [
            'name' => $category->name,
            'slug' => $category->slug,
            'node_type' => $category->node_type,
            'depth' => $category->depth,
            'schema_org_type' => $category->schema_org_type,
            'lifecycle_status' => $category->lifecycle_status,
            'is_assignable' => $category->is_assignable,
            'has_landing_page' => $category->has_landing_page,
            'show_on_homepage' => $category->show_on_homepage,
            'is_popular' => $category->is_popular,
            'is_active' => $category->is_active,
        ];
    }
}
