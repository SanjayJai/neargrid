<?php

declare(strict_types=1);

namespace App\Domains\Location\Models;

use App\Support\Concerns\HasSpatialCenterPoint;
use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
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
#[Hidden(['center_point'])]
class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory, HasSpatialCenterPoint;

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

    public function translationFor(string $locale, string $fallbackLocale = 'en'): ?CityTranslation
    {
        $translations = $this->relationLoaded('translations')
            ? $this->translations
            : $this->translations()->whereIn('locale', [$locale, $fallbackLocale])->get();

        return $translations->firstWhere('locale', $locale)
            ?? $translations->firstWhere('locale', $fallbackLocale);
    }

    public function translatedName(string $locale = 'en'): string
    {
        return $this->translationFor($locale)?->name ?? $this->name;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithLocale(Builder $query, string $locale = 'en'): Builder
    {
        $locales = array_values(array_unique([$locale, 'en']));

        return $query->with(['translations' => fn ($q) => $q->whereIn('locale', $locales)]);
    }
}
