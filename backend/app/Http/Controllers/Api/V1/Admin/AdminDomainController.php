<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Directory\Models\Organization;
use App\Domains\Domains\Contracts\DomainChallengeProber;
use App\Domains\Domains\Exceptions\DomainException;
use App\Domains\Domains\Models\Domain;
use App\Domains\Domains\Services\DomainResolutionService;
use App\Domains\Domains\Services\DomainVerificationService;
use App\Domains\Governance\Services\AuditLogger;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\State;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class AdminDomainController extends Controller
{
    public function __construct(
        private readonly DomainResolutionService $resolutionService,
        private readonly DomainVerificationService $verificationService,
        private readonly DomainChallengeProber $prober,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAdmin', Domain::class);

        $query = Domain::query()
            ->with(['defaultCity', 'defaultState', 'organization', 'canonicalDomain'])
            ->orderByDesc('is_primary')
            ->orderBy('hostname');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        if ($request->filled('domain_type')) {
            $query->where('domain_type', (string) $request->query('domain_type'));
        }
        if ($request->filled('verification_status')) {
            $query->where('verification_status', (string) $request->query('verification_status'));
        }
        if ($request->filled('ssl_status')) {
            $query->where('ssl_status', (string) $request->query('ssl_status'));
        }

        $perPage = min(50, max(1, (int) $request->query('per_page', 20)));
        $paginator = $query->paginate($perPage);

        $items = $paginator->getCollection()
            ->map(fn (Domain $domain): array => $this->serializeDomain($domain))
            ->values()
            ->all();

        return ApiResponse::success($items, [
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Domain::class);

        $validated = $request->validate([
            'hostname' => ['required', 'string', 'max:253'],
            'domain_type' => ['required', 'string', Rule::in(['primary', 'city_portal', 'brand_microsite'])],
            'verification_method' => ['nullable', 'string', Rule::in(['dns_txt', 'http_file'])],
            'ssl_provider' => ['nullable', 'string', Rule::in(['cloudflare', 'nginx_certbot'])],
            'visibility_mode' => ['nullable', 'string', Rule::in(['global', 'location_scoped', 'organization_scoped'])],
            'default_city_public_id' => ['nullable', 'string', 'size:26'],
            'default_state_public_id' => ['nullable', 'string', 'size:26'],
            'default_locale' => ['nullable', 'string', Rule::in(['en', 'ta'])],
            'organization_public_id' => ['nullable', 'string', 'size:26'],
            'canonical_domain_public_id' => ['nullable', 'string', 'size:26'],
            'is_canonical_for_location' => ['nullable', 'boolean'],
            'is_indexable' => ['nullable', 'boolean'],
        ]);

        try {
            $hostname = $this->resolutionService->normalizeHostname($validated['hostname']);
        } catch (DomainException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        if (Domain::withTrashed()->where('hostname', $hostname)->exists()) {
            return ApiResponse::error(
                'DOMAIN_ALREADY_EXISTS',
                'A domain with this hostname already exists.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $cityId = isset($validated['default_city_public_id'])
            ? City::query()->where('public_id', $validated['default_city_public_id'])->value('id')
            : null;
        $stateId = isset($validated['default_state_public_id'])
            ? State::query()->where('public_id', $validated['default_state_public_id'])->value('id')
            : null;
        $orgId = isset($validated['organization_public_id'])
            ? Organization::query()->where('public_id', $validated['organization_public_id'])->value('id')
            : null;
        $canonicalId = isset($validated['canonical_domain_public_id'])
            ? Domain::query()->where('public_id', $validated['canonical_domain_public_id'])->value('id')
            : null;

        /** @var User $actor */
        $actor = $request->user();

        $domain = Domain::query()->create([
            'hostname' => $hostname,
            'domain_type' => $validated['domain_type'],
            'is_primary' => false,
            'primary_slot' => null,
            'status' => 'draft',
            'verification_method' => $validated['verification_method'] ?? 'dns_txt',
            'verification_status' => 'unverified',
            'ssl_provider' => $validated['ssl_provider'] ?? 'cloudflare',
            'ssl_status' => 'not_started',
            'visibility_mode' => $validated['visibility_mode'] ?? 'global',
            'default_city_id' => $cityId,
            'default_state_id' => $stateId,
            'default_locale' => $validated['default_locale'] ?? 'en',
            'organization_id' => $orgId,
            'canonical_domain_id' => $canonicalId,
            'is_canonical_for_location' => (bool) ($validated['is_canonical_for_location'] ?? false),
            'is_indexable' => (bool) ($validated['is_indexable'] ?? false),
            'created_by_user_id' => $actor->id,
        ]);

        $this->auditLogger->record(
            actor: $actor,
            action: 'domain.created',
            auditable: $domain,
            newValues: ['hostname' => $domain->hostname, 'domain_type' => $domain->domain_type],
            ipAddress: $request->ip(),
        );

        return ApiResponse::created($this->serializeDomain($domain->load(['defaultCity', 'defaultState', 'organization', 'canonicalDomain'])));
    }

    public function show(Domain $domain): JsonResponse
    {
        Gate::authorize('viewAdmin', Domain::class);

        return ApiResponse::success(
            $this->serializeDomain($domain->load(['defaultCity', 'defaultState', 'organization', 'canonicalDomain']))
        );
    }

    public function update(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);

        $validated = $request->validate([
            'verification_method' => ['sometimes', 'string', Rule::in(['dns_txt', 'http_file'])],
            'ssl_provider' => ['sometimes', 'string', Rule::in(['cloudflare', 'nginx_certbot'])],
            'visibility_mode' => ['sometimes', 'string', Rule::in(['global', 'location_scoped', 'organization_scoped'])],
            'default_city_public_id' => ['nullable', 'string', 'size:26'],
            'default_state_public_id' => ['nullable', 'string', 'size:26'],
            'default_locale' => ['sometimes', 'string', Rule::in(['en', 'ta'])],
            'organization_public_id' => ['nullable', 'string', 'size:26'],
            'canonical_domain_public_id' => ['nullable', 'string', 'size:26'],
            'is_canonical_for_location' => ['sometimes', 'boolean'],
            'is_indexable' => ['sometimes', 'boolean'],
        ]);

        $oldValues = [
            'visibility_mode' => $domain->visibility_mode,
            'default_city_id' => $domain->default_city_id,
            'is_canonical_for_location' => $domain->is_canonical_for_location,
            'is_indexable' => $domain->is_indexable,
        ];

        if (array_key_exists('verification_method', $validated)) {
            $domain->verification_method = $validated['verification_method'];
        }
        if (array_key_exists('ssl_provider', $validated)) {
            $domain->ssl_provider = $validated['ssl_provider'];
        }
        if (array_key_exists('visibility_mode', $validated)) {
            $domain->visibility_mode = $validated['visibility_mode'];
        }
        if (array_key_exists('default_locale', $validated)) {
            $domain->default_locale = $validated['default_locale'];
        }
        if (array_key_exists('is_canonical_for_location', $validated)) {
            $domain->is_canonical_for_location = (bool) $validated['is_canonical_for_location'];
        }
        if (array_key_exists('is_indexable', $validated)) {
            $domain->is_indexable = (bool) $validated['is_indexable'];
        }
        if (array_key_exists('default_city_public_id', $validated)) {
            $domain->default_city_id = $validated['default_city_public_id'] !== null
                ? City::query()->where('public_id', $validated['default_city_public_id'])->value('id')
                : null;
        }
        if (array_key_exists('default_state_public_id', $validated)) {
            $domain->default_state_id = $validated['default_state_public_id'] !== null
                ? State::query()->where('public_id', $validated['default_state_public_id'])->value('id')
                : null;
        }
        if (array_key_exists('organization_public_id', $validated)) {
            $domain->organization_id = $validated['organization_public_id'] !== null
                ? Organization::query()->where('public_id', $validated['organization_public_id'])->value('id')
                : null;
        }
        if (array_key_exists('canonical_domain_public_id', $validated)) {
            $canonicalId = $validated['canonical_domain_public_id'] !== null
                ? Domain::query()->where('public_id', $validated['canonical_domain_public_id'])->value('id')
                : null;
            if ($canonicalId === $domain->id) {
                return ApiResponse::error('CANONICAL_SELF_REFERENCE', 'Domain cannot reference itself as canonical.', 422);
            }
            $domain->canonical_domain_id = $canonicalId;
        }

        $domain->save();

        /** @var User $actor */
        $actor = $request->user();
        $this->auditLogger->record(
            actor: $actor,
            action: 'domain.updated',
            auditable: $domain,
            oldValues: $oldValues,
            newValues: [
                'visibility_mode' => $domain->visibility_mode,
                'default_city_id' => $domain->default_city_id,
                'is_canonical_for_location' => $domain->is_canonical_for_location,
                'is_indexable' => $domain->is_indexable,
            ],
            ipAddress: $request->ip(),
        );

        return ApiResponse::success(
            $this->serializeDomain($domain->fresh(['defaultCity', 'defaultState', 'organization', 'canonicalDomain']))
        );
    }

    public function issueChallenge(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('verify', $domain);

        /** @var User $actor */
        $actor = $request->user();
        $token = $this->verificationService->issueChallenge($domain, $actor, $request->ip());
        $domain->refresh();

        $recordHost = "_neargrid-verify.{$domain->hostname}";

        return ApiResponse::success([
            'public_id' => $domain->public_id,
            'verification_method' => $domain->verification_method,
            'verification_status' => $domain->verification_status,
            'challenge_token' => $token,
            'challenge_expires_at' => $domain->challenge_expires_at?->toIso8601String(),
            'dns_txt_record' => $recordHost,
            'http_file_path' => '/.well-known/neargrid-verify.txt',
        ]);
    }

    public function verify(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('verify', $domain);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $verified = $this->verificationService->verifyChallenge(
                $domain,
                fn (Domain $d): array => $this->prober->fetchChallengeTokens($d),
                $actor,
                $request->ip(),
            );
        } catch (DomainException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return ApiResponse::success(
            $this->serializeDomain($verified->load(['defaultCity', 'defaultState', 'organization', 'canonicalDomain']))
        );
    }

    public function checkSsl(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('manageSsl', $domain);

        /** @var User $actor */
        $actor = $request->user();
        $validated = $this->prober->probeSslCertificate($domain);

        $updated = $this->verificationService->updateSslStatus(
            $domain,
            $validated ? 'active' : 'failed',
            $validated,
            $actor,
            $request->ip(),
        );

        return ApiResponse::success(
            $this->serializeDomain($updated->load(['defaultCity', 'defaultState', 'organization', 'canonicalDomain']))
        );
    }

    public function promotePrimary(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('promotePrimary', $domain);

        try {
            $promoted = $this->resolutionService->promotePrimaryDomain($domain);
        } catch (DomainException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        /** @var User $actor */
        $actor = $request->user();
        $this->auditLogger->record(
            actor: $actor,
            action: 'domain.primary_promoted',
            auditable: $promoted,
            newValues: ['hostname' => $promoted->hostname, 'primary_slot' => $promoted->primary_slot],
            ipAddress: $request->ip(),
        );

        return ApiResponse::success(
            $this->serializeDomain($promoted->load(['defaultCity', 'defaultState', 'organization', 'canonicalDomain']))
        );
    }

    public function suspend(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('update', $domain);

        if ($domain->is_primary || $domain->primary_slot === 1) {
            return ApiResponse::error(
                'CANNOT_SUSPEND_PRIMARY_DOMAIN',
                'Promote another primary domain before suspending the active primary domain.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $oldStatus = $domain->status;
        $domain->status = 'suspended';
        $domain->save();

        /** @var User $actor */
        $actor = $request->user();
        $this->auditLogger->record(
            actor: $actor,
            action: 'domain.suspended',
            auditable: $domain,
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => 'suspended'],
            ipAddress: $request->ip(),
        );

        return ApiResponse::success(
            $this->serializeDomain($domain->fresh(['defaultCity', 'defaultState', 'organization', 'canonicalDomain']))
        );
    }

    public function destroy(Request $request, Domain $domain): JsonResponse
    {
        Gate::authorize('delete', $domain);

        if ($domain->is_primary || $domain->primary_slot === 1) {
            return ApiResponse::error(
                'CANNOT_DELETE_PRIMARY_DOMAIN',
                'Promote another primary domain before deleting the active primary domain.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        /** @var User $actor */
        $actor = $request->user();
        $this->auditLogger->record(
            actor: $actor,
            action: 'domain.deleted',
            auditable: $domain,
            oldValues: ['hostname' => $domain->hostname],
            ipAddress: $request->ip(),
        );

        $domain->delete();

        return ApiResponse::noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDomain(Domain $domain): array
    {
        return [
            'public_id' => $domain->public_id,
            'hostname' => $domain->hostname,
            'domain_type' => $domain->domain_type,
            'is_primary' => $domain->is_primary,
            'status' => $domain->status,
            'verification_method' => $domain->verification_method,
            'verification_status' => $domain->verification_status,
            'verification_attempts' => $domain->verification_attempts,
            'verified_at' => $domain->verified_at?->toIso8601String(),
            'ssl_provider' => $domain->ssl_provider,
            'ssl_status' => $domain->ssl_status,
            'ssl_checked_at' => $domain->ssl_checked_at?->toIso8601String(),
            'visibility_mode' => $domain->visibility_mode,
            'default_locale' => $domain->default_locale,
            'default_city' => $domain->defaultCity ? [
                'public_id' => $domain->defaultCity->public_id,
                'slug' => $domain->defaultCity->slug,
                'name' => $domain->defaultCity->name,
            ] : null,
            'default_state' => $domain->defaultState ? [
                'public_id' => $domain->defaultState->public_id,
                'slug' => $domain->defaultState->slug,
                'name' => $domain->defaultState->name,
            ] : null,
            'organization' => $domain->organization ? [
                'public_id' => $domain->organization->public_id,
                'name' => $domain->organization->name,
                'slug' => $domain->organization->slug,
            ] : null,
            'canonical_domain' => $domain->canonicalDomain ? [
                'public_id' => $domain->canonicalDomain->public_id,
                'hostname' => $domain->canonicalDomain->hostname,
            ] : null,
            'is_canonical_for_location' => $domain->is_canonical_for_location,
            'is_indexable' => $domain->is_indexable,
            'effectively_indexable' => $domain->isEffectivelyIndexable(),
            'created_at' => $domain->created_at?->toIso8601String(),
        ];
    }
}
