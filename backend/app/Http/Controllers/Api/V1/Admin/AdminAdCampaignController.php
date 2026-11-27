<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Advertising\Models\AdPlacement;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\Organization;
use App\Domains\Governance\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class AdminAdCampaignController extends Controller
{
    public const VALID_STATUSES = [
        'draft',
        'pending_approval',
        'approved',
        'rejected',
        'scheduled',
        'active',
        'paused',
        'completed',
        'archived',
    ];

    public const VALID_TYPES = [
        'sponsored_banner',
        'featured_listing',
        'category_spotlight',
        'city_takeover',
        'house_promo',
    ];

    /** @var array<string, list<string>> */
    private const ALLOWED_TRANSITIONS = [
        'draft' => ['pending_approval', 'archived'],
        'pending_approval' => ['draft', 'archived'],
        'approved' => ['scheduled', 'active', 'paused', 'completed', 'archived'],
        'scheduled' => ['active', 'paused', 'completed', 'archived'],
        'active' => ['paused', 'completed', 'archived'],
        'paused' => ['active', 'completed', 'archived'],
        'rejected' => ['draft', 'pending_approval', 'archived'],
        'completed' => ['archived'],
        'archived' => [],
    ];

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:140'],
            'status' => ['nullable', 'string', Rule::in(self::VALID_STATUSES)],
            'campaign_type' => ['nullable', 'string', Rule::in(self::VALID_TYPES)],
            'organization_public_id' => ['nullable', 'string', 'size:26'],
            'sort_by' => ['nullable', 'string', Rule::in(['created_at', 'name', 'priority', 'starts_at', 'ends_at'])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $query = AdCampaign::query()
            ->with(['organization:id,public_id,name,slug', 'approvedBy:id,public_id,name'])
            ->withCount(['creatives', 'placements', 'businesses']);

        if (! empty($validated['q'])) {
            $term = trim((string) $validated['q']);
            $query->where(static function ($q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            });
        }
        if (! empty($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }
        if (! empty($validated['campaign_type'])) {
            $query->where('campaign_type', (string) $validated['campaign_type']);
        }
        if (! empty($validated['organization_public_id'])) {
            $orgId = Organization::query()->where('public_id', $validated['organization_public_id'])->value('id');
            if ($orgId !== null) {
                $query->where('organization_id', $orgId);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $sortDir = (string) ($validated['sort_dir'] ?? 'desc');
        $campaigns = $query->orderBy($sortBy, $sortDir)->paginate((int) ($validated['per_page'] ?? 20));

        return ApiResponse::paginated(
            $campaigns,
            collect($campaigns->items())->map(fn (AdCampaign $c): array => $this->serializeSummary($c))->values()->all()
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'organization_public_id' => ['nullable', 'string', 'exists:organizations,public_id'],
            'campaign_type' => ['required', 'string', Rule::in(self::VALID_TYPES)],
            'status' => ['nullable', 'string', Rule::in(['draft', 'pending_approval'])],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'daily_impression_cap' => ['nullable', 'integer', 'min:1'],
            'total_impression_cap' => ['nullable', 'integer', 'min:1'],
        ]);

        if (! empty($validated['starts_at']) && ! empty($validated['ends_at']) && CarbonImmutable::parse((string) $validated['ends_at'])->lte(CarbonImmutable::parse((string) $validated['starts_at']))) {
            return ApiResponse::error('INVALID_SCHEDULE', 'Campaign end date must be after start date.', 422);
        }

        if (! empty($validated['daily_impression_cap']) && ! empty($validated['total_impression_cap']) && (int) $validated['total_impression_cap'] < (int) $validated['daily_impression_cap']) {
            return ApiResponse::error('INVALID_IMPRESSION_CAP', 'Total impression cap must be greater than or equal to daily impression cap.', 422);
        }

        /** @var User $actor */
        $actor = $request->user();
        $orgId = null;
        if (! empty($validated['organization_public_id'])) {
            $orgId = Organization::query()->where('public_id', $validated['organization_public_id'])->value('id');
        }

        $campaign = DB::transaction(function () use ($actor, $validated, $orgId, $request): AdCampaign {
            $record = AdCampaign::query()->create([
                'organization_id' => $orgId,
                'created_by_user_id' => $actor->id,
                'name' => trim((string) $validated['name']),
                'description' => isset($validated['description']) ? trim((string) $validated['description']) : null,
                'campaign_type' => (string) $validated['campaign_type'],
                'status' => (string) ($validated['status'] ?? 'draft'),
                'priority' => (int) ($validated['priority'] ?? 100),
                'starts_at' => $validated['starts_at'] ?? null,
                'ends_at' => $validated['ends_at'] ?? null,
                'daily_impression_cap' => $validated['daily_impression_cap'] ?? null,
                'total_impression_cap' => $validated['total_impression_cap'] ?? null,
            ]);

            $this->auditLogger->record(
                actor: $actor,
                action: 'ad_campaign.created',
                auditable: $record,
                newValues: ['name' => $record->name, 'status' => $record->status],
                actorRole: 'super_admin',
                ipAddress: $request->ip(),
            );

            return $record;
        });

        $campaign->load(['organization:id,public_id,name,slug'])->loadCount(['creatives', 'placements', 'businesses']);

        return ApiResponse::created(
            $this->serializeSummary($campaign),
            ['message' => 'Ad campaign created.']
        );
    }

    public function show(Request $request, AdCampaign $adCampaign): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $adCampaign->load([
            'organization:id,public_id,name,slug',
            'approvedBy:id,public_id,name',
            'creatives',
            'placements',
            'businesses:id,public_id,name,slug,organization_id,status',
        ]);

        $availablePlacements = AdPlacement::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['public_id', 'key', 'name', 'page_type', 'render_format', 'device_scope'])
            ->map(static fn (AdPlacement $p): array => [
                'public_id' => $p->public_id,
                'key' => $p->key,
                'name' => $p->name,
                'page_type' => $p->page_type,
                'render_format' => $p->render_format,
                'device_scope' => $p->device_scope,
            ])
            ->values()
            ->all();

        return ApiResponse::success([
            'campaign' => $this->serializeSummary($adCampaign),
            'creatives' => $adCampaign->creatives->map(static fn ($cr): array => [
                'public_id' => $cr->public_id,
                'title' => $cr->title,
                'subtitle' => $cr->subtitle,
                'cta_label' => $cr->cta_label,
                'destination_url' => $cr->destination_url,
                'image_url' => $cr->image_url,
                'mobile_image_url' => $cr->mobile_image_url,
                'alt_text' => $cr->alt_text,
                'locale' => $cr->locale,
                'status' => $cr->status,
            ])->values()->all(),
            'placements' => $adCampaign->placements->map(static fn (AdPlacement $pl): array => [
                'public_id' => $pl->public_id,
                'key' => $pl->key,
                'name' => $pl->name,
                'page_type' => $pl->page_type,
                'render_format' => $pl->render_format,
                'weight' => (int) ($pl->pivot->weight ?? 100),
                'is_active' => (bool) ($pl->pivot->is_active ?? true),
            ])->values()->all(),
            'businesses' => $adCampaign->businesses->map(static fn (Business $b): array => [
                'public_id' => $b->public_id,
                'name' => $b->name,
                'slug' => $b->slug,
                'status' => $b->status?->value ?? (string) $b->status,
                'is_primary' => (bool) ($b->pivot->is_primary ?? false),
            ])->values()->all(),
            'available_placements' => $availablePlacements,
        ]);
    }

    public function update(Request $request, AdCampaign $adCampaign): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'campaign_type' => ['sometimes', 'string', Rule::in(self::VALID_TYPES)],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'daily_impression_cap' => ['nullable', 'integer', 'min:1'],
            'total_impression_cap' => ['nullable', 'integer', 'min:1'],
        ]);

        $effectiveStarts = array_key_exists('starts_at', $validated) ? $validated['starts_at'] : $adCampaign->starts_at?->toIso8601String();
        $effectiveEnds = array_key_exists('ends_at', $validated) ? $validated['ends_at'] : $adCampaign->ends_at?->toIso8601String();
        if ($effectiveStarts !== null && $effectiveEnds !== null && CarbonImmutable::parse($effectiveEnds)->lte(CarbonImmutable::parse($effectiveStarts))) {
            return ApiResponse::error('INVALID_SCHEDULE', 'Campaign end date must be after start date.', 422);
        }

        $effectiveDailyCap = array_key_exists('daily_impression_cap', $validated) ? $validated['daily_impression_cap'] : $adCampaign->daily_impression_cap;
        $effectiveTotalCap = array_key_exists('total_impression_cap', $validated) ? $validated['total_impression_cap'] : $adCampaign->total_impression_cap;
        if ($effectiveDailyCap !== null && $effectiveTotalCap !== null && (int) $effectiveTotalCap < (int) $effectiveDailyCap) {
            return ApiResponse::error('INVALID_IMPRESSION_CAP', 'Total impression cap must be greater than or equal to daily impression cap.', 422);
        }

        /** @var User $actor */
        $actor = $request->user();
        $oldValues = ['name' => $adCampaign->name, 'priority' => $adCampaign->priority];

        DB::transaction(function () use ($actor, $adCampaign, $validated, $oldValues, $request): void {
            $adCampaign->fill($validated)->save();

            $this->auditLogger->record(
                actor: $actor,
                action: 'ad_campaign.updated',
                auditable: $adCampaign,
                oldValues: $oldValues,
                newValues: ['name' => $adCampaign->name, 'priority' => $adCampaign->priority],
                actorRole: 'super_admin',
                ipAddress: $request->ip(),
            );
        });

        $adCampaign->load(['organization:id,public_id,name,slug'])->loadCount(['creatives', 'placements', 'businesses']);

        return ApiResponse::success($this->serializeSummary($adCampaign), ['message' => 'Campaign updated.']);
    }

    public function approve(Request $request, AdCampaign $adCampaign): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        if (! in_array($adCampaign->status, ['draft', 'pending_approval', 'rejected'], true)) {
            return ApiResponse::error('INVALID_LIFECYCLE_STATE', "Campaign in status '{$adCampaign->status}' cannot be approved.", 422);
        }

        $validated = $request->validate([
            'activate_immediately' => ['nullable', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $oldStatus = $adCampaign->status;
        $activate = (bool) ($validated['activate_immediately'] ?? true);

        $targetStatus = 'approved';
        if ($activate) {
            $targetStatus = ($adCampaign->starts_at !== null && $adCampaign->starts_at->isFuture())
                ? 'scheduled'
                : 'active';
        }

        DB::transaction(function () use ($actor, $adCampaign, $oldStatus, $targetStatus, $request): void {
            $adCampaign->status = $targetStatus;
            $adCampaign->approved_by_admin_id = $actor->id;
            $adCampaign->approved_at = now();
            $adCampaign->rejection_reason = null;
            $adCampaign->save();

            $this->auditLogger->record(
                actor: $actor,
                action: 'ad_campaign.approved',
                auditable: $adCampaign,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => $targetStatus],
                actorRole: 'super_admin',
                ipAddress: $request->ip(),
            );
        });

        $adCampaign->load(['organization:id,public_id,name,slug'])->loadCount(['creatives', 'placements', 'businesses']);

        return ApiResponse::success($this->serializeSummary($adCampaign), ['message' => "Campaign approved ({$targetStatus})."]);
    }

    public function reject(Request $request, AdCampaign $adCampaign): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        if (! in_array($adCampaign->status, ['draft', 'pending_approval', 'approved', 'scheduled'], true)) {
            return ApiResponse::error('INVALID_LIFECYCLE_STATE', "Campaign in status '{$adCampaign->status}' cannot be rejected.", 422);
        }

        /** @var User $actor */
        $actor = $request->user();
        $oldStatus = $adCampaign->status;

        DB::transaction(function () use ($actor, $adCampaign, $oldStatus, $validated, $request): void {
            $adCampaign->status = 'rejected';
            $adCampaign->rejection_reason = trim((string) $validated['rejection_reason']);
            $adCampaign->save();

            $this->auditLogger->record(
                actor: $actor,
                action: 'ad_campaign.rejected',
                auditable: $adCampaign,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => 'rejected', 'rejection_reason' => $adCampaign->rejection_reason],
                actorRole: 'super_admin',
                ipAddress: $request->ip(),
            );
        });

        $adCampaign->load(['organization:id,public_id,name,slug'])->loadCount(['creatives', 'placements', 'businesses']);

        return ApiResponse::success($this->serializeSummary($adCampaign), ['message' => 'Campaign rejected.']);
    }

    public function updateStatus(Request $request, AdCampaign $adCampaign): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(self::VALID_STATUSES)],
        ]);

        $targetStatus = (string) $validated['status'];
        $allowed = self::ALLOWED_TRANSITIONS[$adCampaign->status] ?? [];
        if (! in_array($targetStatus, $allowed, true)) {
            return ApiResponse::error(
                'INVALID_LIFECYCLE_TRANSITION',
                "Transition from '{$adCampaign->status}' to '{$targetStatus}' is not permitted.",
                422
            );
        }

        /** @var User $actor */
        $actor = $request->user();
        $oldStatus = $adCampaign->status;

        DB::transaction(function () use ($actor, $adCampaign, $oldStatus, $targetStatus, $request): void {
            $adCampaign->status = $targetStatus;
            $adCampaign->save();

            $this->auditLogger->record(
                actor: $actor,
                action: 'ad_campaign.status_updated',
                auditable: $adCampaign,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => $targetStatus],
                actorRole: 'super_admin',
                ipAddress: $request->ip(),
            );
        });

        $adCampaign->load(['organization:id,public_id,name,slug'])->loadCount(['creatives', 'placements', 'businesses']);

        return ApiResponse::success($this->serializeSummary($adCampaign), ['message' => "Campaign status updated to {$targetStatus}."]);
    }

    public function syncPlacements(Request $request, AdCampaign $adCampaign): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $validated = $request->validate([
            'placements' => ['required', 'array'],
            'placements.*.placement_public_id' => ['required', 'string', 'exists:ad_placements,public_id'],
            'placements.*.weight' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'placements.*.is_active' => ['nullable', 'boolean'],
        ]);

        $syncMap = [];
        $publicIds = array_column($validated['placements'], 'placement_public_id');
        $placementsByPublicId = AdPlacement::query()->whereIn('public_id', $publicIds)->get()->keyBy('public_id');

        foreach ($validated['placements'] as $item) {
            $pl = $placementsByPublicId->get($item['placement_public_id']);
            if ($pl !== null) {
                $syncMap[$pl->id] = [
                    'weight' => (int) ($item['weight'] ?? 100),
                    'is_active' => (bool) ($item['is_active'] ?? true),
                ];
            }
        }

        /** @var User $actor */
        $actor = $request->user();

        DB::transaction(function () use ($actor, $adCampaign, $syncMap, $request): void {
            $adCampaign->placements()->sync($syncMap);

            $this->auditLogger->record(
                actor: $actor,
                action: 'ad_campaign.placements_synced',
                auditable: $adCampaign,
                newValues: ['placement_count' => count($syncMap)],
                actorRole: 'super_admin',
                ipAddress: $request->ip(),
            );
        });

        return ApiResponse::success(['placement_count' => count($syncMap)], ['message' => 'Campaign placements updated.']);
    }

    public function syncBusinesses(Request $request, AdCampaign $adCampaign): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $validated = $request->validate([
            'business_public_ids' => ['required', 'array'],
            'business_public_ids.*' => ['string', 'exists:businesses,public_id'],
            'primary_business_public_id' => ['nullable', 'string'],
        ]);

        $businesses = Business::query()->whereIn('public_id', $validated['business_public_ids'])->get();
        if ($adCampaign->organization_id !== null) {
            foreach ($businesses as $biz) {
                if ((int) $biz->organization_id !== (int) $adCampaign->organization_id) {
                    return ApiResponse::error(
                        'ORGANIZATION_ISOLATION_VIOLATION',
                        "Business '{$biz->name}' does not belong to the campaign's organization.",
                        422
                    );
                }
            }
        }

        $primaryPublicId = $validated['primary_business_public_id'] ?? ($validated['business_public_ids'][0] ?? null);
        $syncMap = [];
        foreach ($businesses as $biz) {
            $syncMap[$biz->id] = [
                'is_primary' => $biz->public_id === $primaryPublicId,
            ];
        }

        /** @var User $actor */
        $actor = $request->user();

        DB::transaction(function () use ($actor, $adCampaign, $syncMap, $request): void {
            $adCampaign->businesses()->sync($syncMap);

            $this->auditLogger->record(
                actor: $actor,
                action: 'ad_campaign.businesses_synced',
                auditable: $adCampaign,
                newValues: ['business_count' => count($syncMap)],
                actorRole: 'super_admin',
                ipAddress: $request->ip(),
            );
        });

        return ApiResponse::success(['business_count' => count($syncMap)], ['message' => 'Campaign businesses updated.']);
    }

    public function destroy(Request $request, AdCampaign $adCampaign): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        /** @var User $actor */
        $actor = $request->user();

        DB::transaction(function () use ($actor, $adCampaign, $request): void {
            $adCampaign->status = 'archived';
            $adCampaign->save();
            $adCampaign->delete();

            $this->auditLogger->record(
                actor: $actor,
                action: 'ad_campaign.archived',
                auditable: $adCampaign,
                actorRole: 'super_admin',
                ipAddress: $request->ip(),
            );
        });

        return ApiResponse::success(['archived' => true], ['message' => 'Campaign archived.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSummary(AdCampaign $c): array
    {
        return [
            'public_id' => $c->public_id,
            'name' => $c->name,
            'description' => $c->description,
            'campaign_type' => $c->campaign_type,
            'status' => $c->status,
            'priority' => (int) $c->priority,
            'starts_at' => $c->starts_at?->toIso8601String(),
            'ends_at' => $c->ends_at?->toIso8601String(),
            'daily_impression_cap' => $c->daily_impression_cap,
            'total_impression_cap' => $c->total_impression_cap,
            'approved_at' => $c->approved_at?->toIso8601String(),
            'rejection_reason' => $c->rejection_reason,
            'organization' => $c->organization ? [
                'public_id' => $c->organization->public_id,
                'name' => $c->organization->name,
                'slug' => $c->organization->slug,
            ] : null,
            'creatives_count' => (int) ($c->creatives_count ?? 0),
            'placements_count' => (int) ($c->placements_count ?? 0),
            'businesses_count' => (int) ($c->businesses_count ?? 0),
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
