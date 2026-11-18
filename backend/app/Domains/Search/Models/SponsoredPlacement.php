<?php

declare(strict_types=1);

namespace App\Domains\Search\Models;

use App\Domains\Directory\Models\Business;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;
use App\Domains\Taxonomy\Models\Category;
use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'business_id',
    'city_id',
    'category_id',
    'locality_id',
    'status',
    'bid_minor',
    'budget_minor',
    'spent_minor',
    'starts_at',
    'ends_at',
])]
#[Hidden([
    'id',
    'business_id',
])]
class SponsoredPlacement extends Model
{
    use HasPublicUlid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bid_minor' => 'integer',
            'budget_minor' => 'integer',
            'spent_minor' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
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
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Locality, $this>
     */
    public function locality(): BelongsTo
    {
        return $this->belongsTo(Locality::class);
    }
}
