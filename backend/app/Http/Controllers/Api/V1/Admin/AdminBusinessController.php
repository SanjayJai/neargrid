<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Directory\Enums\BusinessStatus;
use App\Domains\Directory\Jobs\DispatchRevalidationWebhookJob;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessVerification;
use App\Domains\Governance\Models\AuditLog;
use App\Domains\Governance\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class AdminBusinessController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAdmin', AuditLog::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(BusinessStatus::values())],
            'city_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'is_verified' => ['nullable', 'boolean'],
            'is_trusted' => ['nullable', 'boolean'],
            'is_claimed' => ['nullable', 'boolean'],
            'branch_type' => ['nullable', 'string', Rule::in(['headquarters', 'company_owned', 'franchise'])],
            'sort_by' => ['nullable', 'string', Rule::in(['created_at', 'name', 'completeness_score', 'rating_avg', 'review_count'])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $query = Business::query()
            ->with([
                'city:id,public_id,name,slug',
                'locality:id,public_id,name,slug',
                'primaryCategory:id,public_id,name,slug',
                'organization:id,public_id,name,slug',
                'owner:id,public_id,name,email',
            ]);

        if (! empty($validated['q'])) {
            $term = trim((string) $validated['q']);
            $query->where(static function ($q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('slug', 'like', "%{$term}%")
                    ->orWhere('primary_phone_e164', 'like', "%{$term}%");
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }
        if (isset($validated['city_id'])) {
            $query->where('city_id', (int) $validated['city_id']);
        }
        if (isset($validated['category_id'])) {
            $query->where('primary_category_id', (int) $validated['category_id']);
        }
        if (array_key_exists('is_verified', $validated) && $validated['is_verified'] !== null) {
            $query->where('is_verified', (bool) $validated['is_verified']);
        }
        if (array_key_exists('is_trusted', $validated) && $validated['is_trusted'] !== null) {
            $query->where('is_trusted', (bool) $validated['is_trusted']);
        }
        if (array_key_exists('is_claimed', $validated) && $validated['is_claimed'] !== null) {
            $query->where('is_claimed', (bool) $validated['is_claimed']);
        }
        if (! empty($validated['branch_type'])) {
            $query->where('branch_type', (string) $validated['branch_type']);
        }

        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $sortDir = (string) ($validated['sort_dir'] ?? 'desc');
        $perPage = (int) ($validated['per_page'] ?? 20);

        $businesses = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        return ApiResponse::paginated(
            $businesses,
            collect($businesses->items())->map(fn (Business $b): array => $this->serializeBusiness($b))->values()->all()
        );
    }

    public function show(Request $request, Business $business): JsonResponse
    {
        Gate::authorize('viewAdmin', AuditLog::class);

        $business->load([
            'city:id,public_id,name,slug',
            'locality:id,public_id,name,slug',
            'primaryCategory:id,public_id,name,slug',
            'organization:id,public_id,name,slug,status',
            'canonicalDomain:id,public_id,hostname,status',
            'owner:id,public_id,name,email',
        ]);

        return ApiResponse::success($this->serializeBusiness($business));
    }

    public function updateStatus(Request $request, Business $business): JsonResponse
    {
        Gate::authorize('viewAdmin', AuditLog::class);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(BusinessStatus::values())],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $oldStatus = $business->status?->value ?? (string) $business->status;
        $newStatus = (string) $validated['status'];

        DB::transaction(function () use ($actor, $business, $oldStatus, $newStatus, $validated, $request): void {
            $business->status = BusinessStatus::from($newStatus);
            $business->save();

            $this->auditLogger->record(
                actor: $actor,
                action: 'business.admin_status_updated',
                auditable: $business,
                oldValues: ['status' => $oldStatus],
                newValues: [
                    'status' => $newStatus,
                    'reason' => $validated['reason'] ?? null,
                ],
                ipAddress: $request->ip(),
            );

            $this->dispatchRevalidation($business, 'admin_business_status');
        });

        $business->load(['city:id,public_id,name,slug', 'locality:id,public_id,name,slug', 'primaryCategory:id,public_id,name,slug', 'organization:id,public_id,name,slug', 'owner:id,public_id,name,email']);

        return ApiResponse::success(
            $this->serializeBusiness($business),
            ['message' => "Business status updated to {$newStatus}."]
        );
    }

    public function updateBadges(Request $request, Business $business): JsonResponse
    {
        Gate::authorize('viewAdmin', AuditLog::class);

        $validated = $request->validate([
            'is_verified' => ['nullable', 'boolean'],
            'is_trusted' => ['nullable', 'boolean'],
        ]);

        if (($validated['is_verified'] ?? null) === true) {
            $hasApprovedKyc = BusinessVerification::query()
                ->where('business_id', $business->id)
                ->where('status', 'approved')
                ->exists();

            if (! $hasApprovedKyc) {
                return ApiResponse::error(
                    'KYC_APPROVAL_REQUIRED',
                    'Verified badge requires an approved KYC verification via the KYC queue.',
                    422
                );
            }
        }

        /** @var User $actor */
        $actor = $request->user();
        $oldValues = [
            'is_verified' => $business->is_verified,
            'is_trusted' => $business->is_trusted,
        ];

        DB::transaction(function () use ($actor, $business, $validated, $oldValues, $request): void {
            if (array_key_exists('is_verified', $validated) && $validated['is_verified'] !== null) {
                $business->is_verified = (bool) $validated['is_verified'];
                $business->verified_at = $business->is_verified ? ($business->verified_at ?? now()) : null;
            }

            if (array_key_exists('is_trusted', $validated) && $validated['is_trusted'] !== null) {
                $business->is_trusted = (bool) $validated['is_trusted'];
                $business->trusted_at = $business->is_trusted ? ($business->trusted_at ?? now()) : null;
            }

            $business->save();

            $this->auditLogger->record(
                actor: $actor,
                action: 'business.admin_badges_updated',
                auditable: $business,
                oldValues: $oldValues,
                newValues: [
                    'is_verified' => $business->is_verified,
                    'is_trusted' => $business->is_trusted,
                ],
                ipAddress: $request->ip(),
            );

            $this->dispatchRevalidation($business, 'admin_business_badges');
        });

        $business->load(['city:id,public_id,name,slug', 'locality:id,public_id,name,slug', 'primaryCategory:id,public_id,name,slug', 'organization:id,public_id,name,slug', 'owner:id,public_id,name,email']);

        return ApiResponse::success(
            $this->serializeBusiness($business),
            ['message' => 'Business trust badges updated.']
        );
    }

    private function dispatchRevalidation(Business $business, string $reason): void
    {
        $business->loadMissing(['city:id,slug', 'primaryCategory:id,slug']);
        $citySlug = $business->city?->slug ?? 'chennai';
        $paths = ["/{$citySlug}"];
        if ($business->slug) {
            $paths[] = "/{$citySlug}/business/{$business->slug}";
        }
        if ($business->primaryCategory?->slug) {
            $paths[] = "/{$citySlug}/{$business->primaryCategory->slug}";
        }
        DispatchRevalidationWebhookJob::dispatch($paths, $reason)->afterCommit();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeBusiness(Business $b): array
    {
        return [
            'public_id' => $b->public_id,
            'name' => $b->name,
            'slug' => $b->slug,
            'status' => $b->status?->value ?? (string) $b->status,
            'is_claimed' => (bool) $b->is_claimed,
            'is_verified' => (bool) $b->is_verified,
            'is_trusted' => (bool) $b->is_trusted,
            'branch_type' => $b->branch_type,
            'franchise_code' => $b->franchise_code,
            'allow_hq_lead_access' => (bool) $b->allow_hq_lead_access,
            'primary_phone_e164' => $b->primary_phone_e164,
            'email' => $b->email,
            'completeness_score' => (int) $b->completeness_score,
            'rating_avg' => (float) $b->rating_avg,
            'review_count' => (int) $b->review_count,
            'view_count' => (int) $b->view_count,
            'city' => $b->city ? [
                'public_id' => $b->city->public_id,
                'name' => $b->city->name,
                'slug' => $b->city->slug,
            ] : null,
            'locality' => $b->locality ? [
                'public_id' => $b->locality->public_id,
                'name' => $b->locality->name,
                'slug' => $b->locality->slug,
            ] : null,
            'primary_category' => $b->primaryCategory ? [
                'public_id' => $b->primaryCategory->public_id,
                'name' => $b->primaryCategory->name,
                'slug' => $b->primaryCategory->slug,
            ] : null,
            'organization' => $b->organization ? [
                'public_id' => $b->organization->public_id,
                'name' => $b->organization->name,
                'slug' => $b->organization->slug,
            ] : null,
            'owner' => $b->owner ? [
                'public_id' => $b->owner->public_id,
                'name' => $b->owner->name,
                'email' => $b->owner->email,
            ] : null,
            'created_at' => $b->created_at?->toIso8601String(),
        ];
    }
}
