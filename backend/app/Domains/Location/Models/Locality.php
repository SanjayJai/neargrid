<?php

declare(strict_types=1);

namespace App\Domains\Location\Models;

use App\Support\Concerns\HasSpatialCenterPoint;
use Database\Factories\LocalityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
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
    'is_active',
])]
#[Hidden(['center_point'])]
class Locality extends Model
{
    /** @use HasFactory<LocalityFactory> */
    use HasFactory, HasSpatialCenterPoint;

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

    public function translationFor(string $locale, string $fallbackLocale = 'en'): ?LocalityTranslation
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
