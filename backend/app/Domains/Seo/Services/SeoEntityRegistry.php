<?php

declare(strict_types=1);

namespace App\Domains\Seo\Services;

use App\Domains\Directory\Models\Business;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Location\Models\State;
use App\Domains\Seo\Exceptions\SeoException;
use App\Domains\Seo\Models\SeoMetadata;
use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\CategoryCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Response;

final class SeoEntityRegistry
{
    /**
     * @var array<string, class-string<Model>>
     */
    private const ENTITY_MAP = [
        'state' => State::class,
        'city' => City::class,
        'locality' => Locality::class,
        'category' => Category::class,
        'category_collection' => CategoryCollection::class,
        'business' => Business::class,
    ];

    public function resolveModelClass(string $entityType): string
    {
        $normalized = strtolower(trim($entityType));
        if (! isset(self::ENTITY_MAP[$normalized])) {
            throw new SeoException(
                'INVALID_SEO_ENTITY_TYPE',
                "Unsupported SEO entity type '{$entityType}'.",
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return self::ENTITY_MAP[$normalized];
    }

    public function findEntityOrFail(string $entityType, int|string $identifier, bool $includeTrashed = true): Model
    {
        $class = $this->resolveModelClass($entityType);
        $query = $class::query();

        if ($includeTrashed && in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            /** @phpstan-ignore-next-line */
            $query->withTrashed();
        }

        $model = is_numeric($identifier)
            ? $query->where('id', (int) $identifier)->first()
            : $query->where('public_id', (string) $identifier)->first();

        if ($model === null) {
            throw new SeoException(
                'SEO_ENTITY_NOT_FOUND',
                "Target SEO entity '{$entityType}' ({$identifier}) was not found.",
                Response::HTTP_NOT_FOUND,
            );
        }

        return $model;
    }

    /**
     * Prune orphaned SeoMetadata rows whose underlying entity was permanently deleted.
     * Preserves metadata belonging to soft-deleted entities.
     */
    public function pruneOrphanedMetadata(): int
    {
        $deletedCount = 0;

        SeoMetadata::query()
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$deletedCount): void {
                foreach ($rows as $seo) {
                    $normalized = strtolower(trim((string) $seo->entity_type));
                    if (! isset(self::ENTITY_MAP[$normalized])) {
                        continue;
                    }

                    $class = self::ENTITY_MAP[$normalized];
                    $query = $class::query();
                    if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
                        /** @phpstan-ignore-next-line */
                        $query->withTrashed();
                    }

                    $exists = $query->where('id', $seo->entity_id)->exists();
                    if (! $exists) {
                        $seo->delete();
                        $deletedCount++;
                    }
                }
            });

        return $deletedCount;
    }
}
