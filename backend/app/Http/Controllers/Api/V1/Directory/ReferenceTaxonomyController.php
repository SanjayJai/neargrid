<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Directory;

use App\Domains\Location\Models\City;
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
    public function cities(Request $request): JsonResponse
    {
        $locale = (string) $request->query('locale', 'en');

        $cities = City::query()
            ->withLocale($locale)
            ->where('is_active', true)
            ->orderByDesc('is_popular')
            ->orderBy('sort_order')
            ->get()
            ->map(static fn (City $city): array => [
                'slug' => $city->slug,
                'name' => $city->translatedName($locale),
                'name_en' => $city->name,
                'latitude' => $city->latitude,
                'longitude' => $city->longitude,
                'is_popular' => $city->is_popular,
            ])
            ->values()
            ->all();

        return ApiResponse::success($cities);
    }

    public function localities(Request $request, string $citySlug): JsonResponse
    {
        $locale = (string) $request->query('locale', 'en');

        $city = City::query()->where('slug', $citySlug)->where('is_active', true)->first();
        if ($city === null) {
            return ApiResponse::error('NOT_FOUND', 'City not found.', Response::HTTP_NOT_FOUND);
        }

        $localities = $city->localities()
            ->withLocale($locale)
            ->where('is_active', true)
            ->orderByDesc('is_popular')
            ->orderBy('name')
            ->get()
            ->map(static fn ($loc): array => [
                'slug' => $loc->slug,
                'name' => $loc->translatedName($locale),
                'pincode' => $loc->pincode,
                'latitude' => $loc->latitude,
                'longitude' => $loc->longitude,
            ])
            ->values()
            ->all();

        return ApiResponse::success($localities);
    }

    public function categories(Request $request): JsonResponse
    {
        $locale = (string) $request->query('locale', 'en');

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
}
