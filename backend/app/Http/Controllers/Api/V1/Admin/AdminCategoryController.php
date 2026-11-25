<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\CategoryRevision;
use App\Domains\Taxonomy\Services\CategoryManagementService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class AdminCategoryController extends Controller
{
    public function __construct(
        private readonly CategoryManagementService $categories,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAdmin', Category::class);

        $query = Category::query()->with(['translations', 'parent']);

        if ($request->boolean('include_trashed')) {
            $query->withTrashed();
        }
        if ($request->filled('lifecycle_status')) {
            $query->where('lifecycle_status', (string) $request->query('lifecycle_status'));
        }
        if ($request->filled('node_type')) {
            $query->where('node_type', (string) $request->query('node_type'));
        }
        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->where(static function ($cq) use ($term): void {
                $cq->where('name', 'like', "%{$term}%")
                    ->orWhere('slug', 'like', "%{$term}%");
            });
        }

        $items = $query->orderBy('depth')->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (Category $cat): array => $this->formatCategory($cat))
            ->values()
            ->all();

        return ApiResponse::success($items);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Category::class);

        $validated = $request->validate(array_merge([
            'parent_id' => ['nullable'],
            'node_type' => ['nullable', 'string', Rule::in(CategoryManagementService::NODE_TYPES)],
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140'],
            'schema_org_type' => ['nullable', 'string', 'max:80'],
            'icon_name' => ['nullable', 'string', 'max:80'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'lifecycle_status' => ['nullable', 'string', Rule::in(CategoryManagementService::LIFECYCLE_STATUSES)],
            'is_system' => ['nullable', 'boolean'],
            'is_assignable' => ['nullable', 'boolean'],
            'has_landing_page' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'show_on_homepage' => ['nullable', 'boolean'],
            'is_popular' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
        ], $this->translationValidationRules()));

        /** @var User $actor */
        $actor = $request->user();
        $category = $this->categories->createCategory($validated, $actor, $request->ip());

        return ApiResponse::created($this->formatCategory($category));
    }

    public function show(Category $category): JsonResponse
    {
        Gate::authorize('viewAdmin', Category::class);
        $category->load(['translations', 'parent', 'revisions', 'attributeDefinitions', 'filterConfigs', 'profileSections']);

        return ApiResponse::success($this->formatCategory($category, detailed: true));
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        Gate::authorize('update', $category);

        $validated = $request->validate(array_merge([
            'name' => ['sometimes', 'string', 'max:120'],
            'slug' => ['sometimes', 'string', 'max:140'],
            'node_type' => ['sometimes', 'string', Rule::in(CategoryManagementService::NODE_TYPES)],
            'schema_org_type' => ['sometimes', 'string', 'max:80'],
            'icon_name' => ['nullable', 'string', 'max:80'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'is_assignable' => ['sometimes', 'boolean'],
            'has_landing_page' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'show_on_homepage' => ['sometimes', 'boolean'],
            'is_popular' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'change_summary' => ['nullable', 'string', 'max:255'],
        ], $this->translationValidationRules()));

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->categories->updateCategory($category, $validated, $actor, $request->ip());

        return ApiResponse::success($this->formatCategory($updated));
    }

    public function move(Request $request, Category $category): JsonResponse
    {
        Gate::authorize('update', $category);

        $validated = $request->validate([
            'parent_id' => ['nullable'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $moved = $this->categories->moveCategory($category, $validated['parent_id'] ?? null, $actor, $request->ip());

        return ApiResponse::success($this->formatCategory($moved));
    }

    public function reorder(Request $request): JsonResponse
    {
        Gate::authorize('create', Category::class);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.public_id' => ['required', 'string'],
            'items.*.sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $this->categories->reorderCategories($validated['items'], $actor, $request->ip());

        return ApiResponse::success(['reordered' => count($validated['items'])]);
    }

    public function clone(Request $request, Category $category): JsonResponse
    {
        Gate::authorize('create', Category::class);

        /** @var User $actor */
        $actor = $request->user();
        $cloned = $this->categories->cloneCategory($category, $actor, $request->ip());

        return ApiResponse::created($this->formatCategory($cloned));
    }

    public function publish(Request $request, Category $category): JsonResponse
    {
        Gate::authorize('update', $category);

        /** @var User $actor */
        $actor = $request->user();
        $published = $this->categories->publishCategory($category, $actor, $request->ip());

        return ApiResponse::success($this->formatCategory($published));
    }

    public function archive(Request $request, Category $category): JsonResponse
    {
        Gate::authorize('update', $category);

        /** @var User $actor */
        $actor = $request->user();
        $archived = $this->categories->archiveCategory($category, $actor, $request->ip());

        return ApiResponse::success($this->formatCategory($archived));
    }

    public function restore(Request $request, string $categoryPublicId): JsonResponse
    {
        $category = Category::query()->withTrashed()->where('public_id', $categoryPublicId)->firstOrFail();
        Gate::authorize('update', $category);

        /** @var User $actor */
        $actor = $request->user();
        $restored = $this->categories->restoreCategory($category, $actor, $request->ip());

        return ApiResponse::success($this->formatCategory($restored));
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        Gate::authorize('delete', $category);

        /** @var User $actor */
        $actor = $request->user();
        $this->categories->softDeleteCategory($category, $actor, $request->ip());

        return ApiResponse::success(['deleted' => true]);
    }

    public function dependencies(Category $category): JsonResponse
    {
        Gate::authorize('viewAdmin', Category::class);

        return ApiResponse::success($this->categories->dependencies($category));
    }

    public function syncSchemas(Request $request, Category $category): JsonResponse
    {
        Gate::authorize('update', $category);

        $validated = $request->validate([
            'attributes' => ['sometimes', 'array'],
            'attributes.*.attribute_key' => ['required_with:attributes', 'string', 'max:80'],
            'attributes.*.label_en' => ['required_with:attributes', 'string', 'max:120'],
            'attributes.*.label_ta' => ['nullable', 'string', 'max:120'],
            'attributes.*.data_type' => ['required_with:attributes', 'string', Rule::in(['string', 'number', 'boolean', 'enum', 'multi_enum'])],
            'filters' => ['sometimes', 'array'],
            'filters.*.filter_key' => ['required_with:filters', 'string', 'max:80'],
            'filters.*.filter_type' => ['required_with:filters', 'string', Rule::in(['checkbox', 'range', 'toggle', 'select'])],
            'filters.*.label_en' => ['required_with:filters', 'string', 'max:120'],
            'profile_sections' => ['sometimes', 'array'],
            'profile_sections.*.section_key' => ['required_with:profile_sections', 'string', 'max:60'],
            'profile_sections.*.display_label_en' => ['required_with:profile_sections', 'string', 'max:120'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->categories->syncSchemas($category, $validated, $actor, $request->ip());

        return ApiResponse::success($this->formatCategory($updated, detailed: true));
    }

    public function rollback(Request $request, Category $category, CategoryRevision $revision): JsonResponse
    {
        Gate::authorize('update', $category);

        /** @var User $actor */
        $actor = $request->user();
        $restored = $this->categories->rollbackRevision($category, $revision, $actor, $request->ip());

        return ApiResponse::success($this->formatCategory($restored));
    }

    /**
     * @return array<string, mixed>
     */
    private function translationValidationRules(): array
    {
        $rules = ['translations' => ['nullable', 'array']];

        foreach (['en', 'ta'] as $locale) {
            $rules["translations.{$locale}"] = ['sometimes', 'array'];
            $rules["translations.{$locale}.name"] = ["required_with:translations.{$locale}", 'string', 'max:140'];
            $rules["translations.{$locale}.description"] = ['nullable', 'string'];
            $rules["translations.{$locale}.synonyms"] = ['nullable', 'string'];
            $rules["translations.{$locale}.seo_title_template"] = ['nullable', 'string', 'max:180'];
            $rules["translations.{$locale}.seo_description_template"] = ['nullable', 'string', 'max:320'];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCategory(Category $cat, bool $detailed = false): array
    {
        $payload = [
            'public_id' => $cat->public_id,
            'parent_public_id' => $cat->parent?->public_id,
            'node_type' => $cat->node_type,
            'name' => $cat->name,
            'slug' => $cat->slug,
            'depth' => $cat->depth,
            'schema_org_type' => $cat->schema_org_type,
            'lifecycle_status' => $cat->lifecycle_status,
            'is_system' => $cat->is_system,
            'is_assignable' => $cat->is_assignable,
            'has_landing_page' => $cat->has_landing_page,
            'is_featured' => $cat->is_featured,
            'show_on_homepage' => $cat->show_on_homepage,
            'is_popular' => $cat->is_popular,
            'sort_order' => $cat->sort_order,
            'is_active' => $cat->is_active,
            'deleted_at' => $cat->deleted_at?->utc()->toIso8601ZuluString(),
            'translations' => $cat->relationLoaded('translations')
                ? $cat->translations->mapWithKeys(static fn ($t): array => [$t->locale => [
                    'name' => $t->name,
                    'description' => $t->description,
                    'synonyms' => $t->synonyms,
                    'seo_title_template' => $t->seo_title_template,
                    'seo_description_template' => $t->seo_description_template,
                ]])->all()
                : [],
        ];

        if ($detailed) {
            $payload['attributes'] = $cat->attributeDefinitions->toArray();
            $payload['filters'] = $cat->filterConfigs->toArray();
            $payload['profile_sections'] = $cat->profileSections->toArray();
            $payload['revisions'] = $cat->revisions->map(static fn (CategoryRevision $r): array => [
                'public_id' => $r->public_id,
                'version_number' => $r->version_number,
                'status' => $r->status,
                'change_summary' => $r->change_summary,
                'created_at' => $r->created_at?->utc()->toIso8601ZuluString(),
            ])->values()->all();
        }

        return $payload;
    }
}
