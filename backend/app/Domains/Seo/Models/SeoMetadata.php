<?php

declare(strict_types=1);

namespace App\Domains\Seo\Models;

use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'entity_type',
    'entity_id',
    'domain_id',
    'scope_key',
    'locale',
    'meta_title',
    'meta_description',
    'canonical_override_url',
    'robots_index',
    'robots_follow',
    'robots_directives',
    'og_title',
    'og_description',
    'og_image_url',
    'og_image_alt',
    'schema_type',
    'schema_overrides_json',
    'published_revision_id',
])]
#[Hidden(['id', 'entity_id', 'published_revision_id'])]
class SeoMetadata extends Model
{
    use HasPublicUlid;

    protected $table = 'seo_metadata';

    public static function scopeKeyFor(?int $domainId): string
    {
        return $domainId === null ? 'global' : "domain:{$domainId}";
    }

    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'domain_id' => 'integer',
            'robots_index' => 'boolean',
            'robots_follow' => 'boolean',
            'robots_directives' => 'array',
            'schema_overrides_json' => 'array',
            'published_revision_id' => 'integer',
        ];
    }

    public function entity(): MorphTo
    {
        return $this->morphTo('entity', 'entity_type', 'entity_id');
    }

    /**
     * @return HasMany<SeoRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(SeoRevision::class, 'seo_metadata_id')->orderByDesc('version_number');
    }

    /**
     * @return BelongsTo<SeoRevision, $this>
     */
    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(SeoRevision::class, 'published_revision_id');
    }
}
