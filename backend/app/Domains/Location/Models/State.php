<?php

declare(strict_types=1);

namespace App\Domains\Location\Models;

use App\Domains\Directory\Models\Business;
use App\Domains\Location\Concerns\HasLocalizedTranslations;
use App\Support\Concerns\HasPublicUlid;
use Database\Factories\StateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable([
    'country_code',
    'name',
    'slug',
    'sort_order',
    'is_active',
])]
#[Hidden(['id'])]
class State extends Model
{
    /** @use HasFactory<StateFactory> */
    use HasFactory, HasLocalizedTranslations, HasPublicUlid;

    protected static function newFactory(): StateFactory
    {
        return StateFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<City, $this>
     */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    /**
     * @return HasMany<StateTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(StateTranslation::class);
    }

    /**
     * @return HasManyThrough<Business, City, $this>
     */
    public function businesses(): HasManyThrough
    {
        return $this->hasManyThrough(Business::class, City::class);
    }
}
