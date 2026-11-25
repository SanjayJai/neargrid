<?php

declare(strict_types=1);

namespace App\Domains\Taxonomy\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'category_id',
    'section_key',
    'display_label_en',
    'display_label_ta',
    'is_enabled',
    'is_required',
    'suppress_when_empty',
    'sort_order',
])]
#[Hidden(['id', 'category_id'])]
class CategoryProfileSection extends Model
{
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'is_enabled' => 'boolean',
            'is_required' => 'boolean',
            'suppress_when_empty' => 'boolean',
            'sort_order' => 'integer',
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
