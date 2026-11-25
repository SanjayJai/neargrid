<?php

declare(strict_types=1);

namespace App\Domains\Seo\Services;

use App\Domains\Governance\Services\AuditLogger;
use App\Domains\Seo\Exceptions\SeoException;
use App\Domains\Seo\Models\SeoMetadata;
use App\Domains\Seo\Models\SeoRevision;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class SeoMetadataService
{
    public function __construct(
        private readonly SeoEntityRegistry $registry,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsertMetadata(
        string $entityType,
        int|string $entityIdentifier,
        string $locale,
        array $data,
        User $actor,
        ?int $domainId = null,
        ?string $changeSummary = null,
        ?string $ipAddress = null,
    ): SeoMetadata {
        $entity = $this->registry->findEntityOrFail($entityType, $entityIdentifier, includeTrashed: true);
        $normalizedType = strtolower(trim($entityType));
        $scopeKey = SeoMetadata::scopeKeyFor($domainId);

        return DB::transaction(function () use ($normalizedType, $entity, $domainId, $scopeKey, $locale, $data, $actor, $changeSummary, $ipAddress): SeoMetadata {
            $existing = SeoMetadata::query()
                ->where('entity_type', $normalizedType)
                ->where('entity_id', $entity->getKey())
                ->where('scope_key', $scopeKey)
                ->where('locale', $locale)
                ->first();

            $oldSnapshot = $existing !== null ? $this->snapshot($existing) : null;

            $seo = SeoMetadata::query()->updateOrCreate(
                [
                    'entity_type' => $normalizedType,
                    'entity_id' => $entity->getKey(),
                    'scope_key' => $scopeKey,
                    'locale' => $locale,
                ],
                [
                    'domain_id' => $domainId,
                    'meta_title' => $data['meta_title'] ?? $existing?->meta_title,
                    'meta_description' => $data['meta_description'] ?? $existing?->meta_description,
                    'canonical_override_url' => array_key_exists('canonical_override_url', $data) ? $data['canonical_override_url'] : $existing?->canonical_override_url,
                    'robots_index' => $data['robots_index'] ?? $existing?->robots_index ?? true,
                    'robots_follow' => $data['robots_follow'] ?? $existing?->robots_follow ?? true,
                    'robots_directives' => array_key_exists('robots_directives', $data) ? $data['robots_directives'] : $existing?->robots_directives,
                    'og_title' => array_key_exists('og_title', $data) ? $data['og_title'] : $existing?->og_title,
                    'og_description' => array_key_exists('og_description', $data) ? $data['og_description'] : $existing?->og_description,
                    'og_image_url' => array_key_exists('og_image_url', $data) ? $data['og_image_url'] : $existing?->og_image_url,
                    'og_image_alt' => array_key_exists('og_image_alt', $data) ? $data['og_image_alt'] : $existing?->og_image_alt,
                    'schema_type' => array_key_exists('schema_type', $data) ? $data['schema_type'] : $existing?->schema_type,
                    'schema_overrides_json' => array_key_exists('schema_overrides_json', $data) ? $data['schema_overrides_json'] : $existing?->schema_overrides_json,
                ]
            );

            $nextVersion = ((int) SeoRevision::query()->where('seo_metadata_id', $seo->id)->max('version_number')) + 1;
            $revision = SeoRevision::query()->create([
                'seo_metadata_id' => $seo->id,
                'version_number' => $nextVersion,
                'snapshot_json' => $this->snapshot($seo),
                'change_summary' => $changeSummary,
                'created_by_user_id' => $actor->id,
                'created_at' => now(),
            ]);

            $seo->published_revision_id = $revision->id;
            $seo->save();

            $this->auditLogger->record(
                actor: $actor,
                action: $oldSnapshot === null ? 'seo.metadata.created' : 'seo.metadata.updated',
                auditable: $seo,
                oldValues: $oldSnapshot,
                newValues: $this->snapshot($seo),
                ipAddress: $ipAddress,
                actorRole: 'super_admin',
            );

            return $seo->fresh(['revisions', 'publishedRevision']) ?? $seo;
        });
    }

    public function rollbackToRevision(
        SeoMetadata $seo,
        SeoRevision $revision,
        User $actor,
        ?string $ipAddress = null,
    ): SeoMetadata {
        if ((int) $revision->seo_metadata_id !== (int) $seo->id) {
            throw new SeoException(
                'SEO_REVISION_MISMATCH',
                'SEO revision does not belong to the specified metadata record.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->upsertMetadata(
            entityType: $seo->entity_type,
            entityIdentifier: $seo->entity_id,
            locale: $seo->locale,
            data: $revision->snapshot_json,
            actor: $actor,
            domainId: $seo->domain_id,
            changeSummary: "Rollback to revision v{$revision->version_number}",
            ipAddress: $ipAddress,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(SeoMetadata $seo): array
    {
        return [
            'meta_title' => $seo->meta_title,
            'meta_description' => $seo->meta_description,
            'canonical_override_url' => $seo->canonical_override_url,
            'robots_index' => $seo->robots_index,
            'robots_follow' => $seo->robots_follow,
            'robots_directives' => $seo->robots_directives,
            'og_title' => $seo->og_title,
            'og_description' => $seo->og_description,
            'og_image_url' => $seo->og_image_url,
            'og_image_alt' => $seo->og_image_alt,
            'schema_type' => $seo->schema_type,
            'schema_overrides_json' => $seo->schema_overrides_json,
        ];
    }
}
