<?php

declare(strict_types=1);

namespace App\Domains\Location\Models;

use App\Domains\Directory\Models\Business;
use App\Domains\Leads\Models\Enquiry;
use App\Domains\Location\Concerns\HasLocalizedTranslations;
use App\Support\Concerns\HasPublicUlid;
use App\Support\Concerns\HasSpatialCenterPoint;
use Database\Factories\LocalityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'city_id',
    'name',
    'slug',
    'pincode',
    'latitude',
    'longitude',
    'is_popular',
    'sort_order',
    'is_active',
])]
#[Hidden(['id', 'city_id', 'center_point'])]
class Locality extends Model
{
    /** @use HasFactory<LocalityFactory> */
    use HasFactory, HasLocalizedTranslations, HasPublicUlid, HasSpatialCenterPoint;

    protected static function newFactory(): LocalityFactory
    {
        return LocalityFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_popular' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return HasMany<LocalityTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(LocalityTranslation::class);
    }

    /**
     * @return HasMany<Business, $this>
     */
    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    /**
     * @return HasMany<Enquiry, $this>
     */
    public function enquiries(): HasMany
    {
        return $this->hasMany(Enquiry::class);
    }
}
