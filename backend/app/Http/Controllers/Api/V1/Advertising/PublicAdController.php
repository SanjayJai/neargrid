<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Advertising;

use App\Domains\Advertising\DTOs\AdDeliveryContext;
use App\Domains\Advertising\Services\AdDeliveryService;
use App\Domains\Advertising\Services\AdTrackingService;
use App\Domains\Domains\Services\DomainResolutionService;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Taxonomy\Models\Category;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PublicAdController extends Controller
{
    public function __construct(
        private readonly AdDeliveryService $deliveryService,
        private readonly AdTrackingService $trackingService,
        private readonly DomainResolutionService $domainResolver,
    ) {}

    public function deliver(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page_type' => ['required', 'string', Rule::in(['home', 'search', 'category', 'city_hub', 'business_profile', 'account', 'global'])],
            'placement_keys' => ['required', 'array', 'min:1', 'max:12'],
            'placement_keys.*' => ['required', 'string', 'max:80'],
            'route_path' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', 'string', Rule::in(['en', 'ta'])],
            'device' => ['nullable', 'string', Rule::in(['desktop', 'tablet', 'mobile'])],
            'city_slug' => ['nullable', 'string', 'max:120'],
            'locality_slug' => ['nullable', 'string', 'max:120'],
            'category_slug' => ['nullable', 'string', 'max:120'],
            'host' => ['nullable', 'string', 'max:255'],
        ]);

        $city = null;
        if (! empty($validated['city_slug'])) {
            $city = City::query()->where('slug', (string) $validated['city_slug'])->where('is_active', true)->first(['id', 'state_id']);
        }

        $locality = null;
        if (! empty($validated['locality_slug']) && $city !== null) {
            $locality = Locality::query()->where('city_id', $city->id)->where('slug', (string) $validated['locality_slug'])->where('is_active', true)->first(['id']);
        }

        $categoryId = null;
        $ancestorIds = [];
        if (! empty($validated['category_slug'])) {
            $category = Category::query()->where('slug', (string) $validated['category_slug'])->where('is_active', true)->first(['id', 'parent_id']);
            if ($category !== null) {
                $categoryId = (int) $category->id;
                $parentId = $category->parent_id;
                $guard = 0;
                while ($parentId !== null && $guard < 5) {
                    $ancestorIds[] = (int) $parentId;
                    $parentId = Category::query()->where('id', $parentId)->value('parent_id');
                    $guard++;
                }
            }
        }

        $domainId = null;
        $rawHost = $validated['host'] ?? $request->getHost();
        if (is_string($rawHost) && $rawHost !== '') {
            $domain = $this->domainResolver->resolveActiveDomain($rawHost);
            $domainId = $domain?->id;
        }

        // Derive audience server-side from Sanctum token (never trust client-asserted audience)
        $audience = 'guest';
        $user = $request->user('sanctum');
        if ($user instanceof User && $user->status === 'active') {
            $audience = $user->hasRole('business_owner') || $user->hasRole('business_staff') ? 'merchant' : 'customer';
        }

        $context = new AdDeliveryContext(
            pageType: (string) $validated['page_type'],
            placementKeys: array_values(array_unique(array_map('strval', $validated['placement_keys']))),
            routePath: (string) ($validated['route_path'] ?? '/'),
            locale: (string) ($validated['locale'] ?? 'en'),
            device: (string) ($validated['device'] ?? 'desktop'),
            audience: $audience,
            domainId: $domainId,
            stateId: $city ? (int) $city->state_id : null,
            cityId: $city ? (int) $city->id : null,
            localityId: $locality ? (int) $locality->id : null,
            categoryId: $categoryId,
            categoryAncestorIds: $ancestorIds,
        );

        $placements = $this->deliveryService->deliver($context);

        return ApiResponse::success(
            ['placements' => $placements],
            headers: ['Cache-Control' => 'private, no-store']
        );
    }

    public function recordImpression(Request $request): JsonResponse
    {
        return $this->handleEvent($request, 'impression');
    }

    public function recordClick(Request $request): JsonResponse
    {
        return $this->handleEvent($request, 'click');
    }

    private function handleEvent(Request $request, string $eventType): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:2048'],
            'session_id' => ['nullable', 'string', 'max:128'],
        ]);

        $sessionId = (string) ($validated['session_id'] ?? $request->header('X-Ad-Session', ''));
        $result = $this->trackingService->recordEvent(
            token: (string) $validated['token'],
            eventType: $eventType,
            userAgent: $request->userAgent(),
            ip: (string) ($request->ip() ?? '0.0.0.0'),
            sessionIdentifier: $sessionId,
        );

        if (($result['reason'] ?? null) === 'invalid_or_expired_token') {
            return ApiResponse::error('INVALID_ATTRIBUTION_TOKEN', 'Invalid or expired ad attribution token.', 422);
        }

        return ApiResponse::success($result, headers: ['Cache-Control' => 'private, no-store']);
    }
}
