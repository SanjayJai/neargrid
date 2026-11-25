<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Directory;

use App\Domains\Location\Models\City;
use App\Domains\Location\Models\State;
use App\Domains\Taxonomy\Models\Amenity;
use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ReferenceTaxonomyController extends Controller
{
    public function states(Request $request): JsonResponse
    {
        $locale = $this->normalizeLocale((string) $request->query('locale', 'en'));

        $query = State::query()
            ->withLocale($locale)
            ->where('is_active', true)
            ->withCount(['cities as cities_count' => static fn ($cq) => $cq->where('is_active', true)]);

        if ($request->filled('country_code')) {
            $query->where('country_code', strtoupper(trim((string) $request->query('country_code'))));
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->where(static function ($sq) use ($term): void {
                $sq->where('name', 'like', "%{$term}%")
                    ->orWhere('slug', 'like', "%{$term}%")
                    ->orWhereHas('translations', static fn ($tq) => $tq->where('name', 'like', "%{$term}%"));
            });
        }

        $query->orderBy('sort_order')->orderBy('name');

        $mapper = static fn (State $state): array => [
            'public_id' => $state->public_id,
            'country_code' => $state->country_code,
            'slug' => $state->slug,
            'name' => $state->translatedName($locale),
            'name_en' => $state->name,
            'cities_count' => (int) ($state->cities_count ?? 0),
            'sort_order' => $state->sort_order,
        ];

        if ($request->filled('per_page')) {
            $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
            $paginated = $query->paginate($perPage);

            return ApiResponse::paginated(
                $paginated,
                collect($paginated->items())->map($mapper)->values()->all()
            );
        }

        return ApiResponse::success($query->get()->map($mapper)->values()->all());
    }

    public function cities(Request $request): JsonResponse
    {
        $locale = $this->normalizeLocale((string) $request->query('locale', 'en'));

        $query = City::query()
            ->withLocale($locale)
            ->with(['state' => static fn ($sq) => $sq->withLocale($locale)])
            ->where('is_active', true)
            ->whereHas('state', static fn ($sq) => $sq->where('is_active', true));

        if ($request->filled('state')) {
            $stateFilter = trim((string) $request->query('state'));
            $query->whereHas('state', static fn ($sq) => $sq->where('slug', $stateFilter)->orWhere('public_id', $stateFilter));
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->where(static function ($cq) use ($term): void {
                $cq->where('name', 'like', "%{$term}%")
                    ->orWhere('slug', 'like', "%{$term}%")
                    ->orWhereHas('translations', static fn ($tq) => $tq->where('name', 'like', "%{$term}%"));
            });
        }

        $query->orderByDesc('is_popular')->orderBy('sort_order')->orderBy('name');

        $mapper = static fn (City $city): array => [
            'public_id' => $city->public_id,
            'slug' => $city->slug,
            'name' => $city->translatedName($locale),
            'name_en' => $city->name,
            'state_slug' => $city->state?->slug,
            'state_name' => $city->state?->translatedName($locale),
            'latitude' => $city->latitude,
            'longitude' => $city->longitude,
            'is_popular' => $city->is_popular,
        ];

        if ($request->filled('per_page')) {
            $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
            $paginated = $query->paginate($perPage);

            return ApiResponse::paginated(
                $paginated,
                collect($paginated->items())->map($mapper)->values()->all()
            );
        }

        return ApiResponse::success($query->get()->map($mapper)->values()->all());
    }

    public function localities(Request $request, string $citySlug): JsonResponse
    {
        $locale = $this->normalizeLocale((string) $request->query('locale', 'en'));

        $city = City::query()
            ->where('slug', $citySlug)
            ->where('is_active', true)
            ->whereHas('state', static fn ($sq) => $sq->where('is_active', true))
            ->first();

        if ($city === null) {
            return ApiResponse::error('NOT_FOUND', 'City not found.', Response::HTTP_NOT_FOUND);
        }

        $query = $city->localities()
            ->withLocale($locale)
            ->where('is_active', true);

        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->where(static function ($lq) use ($term): void {
                $lq->where('name', 'like', "%{$term}%")
                    ->orWhere('slug', 'like', "%{$term}%")
                    ->orWhere('pincode', 'like', "%{$term}%")
                    ->orWhereHas('translations', static fn ($tq) => $tq->where('name', 'like', "%{$term}%"));
            });
        }

        $localities = $query
            ->orderBy('sort_order')
            ->orderByDesc('is_popular')
            ->orderBy('name')
            ->get()
            ->map(static fn ($loc): array => [
                'public_id' => $loc->public_id,
                'slug' => $loc->slug,
                'name' => $loc->translatedName($locale),
                'name_en' => $loc->name,
                'pincode' => $loc->pincode,
                'latitude' => $loc->latitude,
                'longitude' => $loc->longitude,
                'is_popular' => $loc->is_popular,
            ])
            ->values()
            ->all();

        return ApiResponse::success($localities);
    }

    public function categories(Request $request): JsonResponse
    {
        $locale = $this->normalizeLocale((string) $request->query('locale', 'en'));

        $roots = Category::query()
            ->root()
            ->active()
            ->withLocale($locale)
            ->with([
                'children' => static fn ($q) => $q->active()->withLocale($locale),
            ])
            ->orderBy('sort_order')
            ->get()
            ->map(static fn (Category $root): array => [
                'public_id' => $root->public_id,
                'slug' => $root->slug,
                'name' => $root->translatedName($locale),
                'schema_org_type' => $root->schema_org_type,
                'icon_name' => $root->icon_name,
                'is_featured' => $root->is_featured,
                'children' => $root->children->map(static fn (Category $child): array => [
                    'public_id' => $child->public_id,
                    'slug' => $child->slug,
                    'name' => $child->translatedName($locale),
                    'schema_org_type' => $child->schema_org_type,
                    'icon_name' => $child->icon_name,
                    'is_featured' => $child->is_featured,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return ApiResponse::success($roots);
    }

    public function attributes(): JsonResponse
    {
        return ApiResponse::success([
            'amenities' => Amenity::query()
                ->where('is_active', true)
                ->orderBy('group_name')
                ->orderBy('name')
                ->get(['slug', 'name', 'icon_name', 'group_name'])
                ->toArray(),
            'payment_methods' => PaymentMethod::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['slug', 'name', 'icon_name'])
                ->toArray(),
        ]);
    }

    private function normalizeLocale(string $locale): string
    {
        $clean = strtolower(trim($locale));

        return in_array($clean, ['en', 'ta'], true) ? $clean : 'en';
    }
}
