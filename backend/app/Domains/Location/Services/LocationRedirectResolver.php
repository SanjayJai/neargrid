<?php

declare(strict_types=1);

namespace App\Domains\Location\Services;

use App\Domains\Governance\Models\UrlRedirect;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\Locality;

final class LocationRedirectResolver
{
    private const SAFE_INTERNAL_PATH_REGEX = '/^\/[a-z0-9\-\/]+$/i';

    /**
     * Resolve a 301 redirect target path when an old city or locality slug is queried.
     * Enforces single-hop chain traversal, loop prevention, active destination checks,
     * and strict internal-only path validation.
     */
    public function resolveDirectoryRedirect(
        ?string $citySlug,
        ?string $localitySlug = null,
        ?string $categorySlug = null,
    ): ?string {
        if ($citySlug === null || $citySlug === '') {
            return null;
        }

        $activeCity = City::query()
            ->where('slug', $citySlug)
            ->where('is_active', true)
            ->whereHas('state', static fn ($sq) => $sq->where('is_active', true))
            ->first();

        // Case 1: City slug is stale; look up /{citySlug} (or /{citySlug}/{localitySlug} if locality given)
        if ($activeCity === null) {
            if ($localitySlug !== null && $localitySlug !== '') {
                $locTarget = $this->followSafeInternalChain("/{$citySlug}/{$localitySlug}");
                if ($locTarget !== null) {
                    $segments = array_values(array_filter(explode('/', trim($locTarget, '/'))));
                    if (count($segments) === 2 && $this->isActiveCityAndLocality($segments[0], $segments[1])) {
                        return $categorySlug !== null && $categorySlug !== ''
                            ? "/{$segments[0]}/{$segments[1]}/{$categorySlug}"
                            : "/{$segments[0]}/{$segments[1]}";
                    }
                }
            }

            $cityTarget = $this->followSafeInternalChain("/{$citySlug}");
            if ($cityTarget !== null) {
                $newCitySlug = trim($cityTarget, '/');
                if ($newCitySlug !== '' && ! str_contains($newCitySlug, '/') && $this->isActiveCity($newCitySlug)) {
                    if ($localitySlug !== null && $localitySlug !== '') {
                        return $categorySlug !== null && $categorySlug !== ''
                            ? "/{$newCitySlug}/{$localitySlug}/{$categorySlug}"
                            : "/{$newCitySlug}/{$localitySlug}";
                    }

                    return $categorySlug !== null && $categorySlug !== ''
                        ? "/{$newCitySlug}/{$categorySlug}"
                        : "/{$newCitySlug}";
                }
            }

            return null;
        }

        // Case 2: City is active, check if locality slug is stale
        if ($localitySlug !== null && $localitySlug !== '') {
            $activeLocality = Locality::query()
                ->where('city_id', $activeCity->id)
                ->where('slug', $localitySlug)
                ->where('is_active', true)
                ->exists();

            if (! $activeLocality) {
                $locTarget = $this->followSafeInternalChain("/{$activeCity->slug}/{$localitySlug}");
                if ($locTarget !== null) {
                    $segments = array_values(array_filter(explode('/', trim($locTarget, '/'))));
                    if (count($segments) === 2 && $this->isActiveCityAndLocality($segments[0], $segments[1])) {
                        return $categorySlug !== null && $categorySlug !== ''
                            ? "/{$segments[0]}/{$segments[1]}/{$categorySlug}"
                            : "/{$segments[0]}/{$segments[1]}";
                    }
                }
            }
        }

        return null;
    }

    public function isSafeInternalPath(string $path): bool
    {
        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1 || str_contains($path, ':')) {
            return false;
        }

        return preg_match(self::SAFE_INTERNAL_PATH_REGEX, $path) === 1;
    }

    private function followSafeInternalChain(string $sourcePath): ?string
    {
        if (! $this->isSafeInternalPath($sourcePath)) {
            return null;
        }

        $visited = [$sourcePath => true];
        $current = $sourcePath;

        // Guard against any legacy chains or loops (max 5 hops).
        for ($hop = 0; $hop < 5; $hop++) {
            $redirect = UrlRedirect::query()
                ->where('source_path', $current)
                ->first();

            if ($redirect === null) {
                return $current === $sourcePath ? null : $current;
            }

            $next = (string) $redirect->target_path;
            if (! $this->isSafeInternalPath($next)) {
                return null;
            }

            if (isset($visited[$next])) {
                // Redirect loop detected: fail closed.
                return null;
            }

            $visited[$next] = true;
            $current = $next;
        }

        return null;
    }

    private function isActiveCity(string $citySlug): bool
    {
        return City::query()
            ->where('slug', $citySlug)
            ->where('is_active', true)
            ->whereHas('state', static fn ($sq) => $sq->where('is_active', true))
            ->exists();
    }

    private function isActiveCityAndLocality(string $citySlug, string $localitySlug): bool
    {
        $city = City::query()
            ->where('slug', $citySlug)
            ->where('is_active', true)
            ->whereHas('state', static fn ($sq) => $sq->where('is_active', true))
            ->first();

        if ($city === null) {
            return false;
        }

        return Locality::query()
            ->where('city_id', $city->id)
            ->where('slug', $localitySlug)
            ->where('is_active', true)
            ->exists();
    }
}
