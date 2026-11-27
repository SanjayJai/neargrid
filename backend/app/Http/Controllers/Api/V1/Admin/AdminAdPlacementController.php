<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Advertising\Models\AdPlacement;
use App\Domains\Advertising\Services\AdPlacementConflictDetector;
use App\Domains\Governance\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class AdminAdPlacementController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly AdPlacementConflictDetector $conflictDetector,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'page_type' => ['nullable', 'string', Rule::in(['home', 'search', 'category', 'city_hub', 'business_profile', 'account', 'global'])],
            'render_format' => ['nullable', 'string', Rule::in(['banner', 'card', 'native_listing', 'text_link', 'carousel', 'strip'])],
            'device_scope' => ['nullable', 'string', Rule::in(['all', 'desktop', 'tablet', 'mobile'])],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $query = AdPlacement::query()->withCount('campaigns');

        if (! empty($validated['q'])) {
            $term = trim((string) $validated['q']);
            $query->where(static function ($q) use ($term): void {
                $q->where('key', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhere('route_pattern', 'like', "%{$term}%");
            });
        }
        if (! empty($validated['page_type'])) {
            $query->where('page_type', (string) $validated['page_type']);
        }
        if (! empty($validated['render_format'])) {
            $query->where('render_format', (string) $validated['render_format']);
        }
        if (! empty($validated['device_scope'])) {
            $query->where('device_scope', (string) $validated['device_scope']);
        }
        if (array_key_exists('is_active', $validated) && $validated['is_active'] !== null) {
            $query->where('is_active', (bool) $validated['is_active']);
        }

        $placements = $query->orderBy('sort_order')->orderBy('id')->paginate((int) ($validated['per_page'] ?? 50));

        return ApiResponse::paginated(
            $placements,
            collect($placements->items())->map(fn (AdPlacement $p): array => $this->serializePlacement($p))->values()->all(),
            ['conflicts' => $this->conflictDetector->detectConflicts()]
        );
    }

    public function show(AdPlacement $adPlacement): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $adPlacement->loadCount('campaigns');

        return ApiResponse::success($this->serializePlacement($adPlacement));
    }

    public function update(Request $request, AdPlacement $adPlacement): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'render_format' => ['sometimes', 'string', Rule::in(['banner', 'card', 'native_listing', 'text_link', 'carousel', 'strip'])],
            'device_scope' => ['sometimes', 'string', Rule::in(['all', 'desktop', 'tablet', 'mobile'])],
            'audience_scope' => ['sometimes', 'string', Rule::in(['all', 'guest', 'customer', 'merchant'])],
            'max_ads' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'width_px' => ['nullable', 'integer', 'min:50', 'max:2400'],
            'height_px' => ['nullable', 'integer', 'min:20', 'max:1200'],
            'supports_category_targeting' => ['sometimes', 'boolean'],
            'supports_location_targeting' => ['sometimes', 'boolean'],
            'supports_domain_targeting' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'config_json' => ['nullable', 'array'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $oldValues = [
            'is_active' => $adPlacement->is_active,
            'max_ads' => $adPlacement->max_ads,
            'device_scope' => $adPlacement->device_scope,
            'audience_scope' => $adPlacement->audience_scope,
        ];

        DB::transaction(function () use ($actor, $adPlacement, $validated, $oldValues, $request): void {
            $adPlacement->fill($validated)->save();

            $this->auditLogger->record(
                actor: $actor,
                action: 'ad_placement.updated',
                auditable: $adPlacement,
                oldValues: $oldValues,
                newValues: [
                    'is_active' => $adPlacement->is_active,
                    'max_ads' => $adPlacement->max_ads,
                    'device_scope' => $adPlacement->device_scope,
                    'audience_scope' => $adPlacement->audience_scope,
                ],
                actorRole: 'super_admin',
                ipAddress: $request->ip(),
            );
        });

        $adPlacement->loadCount('campaigns');

        return ApiResponse::success([
            'placement' => $this->serializePlacement($adPlacement),
            'conflicts' => $this->conflictDetector->detectConflicts(),
        ], ['message' => 'Ad placement updated.']);
    }

    public function conflicts(): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        return ApiResponse::success([
            'conflicts' => $this->conflictDetector->detectConflicts(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePlacement(AdPlacement $p): array
    {
        return [
            'public_id' => $p->public_id,
            'key' => $p->key,
            'name' => $p->name,
            'description' => $p->description,
            'page_type' => $p->page_type,
            'route_pattern' => $p->route_pattern,
            'slot_position' => $p->slot_position,
            'render_format' => $p->render_format,
            'device_scope' => $p->device_scope,
            'audience_scope' => $p->audience_scope,
            'max_ads' => (int) $p->max_ads,
            'width_px' => $p->width_px,
            'height_px' => $p->height_px,
            'supports_category_targeting' => (bool) $p->supports_category_targeting,
            'supports_location_targeting' => (bool) $p->supports_location_targeting,
            'supports_domain_targeting' => (bool) $p->supports_domain_targeting,
            'is_active' => (bool) $p->is_active,
            'sort_order' => (int) $p->sort_order,
            'config_json' => $p->config_json,
            'campaigns_count' => (int) ($p->campaigns_count ?? 0),
        ];
    }
}
