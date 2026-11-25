<?php

declare(strict_types=1);

namespace App\Domains\Taxonomy\Models;

use App\Domains\Seo\Concerns\HasSeoMetadata;
use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'slug',
    'name_en',
    'name_ta',
    'description_en',
    'description_ta',
    'icon_name',
    'lifecycle_status',
    'show_on_homepage',
    'is_popular',
    'sort_order',
    'is_active',
    'archived_at',
])]
#[Hidden(['id'])]
class CategoryCollection extends Model
{
    use HasPublicUlid, HasSeoMetadata, SoftDeletes;

    protected function casts(): array
    {
        return [
            'show_on_homepage' => 'boolean',
            'is_popular' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_collection_items', 'collection_id', 'category_id')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('lifecycle_status', 'published')->where('is_active', true);
    }

    public function translatedName(string $locale = 'en'): string
    {
        if ($locale === 'ta' && $this->name_ta !== null && $this->name_ta !== '') {
            return $this->name_ta;
        }

        return $this->name_en;
    }
}
