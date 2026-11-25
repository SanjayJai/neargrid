<?php

declare(strict_types=1);

namespace App\Domains\Taxonomy\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'category_id',
    'filter_key',
    'filter_type',
    'label_en',
    'label_ta',
    'sort_order',
    'is_enabled',
])]
#[Hidden(['id', 'category_id'])]
class CategoryFilterConfig extends Model
{
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'sort_order' => 'integer',
            'is_enabled' => 'boolean',
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
