<?php

declare(strict_types=1);

namespace App\Domains\Directory\Models;

use App\Support\Concerns\HasPublicUlid;
use Database\Factories\BusinessServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'business_id',
    'name',
    'description',
    'price_min_minor',
    'price_max_minor',
    'currency',
    'price_unit',
    'sort_order',
    'is_active',
])]
#[Hidden(['id', 'business_id'])]
class BusinessService extends Model
{
    /** @use HasFactory<BusinessServiceFactory> */
    use HasFactory, HasPublicUlid;

    protected static function newFactory(): BusinessServiceFactory
    {
        return BusinessServiceFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_min_minor' => 'integer',
            'price_max_minor' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
