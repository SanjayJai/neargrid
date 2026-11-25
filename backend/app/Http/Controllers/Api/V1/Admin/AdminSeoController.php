<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Seo\Models\SeoMetadata;
use App\Domains\Seo\Models\SeoRevision;
use App\Domains\Seo\Services\SeoEntityRegistry;
use App\Domains\Seo\Services\SeoMetadataService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class AdminSeoController extends Controller
{
    public function __construct(
        private readonly SeoMetadataService $seoService,
        private readonly SeoEntityRegistry $registry,
    ) {}

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
