'use client';

import { useState } from 'react';
import {
  ArrowUpDown,
  Check,
  CheckCircle2,
  Clock,
  Crosshair,
  Filter,
  Loader2,
  Star,
  X,
} from 'lucide-react';
import type { SearchQueryParams, SearchSortOptionDto } from '@/shared/api/directory-types';

interface QuickFilterBarProps {
  currentParams: SearchQueryParams;
  onFilterChange: (patch: Partial<SearchQueryParams>) => void;
  onResetFilters: () => void;
  onOpenMobileFilters?: () => void;
  hasActiveFilters?: boolean;
}

export function QuickFilterBar({
  currentParams,
  onFilterChange,
  onResetFilters,
  onOpenMobileFilters,
  hasActiveFilters = false,
}: QuickFilterBarProps) {
  const [locating, setLocating] = useState(false);
  const [locationError, setLocationError] = useState<string | null>(null);

  const activeSort = currentParams.sort ?? 'relevance';
  const isTopRated = (currentParams.min_rating ?? 0) >= 4;
  const isVerifiedOnly = Boolean(currentParams.verified_only);
  const isOpenNow = Boolean(currentParams.open_now);
  const hasCoordinates =
    typeof currentParams.lat === 'number' && typeof currentParams.lng === 'number';

  function handleNearMeClick() {
    setLocationError(null);
    if (typeof window === 'undefined' || !navigator.geolocation) {
      setLocationError('Geolocation is not supported by your browser.');
      return;
    }

    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setLocating(false);
        onFilterChange({
          lat: position.coords.latitude,
          lng: position.coords.longitude,
          radius_km: currentParams.radius_km ?? 10,
          sort: 'distance',
          page: 1,
        });
      },
      (err) => {
        setLocating(false);
        if (err.code === err.PERMISSION_DENIED) {
          setLocationError('Location permission denied. Please search by city or locality.');
        } else {
          setLocationError('Unable to retrieve your location. Please try again.');
        }
      },
      { timeout: 10000, enableHighAccuracy: false }
    );
  }

  function handleClearCoords() {
    onFilterChange({
      lat: undefined,
      lng: undefined,
      radius_km: undefined,
      sort: activeSort === 'distance' ? 'relevance' : activeSort,
      page: 1,
    });
  }

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 pb-3">
        {/* Left: Scrollable horizontal quick filter chips */}
        <div className="flex flex-wrap items-center gap-1.5 sm:gap-2">
          {/* Mobile Filter Drawer trigger */}
          {onOpenMobileFilters && (
            <button
              type="button"
              onClick={onOpenMobileFilters}
              className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-ink shadow-2xs hover:bg-gray-50 lg:hidden"
            >
              <Filter className="h-3.5 w-3.5 text-brand-600" aria-hidden="true" />
              <span>Filters</span>
            </button>
          )}

          {/* Near Me button */}
          {hasCoordinates ? (
            <button
              type="button"
              onClick={handleClearCoords}
              className="inline-flex items-center gap-1.5 rounded-lg border border-signal-600 bg-signal-50 px-2.5 py-1.5 text-xs font-semibold text-signal-700 hover:bg-signal-100"
              title="Click to remove GPS location filter"
            >
              <Crosshair className="h-3.5 w-3.5 text-signal-600 animate-pulse" aria-hidden="true" />
              <span>Near Me (Active)</span>
              <X className="h-3 w-3 text-signal-700" aria-hidden="true" />
            </button>
          ) : (
            <button
              type="button"
              onClick={handleNearMeClick}
              disabled={locating}
              className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-ink shadow-2xs hover:border-signal-500 hover:text-signal-700 disabled:opacity-60"
            >
              {locating ? (
                <Loader2 className="h-3.5 w-3.5 animate-spin text-signal-600" aria-hidden="true" />
              ) : (
                <Crosshair className="h-3.5 w-3.5 text-signal-600" aria-hidden="true" />
              )}
              <span>{locating ? 'Locating...' : 'Near Me'}</span>
            </button>
          )}

          {/* Top Rated (4.0+) toggle */}
          <button
            type="button"
            onClick={() =>
              onFilterChange({
                min_rating: isTopRated ? undefined : 4,
                page: 1,
              })
            }
            aria-pressed={isTopRated}
            className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition-colors shadow-2xs ${
              isTopRated
                ? 'border border-amber-500 bg-amber-50 text-amber-900 font-bold'
                : 'border border-gray-300 bg-white text-ink hover:bg-gray-50'
            }`}
          >
            <Star
              className={`h-3.5 w-3.5 ${
                isTopRated ? 'fill-amber-500 text-amber-500' : 'text-amber-500'
              }`}
              aria-hidden="true"
            />
            <span>Top Rated (4.0+)</span>
            {isTopRated && <Check className="h-3 w-3" aria-hidden="true" />}
          </button>

          {/* Verified Only toggle */}
          <button
            type="button"
            onClick={() =>
              onFilterChange({
                verified_only: !isVerifiedOnly,
                page: 1,
              })
            }
            aria-pressed={isVerifiedOnly}
            className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition-colors shadow-2xs ${
              isVerifiedOnly
                ? 'border border-trust-600 bg-trust-50 text-trust-900 font-bold'
                : 'border border-gray-300 bg-white text-ink hover:bg-gray-50'
            }`}
          >
            <CheckCircle2
              className={`h-3.5 w-3.5 ${
                isVerifiedOnly ? 'text-trust-700' : 'text-trust-600'
              }`}
              aria-hidden="true"
            />
            <span>Verified</span>
            {isVerifiedOnly && <Check className="h-3 w-3" aria-hidden="true" />}
          </button>

          {/* Open Now toggle */}
          <button
            type="button"
            onClick={() =>
              onFilterChange({
                open_now: !isOpenNow,
                page: 1,
              })
            }
            aria-pressed={isOpenNow}
            className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition-colors shadow-2xs ${
              isOpenNow
                ? 'border border-emerald-600 bg-emerald-50 text-emerald-900 font-bold'
                : 'border border-gray-300 bg-white text-ink hover:bg-gray-50'
            }`}
          >
            <Clock
              className={`h-3.5 w-3.5 ${
                isOpenNow ? 'text-emerald-700' : 'text-emerald-600'
              }`}
              aria-hidden="true"
            />
            <span>Open Now</span>
            {isOpenNow && <Check className="h-3 w-3" aria-hidden="true" />}
          </button>

          {/* Clear all filters shortcut */}
          {hasActiveFilters && (
            <button
              type="button"
              onClick={onResetFilters}
              className="inline-flex items-center gap-1 rounded-lg px-2 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-50 hover:underline"
            >
              <X className="h-3.5 w-3.5" aria-hidden="true" />
              <span>Clear filters</span>
            </button>
          )}
        </div>

        {/* Right: Sort Dropdown */}
        <div className="flex items-center gap-2 self-end sm:self-auto ml-auto">
          <label htmlFor="quick-sort-select" className="text-xs font-medium text-ink-muted flex items-center gap-1">
            <ArrowUpDown className="h-3 w-3 text-gray-500" aria-hidden="true" />
            <span className="hidden sm:inline">Sort:</span>
          </label>
          <select
            id="quick-sort-select"
            value={activeSort}
            onChange={(e) =>
              onFilterChange({
                sort: e.target.value as SearchSortOptionDto,
                page: 1,
              })
            }
            className="rounded-lg border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-ink shadow-2xs focus:border-brand-500 focus:outline-none"
          >
            <option value="relevance">Relevance</option>
            <option value="rating">Highest Rated</option>
            <option value="distance">Nearest First</option>
            <option value="popularity">Most Popular</option>
            <option value="newest">Recently Added</option>
          </select>
        </div>
      </div>

      {/* Geolocation permission error message */}
      {locationError && (
        <div className="flex items-center justify-between rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-800 border border-rose-200">
          <span>{locationError}</span>
          <button
            type="button"
            onClick={() => setLocationError(null)}
            className="text-rose-600 hover:text-rose-900 font-semibold"
          >
            Dismiss
          </button>
        </div>
      )}
    </div>
  );
}
