<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Governance\Models\UrlRedirect;
use App\Domains\Governance\Services\AuditLogger;
use App\Domains\Seo\Exceptions\SeoException;
use App\Domains\Seo\Models\SeoMetadata;
use App\Domains\Seo\Models\SeoRevision;
use App\Domains\Seo\Services\RedirectLifecycleService;
use App\Domains\Seo\Services\SeoEntityRegistry;
use App\Domains\Seo\Services\SeoMetadataService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class AdminSeoController extends Controller
{
    public function __construct(
        private readonly SeoMetadataService $seoService,
        private readonly SeoEntityRegistry $registry,
        private readonly RedirectLifecycleService $redirectService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function indexMetadata(Request $request): JsonResponse
    {
        Gate::authorize('manageSeo', SeoMetadata::class);

        $validated = $request->validate([
            'entity_type' => ['nullable', 'string', 'max:40'],
            'locale' => ['nullable', 'string', 'in:en,ta'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $query = SeoMetadata::query()->with('publishedRevision:id,public_id,version_number');

        if (! empty($validated['entity_type'])) {
            $query->where('entity_type', strtolower(trim((string) $validated['entity_type'])));
        }
        if (! empty($validated['locale'])) {
            $query->where('locale', (string) $validated['locale']);
        }
        if (! empty($validated['q'])) {
            $term = trim((string) $validated['q']);
            $query->where(static function ($q) use ($term): void {
                $q->where('meta_title', 'like', "%{$term}%")
                    ->orWhere('meta_description', 'like', "%{$term}%")
                    ->orWhere('canonical_override_url', 'like', "%{$term}%");
            });
        }

        $items = $query->orderByDesc('updated_at')->paginate((int) ($validated['per_page'] ?? 20));

        return ApiResponse::paginated(
            $items,
            collect($items->items())->map(static fn (SeoMetadata $m): array => [
                'public_id' => $m->public_id,
                'entity_type' => $m->entity_type,
                'entity_id' => $m->entity_id,
                'scope_key' => $m->scope_key,
                'locale' => $m->locale,
                'meta_title' => $m->meta_title,
                'meta_description' => $m->meta_description,
                'canonical_override_url' => $m->canonical_override_url,
                'robots_index' => (bool) $m->robots_index,
                'robots_follow' => (bool) $m->robots_follow,
                'schema_type' => $m->schema_type,
                'published_version' => $m->publishedRevision?->version_number,
                'updated_at' => $m->updated_at?->toIso8601String(),
            ])->values()->all()
        );
    }

    public function indexRedirects(Request $request): JsonResponse
    {
        Gate::authorize('manageSeo', SeoMetadata::class);

        $validated = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'http_status' => ['nullable', 'integer', Rule::in([301, 302, 307, 308])],
            'q' => ['nullable', 'string', 'max:140'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $query = UrlRedirect::query()->with('domain:id,public_id,hostname');

        if (array_key_exists('is_active', $validated) && $validated['is_active'] !== null) {
            $query->where('is_active', (bool) $validated['is_active']);
        }
        if (isset($validated['http_status'])) {
            $query->where('http_status', (int) $validated['http_status']);
        }
        if (! empty($validated['q'])) {
            $term = trim((string) $validated['q']);
            $query->where(static function ($q) use ($term): void {
                $q->where('source_path', 'like', "%{$term}%")
                    ->orWhere('target_path', 'like', "%{$term}%");
            });
        }

        $redirects = $query->orderByDesc('id')->paginate((int) ($validated['per_page'] ?? 20));

        return ApiResponse::paginated(
            $redirects,
            collect($redirects->items())->map(static fn (UrlRedirect $r): array => [
                'id' => $r->id,
                'scope_key' => $r->scope_key,
                'source_path' => $r->source_path,
                'target_path' => $r->target_path,
                'http_status' => $r->http_status,
                'entity_type' => $r->entity_type,
                'is_active' => (bool) $r->is_active,
                'domain_hostname' => $r->domain?->hostname,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values()->all()
        );
    }

    public function storeRedirect(Request $request): JsonResponse
    {
        Gate::authorize('manageSeo', SeoMetadata::class);

        $validated = $request->validate([
            'source_path' => ['required', 'string', 'max:300'],
            'target_path' => ['required', 'string', 'max:300'],
            'http_status' => ['nullable', 'integer', Rule::in([301, 302, 307, 308])],
            'domain_id' => ['nullable', 'integer', 'exists:domains,id'],
        ]);

        try {
            $redirect = $this->redirectService->registerRedirect(
                sourcePath: (string) $validated['source_path'],
                targetPath: (string) $validated['target_path'],
                httpStatus: (int) ($validated['http_status'] ?? 301),
                domainId: isset($validated['domain_id']) ? (int) $validated['domain_id'] : null,
            );
        } catch (SeoException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        /** @var User $actor */
        $actor = $request->user();
        $this->auditLogger->record(
            actor: $actor,
            action: 'seo.redirect.created',
            auditable: $redirect,
            newValues: [
                'source_path' => $redirect->source_path,
                'target_path' => $redirect->target_path,
                'http_status' => $redirect->http_status,
            ],
            ipAddress: $request->ip(),
        );

        return ApiResponse::created([
            'id' => $redirect->id,
            'scope_key' => $redirect->scope_key,
            'source_path' => $redirect->source_path,
            'target_path' => $redirect->target_path,
            'http_status' => $redirect->http_status,
            'is_active' => (bool) $redirect->is_active,
        ], ['message' => 'Redirect created successfully.']);
    }

    public function updateRedirect(Request $request, UrlRedirect $urlRedirect): JsonResponse
    {
        Gate::authorize('manageSeo', SeoMetadata::class);

        $validated = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'http_status' => ['sometimes', 'integer', Rule::in([301, 302, 307, 308])],
        ]);

        $oldValues = ['is_active' => $urlRedirect->is_active, 'http_status' => $urlRedirect->http_status];
        $urlRedirect->fill($validated)->save();

        /** @var User $actor */
        $actor = $request->user();
        $this->auditLogger->record(
            actor: $actor,
            action: 'seo.redirect.updated',
            auditable: $urlRedirect,
            oldValues: $oldValues,
            newValues: ['is_active' => $urlRedirect->is_active, 'http_status' => $urlRedirect->http_status],
            ipAddress: $request->ip(),
        );

        return ApiResponse::success([
            'id' => $urlRedirect->id,
            'is_active' => (bool) $urlRedirect->is_active,
            'http_status' => $urlRedirect->http_status,
        ], ['message' => 'Redirect updated.']);
    }

    public function destroyRedirect(Request $request, UrlRedirect $urlRedirect): JsonResponse
    {
        Gate::authorize('manageSeo', SeoMetadata::class);

        /** @var User $actor */
        $actor = $request->user();
        $this->auditLogger->record(
            actor: $actor,
            action: 'seo.redirect.deleted',
            auditable: $urlRedirect,
            oldValues: [
                'source_path' => $urlRedirect->source_path,
                'target_path' => $urlRedirect->target_path,
            ],
            ipAddress: $request->ip(),
        );

        $urlRedirect->delete();

        return ApiResponse::success(['deleted' => true], ['message' => 'Redirect deleted.']);
    }

    public function show(Request $request, string $entityType, string $entityIdentifier): JsonResponse
    {
        Gate::authorize('manageSeo', SeoMetadata::class);

        $entity = $this->registry->findEntityOrFail($entityType, $entityIdentifier, includeTrashed: true);
        $records = SeoMetadata::query()
            ->where('entity_type', strtolower(trim($entityType)))
            ->where('entity_id', $entity->getKey())
            ->with('revisions')
            ->get()
            ->map(static fn (SeoMetadata $m): array => [
                'public_id' => $m->public_id,
                'scope_key' => $m->scope_key,
                'locale' => $m->locale,
                'meta_title' => $m->meta_title,
                'meta_description' => $m->meta_description,
                'canonical_override_url' => $m->canonical_override_url,
                'robots_index' => $m->robots_index,
                'robots_follow' => $m->robots_follow,
                'og_title' => $m->og_title,
                'og_description' => $m->og_description,
                'schema_type' => $m->schema_type,
                'revisions' => $m->revisions->map(static fn (SeoRevision $r): array => [
                    'public_id' => $r->public_id,
                    'version_number' => $r->version_number,
                    'change_summary' => $r->change_summary,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return ApiResponse::success($records);
    }

    public function upsert(Request $request, string $entityType, string $entityIdentifier): JsonResponse
    {
        Gate::authorize('manageSeo', SeoMetadata::class);

        $validated = $request->validate([
            'locale' => ['required', 'string', 'in:en,ta'],
            'domain_id' => ['nullable', 'integer', 'min:1'],
            'meta_title' => ['nullable', 'string', 'max:180'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'canonical_override_url' => ['nullable', 'url', 'max:500'],
            'robots_index' => ['nullable', 'boolean'],
            'robots_follow' => ['nullable', 'boolean'],
            'robots_directives' => ['nullable', 'array'],
            'og_title' => ['nullable', 'string', 'max:180'],
            'og_description' => ['nullable', 'string', 'max:320'],
            'og_image_url' => ['nullable', 'url', 'max:500'],
            'og_image_alt' => ['nullable', 'string', 'max:200'],
            'schema_type' => ['nullable', 'string', 'max:80'],
            'schema_overrides_json' => ['nullable', 'array'],
            'change_summary' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $seo = $this->seoService->upsertMetadata(
            entityType: $entityType,
            entityIdentifier: $entityIdentifier,
            locale: (string) $validated['locale'],
            data: $validated,
            actor: $actor,
            domainId: isset($validated['domain_id']) ? (int) $validated['domain_id'] : null,
            changeSummary: $validated['change_summary'] ?? null,
            ipAddress: $request->ip(),
        );

        return ApiResponse::success([
            'public_id' => $seo->public_id,
            'scope_key' => $seo->scope_key,
            'locale' => $seo->locale,
            'meta_title' => $seo->meta_title,
            'meta_description' => $seo->meta_description,
            'robots_index' => $seo->robots_index,
            'robots_follow' => $seo->robots_follow,
            'published_revision_version' => $seo->publishedRevision?->version_number,
        ]);
    }

    public function rollback(Request $request, SeoMetadata $seoMetadata, SeoRevision $revision): JsonResponse
    {
        Gate::authorize('manageSeo', $seoMetadata);

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->seoService->rollbackToRevision($seoMetadata, $revision, $actor, $request->ip());

        return ApiResponse::success([
            'public_id' => $updated->public_id,
            'meta_title' => $updated->meta_title,
            'published_revision_version' => $updated->publishedRevision?->version_number,
        ]);
    }
}
