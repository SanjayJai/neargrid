<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Taxonomy\Models\CategoryCollection;
use App\Domains\Taxonomy\Services\CategoryManagementService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class AdminCategoryCollectionController extends Controller
{
    public function __construct(
        private readonly CategoryManagementService $categories,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAdmin', CategoryCollection::class);

        $collections = CategoryCollection::query()
            ->with('categories')
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->get()
            ->map(fn (CategoryCollection $c): array => $this->format($c))
            ->values()
            ->all();

        return ApiResponse::success($collections);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', CategoryCollection::class);

        $validated = $request->validate([
            'slug' => ['nullable', 'string', 'max:140'],
            'name_en' => ['required', 'string', 'max:140'],
            'name_ta' => ['nullable', 'string', 'max:140'],
            'description_en' => ['nullable', 'string'],
            'description_ta' => ['nullable', 'string'],
            'icon_name' => ['nullable', 'string', 'max:80'],
            'lifecycle_status' => ['nullable', 'string', Rule::in(CategoryManagementService::LIFECYCLE_STATUSES)],
            'show_on_homepage' => ['nullable', 'boolean'],
            'is_popular' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'category_public_ids' => ['nullable', 'array'],
            'category_public_ids.*' => ['string'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $collection = $this->categories->upsertCollection(null, $validated, $actor, $request->ip());

        return ApiResponse::created($this->format($collection));
    }

    public function update(Request $request, CategoryCollection $categoryCollection): JsonResponse
    {
        Gate::authorize('update', $categoryCollection);

        $validated = $request->validate([
            'slug' => ['sometimes', 'string', 'max:140'],
            'name_en' => ['sometimes', 'string', 'max:140'],
            'name_ta' => ['nullable', 'string', 'max:140'],
            'description_en' => ['nullable', 'string'],
            'description_ta' => ['nullable', 'string'],
            'icon_name' => ['nullable', 'string', 'max:80'],
            'lifecycle_status' => ['sometimes', 'string', Rule::in(CategoryManagementService::LIFECYCLE_STATUSES)],
            'show_on_homepage' => ['sometimes', 'boolean'],
            'is_popular' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'category_public_ids' => ['sometimes', 'array'],
            'category_public_ids.*' => ['string'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->categories->upsertCollection($categoryCollection, $validated, $actor, $request->ip());

        return ApiResponse::success($this->format($updated));
    }

    public function destroy(CategoryCollection $categoryCollection): JsonResponse
    {
        Gate::authorize('delete', $categoryCollection);
        $categoryCollection->delete();

        return ApiResponse::success(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(CategoryCollection $c): array
    {
        return [
            'public_id' => $c->public_id,
            'slug' => $c->slug,
            'name_en' => $c->name_en,
            'name_ta' => $c->name_ta,
            'lifecycle_status' => $c->lifecycle_status,
            'show_on_homepage' => $c->show_on_homepage,
            'is_popular' => $c->is_popular,
            'sort_order' => $c->sort_order,
            'is_active' => $c->is_active,
            'category_public_ids' => $c->categories->pluck('public_id')->values()->all(),
        ];
    }
}
