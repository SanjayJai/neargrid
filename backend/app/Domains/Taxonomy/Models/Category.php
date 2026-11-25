<?php

declare(strict_types=1);

namespace App\Domains\Taxonomy\Models;

use App\Domains\Seo\Concerns\HasSeoMetadata;
use App\Support\Concerns\HasPublicUlid;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'parent_id',
    'node_type',
    'name',
    'slug',
    'schema_org_type',
    'icon_name',
    'image_url',
    'depth',
    'lifecycle_status',
    'is_system',
    'is_assignable',
    'has_landing_page',
    'is_featured',
    'show_on_homepage',
    'is_popular',
    'sort_order',
    'is_active',
    'published_version_id',
    'archived_at',
])]
#[Hidden(['id', 'parent_id'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, HasPublicUlid, HasSeoMetadata, SoftDeletes;

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'is_system' => 'boolean',
            'is_assignable' => 'boolean',
            'has_landing_page' => 'boolean',
            'is_featured' => 'boolean',
            'show_on_homepage' => 'boolean',
            'is_popular' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<CategoryTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(CategoryTranslation::class);
    }

    /**
     * @return HasMany<CategoryRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(CategoryRevision::class)->orderByDesc('version_number');
    }

    /**
     * @return BelongsTo<CategoryRevision, $this>
     */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(CategoryRevision::class, 'published_version_id');
    }

    /**
     * @return BelongsToMany<CategoryCollection, $this>
     */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(CategoryCollection::class, 'category_collection_items', 'category_id', 'collection_id')
            ->withPivot('sort_order');
    }

    /**
     * @return HasMany<CategoryAttributeDefinition, $this>
     */
    public function attributeDefinitions(): HasMany
    {
        return $this->hasMany(CategoryAttributeDefinition::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<CategoryFilterConfig, $this>
     */
    public function filterConfigs(): HasMany
    {
        return $this->hasMany(CategoryFilterConfig::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<CategoryProfileSection, $this>
     */
    public function profileSections(): HasMany
    {
        return $this->hasMany(CategoryProfileSection::class)->orderBy('sort_order');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('lifecycle_status', 'published')->where('is_active', true);
    }

    public function translationFor(string $locale, string $fallbackLocale = 'en'): ?CategoryTranslation
    {
        $translations = $this->relationLoaded('translations')
            ? $this->translations
            : $this->translations()->whereIn('locale', [$locale, $fallbackLocale])->get();

        return $translations->firstWhere('locale', $locale)
            ?? $translations->firstWhere('locale', $fallbackLocale);
    }

    public function translatedName(string $locale = 'en'): string
    {
        return $this->translationFor($locale)?->name ?? $this->name;
    }

    public function translatedSynonyms(string $locale = 'en'): ?string
    {
        return $this->translationFor($locale)?->synonyms;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRoot(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithLocale(Builder $query, string $locale = 'en'): Builder
    {
        $locales = array_values(array_unique([$locale, 'en']));

        return $query->with(['translations' => fn ($q) => $q->whereIn('locale', $locales)]);
    }
}
