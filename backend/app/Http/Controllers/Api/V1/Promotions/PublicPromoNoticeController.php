<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Promotions;

use App\Domains\Directory\Models\Business;
use App\Domains\Domains\Services\DomainResolutionService;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Promotions\DTOs\PromoNoticeDeliveryContext;
use App\Domains\Promotions\Services\PromoNoticeDeliveryService;
use App\Domains\Promotions\Services\PromoNoticeTrackingService;
use App\Domains\Taxonomy\Models\Category;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PublicPromoNoticeController extends Controller
{
    public function __construct(
        private readonly PromoNoticeDeliveryService $deliveryService,
        private readonly PromoNoticeTrackingService $trackingService,
        private readonly DomainResolutionService $domainResolver,
    ) {}

    public function deliver(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'route_path' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', 'string', Rule::in(['en', 'ta'])],
            'device' => ['nullable', 'string', Rule::in(['desktop', 'tablet', 'mobile'])],
            'city_slug' => ['nullable', 'string', 'max:120'],
            'locality_slug' => ['nullable', 'string', 'max:120'],
            'category_slug' => ['nullable', 'string', 'max:120'],
            'business_public_id' => ['nullable', 'string', 'size:26'],
            'host' => ['nullable', 'string', 'max:255'],
            'session_id' => ['nullable', 'string', 'max:128'],
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

        $businessId = null;
        if (! empty($validated['business_public_id'])) {
            $businessId = Business::query()->where('public_id', (string) $validated['business_public_id'])->value('id');
        }

        $domainId = null;
        $rawHost = $validated['host'] ?? $request->getHost();
        if (is_string($rawHost) && $rawHost !== '') {
            $domain = $this->domainResolver->resolveActiveDomain($rawHost);
            $domainId = $domain?->id;
        }

        // Derive audience and userId strictly server-side from Sanctum session
        $audience = 'guest';
        $userId = null;
        $user = $request->user('sanctum');
        if ($user instanceof User && $user->status === 'active') {
            $userId = (int) $user->id;
            $audience = $user->hasRole('business_owner') || $user->hasRole('business_staff') ? 'merchant' : 'customer';
        }

        $sessionId = (string) ($validated['session_id'] ?? $request->header('X-Promo-Session', $request->ip() ?? ''));

        $context = new PromoNoticeDeliveryContext(
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
            businessId: $businessId !== null ? (int) $businessId : null,
            userId: $userId,
            sessionIdentifier: $sessionId,
        );

        $result = $this->deliveryService->deliver($context);

        return ApiResponse::success($result, headers: ['Cache-Control' => 'private, no-store']);
    }

    public function recordEvent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:2048'],
            'event_type' => ['required', 'string', Rule::in(['impression', 'click', 'dismiss'])],
            'session_id' => ['nullable', 'string', 'max:128'],
        ]);

        $user = $request->user('sanctum');
        $userId = ($user instanceof User && $user->status === 'active') ? (int) $user->id : null;
        $sessionId = (string) ($validated['session_id'] ?? $request->header('X-Promo-Session', ''));

        $result = $this->trackingService->recordEvent(
            token: (string) $validated['token'],
            eventType: (string) $validated['event_type'],
            userAgent: $request->userAgent(),
            ip: (string) ($request->ip() ?? '0.0.0.0'),
            sessionIdentifier: $sessionId,
            userId: $userId,
        );

        if (($result['reason'] ?? null) === 'invalid_or_expired_token') {
            return ApiResponse::error('INVALID_ATTRIBUTION_TOKEN', 'Invalid or expired notice attribution token.', 422);
        }

        return ApiResponse::success($result, headers: ['Cache-Control' => 'private, no-store']);
    }
}
