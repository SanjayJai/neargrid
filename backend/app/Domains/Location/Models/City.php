<?php

declare(strict_types=1);

namespace App\Domains\Location\Models;

use App\Domains\Directory\Models\Business;
use App\Domains\Leads\Models\Enquiry;
use App\Domains\Location\Concerns\HasLocalizedTranslations;
use App\Support\Concerns\HasPublicUlid;
use App\Support\Concerns\HasSpatialCenterPoint;
use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'state_id',
    'name',
    'slug',
    'latitude',
    'longitude',
    'timezone',
    'is_popular',
    'sort_order',
    'seo_title',
    'seo_description',
    'is_active',
])]
#[Hidden(['id', 'state_id', 'center_point'])]
class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory, HasLocalizedTranslations, HasPublicUlid, HasSpatialCenterPoint;

    protected static function newFactory(): CityFactory
    {
        return CityFactory::new();
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
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * @return HasMany<Locality, $this>
     */
    public function localities(): HasMany
    {
        return $this->hasMany(Locality::class);
    }

    /**
     * @return HasMany<CityTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(CityTranslation::class);
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
