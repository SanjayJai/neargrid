<?php

declare(strict_types=1);

namespace App\Domains\Taxonomy\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'category_id',
    'locale',
    'name',
    'description',
    'synonyms',
    'seo_title_template',
    'seo_description_template',
])]
class CategoryTranslation extends Model
{
    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
