<?php

declare(strict_types=1);

namespace App\Domains\Directory\Models;

use App\Domains\Taxonomy\Models\CategoryAttributeDefinition;
use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'business_id',
    'attribute_definition_id',
    'value_string',
    'value_number',
    'value_boolean',
    'value_json',
])]
#[Hidden(['id', 'business_id', 'attribute_definition_id'])]
class BusinessAttributeValue extends Model
{
    use HasPublicUlid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_id' => 'integer',
            'attribute_definition_id' => 'integer',
            'value_number' => 'float',
            'value_boolean' => 'boolean',
            'value_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @return BelongsTo<CategoryAttributeDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(CategoryAttributeDefinition::class, 'attribute_definition_id');
    }

    /**
     * Return the single typed value corresponding to the attribute definition's data_type.
     *
     * @return string|float|bool|array<int, string>|null
     */
    public function typedValue(): string|float|bool|array|null
    {
        $dataType = $this->definition?->data_type;

        return match ($dataType) {
            'string', 'enum' => $this->value_string,
            'number' => $this->value_number !== null ? (float) $this->value_number : null,
            'boolean' => $this->value_boolean,
            'multi_enum' => is_array($this->value_json) ? array_values($this->value_json) : null,
            default => null,
        };
    }
}
