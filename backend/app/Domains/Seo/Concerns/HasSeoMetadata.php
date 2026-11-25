<?php

declare(strict_types=1);

namespace App\Domains\Seo\Concerns;

use App\Domains\Seo\Models\SeoMetadata;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Links an entity to polymorphic SeoMetadata and cleans up SEO records only on permanent deletion.
 */
trait HasSeoMetadata
{
    public static function bootHasSeoMetadata(): void
    {
        $cleanup = static function (self $model): void {
            SeoMetadata::query()
                ->whereIn('entity_type', [static::class, $model->seoEntityType()])
                ->where('entity_id', $model->getKey())
                ->delete();
        };

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::forceDeleted($cleanup);

            return;
        }

        static::deleted($cleanup);
    }

    public function seoEntityType(): string
    {
        return Str::snake(class_basename(static::class));
    }

    /**
     * @return MorphMany<SeoMetadata, $this>
     */
    public function seoMetadata(): MorphMany
    {
        return $this->morphMany(SeoMetadata::class, 'entity');
    }
}
