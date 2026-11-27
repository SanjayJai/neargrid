<?php

declare(strict_types=1);

namespace App\Domains\Taxonomy\Models;

use App\Domains\Directory\Models\BusinessAttributeValue;
use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'category_id',
    'attribute_key',
    'section_key',
    'label_en',
    'label_ta',
    'data_type',
    'options_json',
    'unit',
    'is_filterable',
    'is_required',
    'show_on_profile',
    'sort_order',
    'is_active',
])]
#[Hidden(['id', 'category_id'])]
class CategoryAttributeDefinition extends Model
{
    use HasPublicUlid;

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'options_json' => 'array',
            'is_filterable' => 'boolean',
            'is_required' => 'boolean',
            'show_on_profile' => 'boolean',
            'sort_order' => 'integer',
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

    /**
     * @return HasMany<BusinessAttributeValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(BusinessAttributeValue::class, 'attribute_definition_id');
    }
}
