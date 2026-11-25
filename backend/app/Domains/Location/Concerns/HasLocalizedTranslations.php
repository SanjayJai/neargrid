<?php

declare(strict_types=1);

namespace App\Domains\Location\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait HasLocalizedTranslations
{
    public function translationFor(string $locale, string $fallbackLocale = 'en'): ?Model
    {
        $translations = $this->relationLoaded('translations')
            ? $this->translations
            : $this->translations()->whereIn('locale', [$locale, $fallbackLocale])->get();

        return $translations->firstWhere('locale', $locale)
            ?? $translations->firstWhere('locale', $fallbackLocale);
    }

    public function translatedName(string $locale = 'en'): string
    {
        $translation = $this->translationFor($locale);
        if ($translation !== null && is_string($translation->getAttribute('name'))) {
            return $translation->getAttribute('name');
        }

        return (string) $this->getAttribute('name');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithLocale(Builder $query, string $locale = 'en'): Builder
    {
        $locales = array_values(array_unique([$locale, 'en']));

        return $query->with(['translations' => fn ($q) => $q->whereIn('locale', $locales)]);
    }
}
