<?php

declare(strict_types=1);

namespace App\Domains\Search\Models;

use App\Domains\Taxonomy\Models\Category;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'term',
    'normalized_term',
    'canonical_term',
    'locale',
    'category_id',
    'weight',
    'is_active',
])]
class SearchSynonym extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight' => 'float',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
