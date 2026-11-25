'use client';

import { useEffect, useRef, useState } from 'react';
import {
  ArrowUpDown,
  Check,
  CheckCircle2,
  Clock,
  Crosshair,
  Loader2,
  Search,
  SlidersHorizontal,
  Star,
  X,
} from 'lucide-react';
import type {
  SearchFiltersDataDto,
  SearchQueryParams,
  SearchSortOptionDto,
} from '@/shared/api/directory-types';
import { SUPPORTED_SORT_OPTIONS } from './QuickFilterBar';

interface HorizontalFilterBarProps {
  currentParams: SearchQueryParams;
  filtersData: SearchFiltersDataDto | null;
  onFilterChange: (patch: Partial<SearchQueryParams>) => void;
  onResetFilters: () => void;
  hasActiveFilters: boolean;
  totalResults: number;
  sponsoredCount?: number;
  fixedCategorySlug?: string;
  fixedLocalitySlug?: string;
  showSearchWithin?: boolean;
  locale?: 'en' | 'ta';
}

export function HorizontalFilterBar({
  currentParams,
  filtersData,
  onFilterChange,
  onResetFilters,
  hasActiveFilters,
  totalResults,
  sponsoredCount = 0,
  fixedCategorySlug,
  fixedLocalitySlug,
  showSearchWithin = true,
  locale = 'en',
}: HorizontalFilterBarProps) {
  const [moreOpen, setMoreOpen] = useState(false);
  const [locating, setLocating] = useState(false);
  const [locationError, setLocationError] = useState<string | null>(null);
  const [keywordInput, setKeywordInput] = useState(currentParams.q ?? '');
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    setKeywordInput(currentParams.q ?? '');
  }, [currentParams.q]);

  useEffect(() => {
    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
    };
  }, []);

  function handleKeywordChange(value: string) {
    setKeywordInput(value);
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => {
      onFilterChange({ q: value.trim() || undefined, page: 1 });
    }, 350);
  }

  function handleNearMe() {
    setLocationError(null);
    if (typeof window === 'undefined' || !navigator.geolocation) {
      setLocationError('Geolocation is not supported by your browser.');
      return;
    }

    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setLocating(false);
        onFilterChange({
          lat: pos.coords.latitude,
          lng: pos.coords.longitude,
          radius_km: currentParams.radius_km ?? 10,
          sort: 'distance',
          page: 1,
        });
      },
      () => {
        setLocating(false);
        setLocationError('Location permission denied or unavailable.');
      },
      { timeout: 10000, enableHighAccuracy: false }
    );
  }

  function toggleArrayValue(list: string[] | undefined, value: string): string[] | undefined {
    const current = list ?? [];
    const next = current.includes(value)
      ? current.filter((item) => item !== value)
      : [...current, value];
    return next.length > 0 ? next : undefined;
  }

  const activeSort = currentParams.sort ?? 'relevance';
  const sortOptions =
    filtersData?.sort_options && filtersData.sort_options.length > 0
      ? filtersData.sort_options
      : SUPPORTED_SORT_OPTIONS;
  const hasCoordinates =
    typeof currentParams.lat === 'number' && typeof currentParams.lng === 'number';
  const isTopRated = (currentParams.min_rating ?? 0) >= 4;
  const isVerified = Boolean(currentParams.verified_only);
  const isOpenNow = Boolean(currentParams.open_now);
  const selectedAmenities = currentParams.amenities ?? [];
  const selectedPayments = currentParams.payment_methods ?? [];
  const moreCount = selectedAmenities.length + selectedPayments.length;

  return (
    <div className="sticky top-16 z-20 -mx-4 border-y border-gray-200 bg-white/95 px-4 backdrop-blur sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
      <div className="mx-auto max-w-7xl py-3">
        {/* Main horizontal scrollable filter strip */}
        <div
          role="region"
          aria-label="Search and business filters"
          className="flex items-center gap-2 overflow-x-auto pb-1 [scrollbar-width:thin]"
        >
          {showSearchWithin && (
            <div className="relative shrink-0">
              <Search
                className="pointer-events-none absolute left-2.5 top-2.5 h-3.5 w-3.5 text-ink-subtle"
                aria-hidden="true"
              />
              <input
                type="search"
                value={keywordInput}
                onChange={(e) => handleKeywordChange(e.target.value)}
                placeholder={locale === 'ta' ? 'முடிவுகளில் தேடு...' : 'Filter by keyword...'}
                aria-label="Search within results"
                className="h-8 w-44 rounded-lg border border-gray-200 bg-surface-subtle pl-8 pr-3 text-xs text-ink placeholder:text-ink-subtle focus:border-brand-500 focus:bg-white focus:outline-none sm:w-52"
              />
            </div>
          )}

          {/* Category dropdown */}
          {!fixedCategorySlug && filtersData && filtersData.categories.length > 0 && (
            <select
              aria-label="Filter by category"
              value={currentParams.category ?? ''}
              onChange={(e) =>
                onFilterChange({ category: e.target.value || undefined, page: 1 })
              }
              className="h-8 shrink-0 rounded-lg border border-gray-200 bg-white px-2.5 text-xs font-medium text-ink focus:border-brand-500 focus:outline-none"
            >
              <option value="">All Categories</option>
              {filtersData.categories.map((cat) => (
                <option key={cat.slug} value={cat.slug}>
                  {cat.name}
                </option>
              ))}
            </select>
          )}

          {/* Locality dropdown */}
          {!fixedLocalitySlug && filtersData && filtersData.localities.length > 0 && (
            <select
              aria-label="Filter by locality"
              value={currentParams.locality ?? ''}
              onChange={(e) =>
                onFilterChange({ locality: e.target.value || undefined, page: 1 })
              }
              className="h-8 shrink-0 rounded-lg border border-gray-200 bg-white px-2.5 text-xs font-medium text-ink focus:border-brand-500 focus:outline-none"
            >
              <option value="">All Localities</option>
              {filtersData.localities.map((loc) => (
                <option key={loc.slug} value={loc.slug}>
                  {loc.name}
                </option>
              ))}
            </select>
          )}

          {/* Near Me / Distance */}
          <button
            type="button"
            onClick={() =>
              hasCoordinates
                ? onFilterChange({
                    lat: undefined,
                    lng: undefined,
                    radius_km: undefined,
                    sort: activeSort === 'distance' ? 'relevance' : activeSort,
                    page: 1,
                  })
                : handleNearMe()
            }
            disabled={locating}
            aria-pressed={hasCoordinates}
            className={`inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg border px-3 text-xs font-semibold transition-colors ${
              hasCoordinates
                ? 'border-brand-600 bg-brand-50 text-brand-700'
                : 'border-gray-200 bg-white text-ink hover:bg-gray-50'
            }`}
          >
            {locating ? (
              <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
            ) : (
              <Crosshair className="h-3.5 w-3.5 text-signal-600" aria-hidden="true" />
            )}
            <span>{hasCoordinates ? `${currentParams.radius_km ?? 10} km` : 'Near Me'}</span>
          </button>

          {hasCoordinates && (
            <select
              aria-label="Distance radius"
              value={currentParams.radius_km ?? 10}
              onChange={(e) =>
                onFilterChange({ radius_km: Number(e.target.value), page: 1 })
              }
              className="h-8 shrink-0 rounded-lg border border-brand-200 bg-brand-50 px-2 text-xs font-semibold text-brand-700 focus:outline-none"
            >
              <option value={5}>5 km</option>
              <option value={10}>10 km</option>
              <option value={25}>25 km</option>
              <option value={50}>50 km</option>
            </select>
          )}

          {/* Open Now */}
          <button
            type="button"
            onClick={() => onFilterChange({ open_now: isOpenNow ? undefined : true, page: 1 })}
            aria-pressed={isOpenNow}
            className={`inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg border px-3 text-xs font-semibold transition-colors ${
              isOpenNow
                ? 'border-brand-600 bg-brand-50 text-brand-700'
                : 'border-gray-200 bg-white text-ink hover:bg-gray-50'
            }`}
          >
            <Clock className="h-3.5 w-3.5" aria-hidden="true" />
            <span>Open Now</span>
          </button>

          {/* Verified */}
          <button
            type="button"
            onClick={() =>
              onFilterChange({ verified_only: isVerified ? undefined : true, page: 1 })
            }
            aria-pressed={isVerified}
            className={`inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg border px-3 text-xs font-semibold transition-colors ${
              isVerified
                ? 'border-trust-600 bg-trust-50 text-trust-700'
                : 'border-gray-200 bg-white text-ink hover:bg-gray-50'
            }`}
          >
            <CheckCircle2 className="h-3.5 w-3.5" aria-hidden="true" />
            <span>Verified</span>
          </button>

          {/* Top Rated */}
          <button
            type="button"
            onClick={() =>
              onFilterChange({ min_rating: isTopRated ? undefined : 4, page: 1 })
            }
            aria-pressed={isTopRated}
            className={`inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg border px-3 text-xs font-semibold transition-colors ${
              isTopRated
                ? 'border-signal-500 bg-signal-50 text-signal-700'
                : 'border-gray-200 bg-white text-ink hover:bg-gray-50'
            }`}
          >
            <Star className="h-3.5 w-3.5" aria-hidden="true" />
            <span>4.0+ Rated</span>
          </button>

          {/* Sort */}
          <div className="inline-flex h-8 shrink-0 items-center gap-1 rounded-lg border border-gray-200 bg-white px-2">
            <ArrowUpDown className="h-3.5 w-3.5 text-ink-subtle" aria-hidden="true" />
            <select
              aria-label="Sort businesses"
              value={activeSort}
              onChange={(e) =>
                onFilterChange({ sort: e.target.value as SearchSortOptionDto, page: 1 })
              }
              className="bg-transparent text-xs font-semibold text-ink focus:outline-none"
            >
              {sortOptions.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
          </div>

          {/* More Filters (Amenities & Payment Methods) */}
          {filtersData &&
            (filtersData.amenities.length > 0 || filtersData.payment_methods.length > 0) && (
              <button
                type="button"
                onClick={() => setMoreOpen((prev) => !prev)}
                aria-expanded={moreOpen}
                className={`inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg border px-3 text-xs font-semibold transition-colors ${
                  moreOpen || moreCount > 0
                    ? 'border-brand-600 bg-brand-50 text-brand-700'
                    : 'border-gray-200 bg-white text-ink hover:bg-gray-50'
                }`}
              >
                <SlidersHorizontal className="h-3.5 w-3.5" aria-hidden="true" />
                <span>More Filters{moreCount > 0 ? ` (${moreCount})` : ''}</span>
              </button>
            )}

          {hasActiveFilters && (
            <button
              type="button"
              onClick={() => {
                setKeywordInput('');
                onResetFilters();
              }}
              className="inline-flex h-8 shrink-0 items-center gap-1 rounded-lg border border-rose-200 bg-rose-50 px-3 text-xs font-semibold text-rose-700 hover:bg-rose-100"
            >
              <X className="h-3.5 w-3.5" aria-hidden="true" />
              <span>Clear all</span>
            </button>
          )}
        </div>

        {/* Expandable More Filters panel (Amenities & Payment Methods) */}
        {moreOpen && filtersData && (
          <div className="mt-2.5 grid gap-4 rounded-xl border border-gray-200 bg-surface-subtle p-4 sm:grid-cols-2">
            {filtersData.amenities.length > 0 && (
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-muted">
                  Amenities &amp; Facilities
                </p>
                <div className="flex flex-wrap gap-1.5">
                  {filtersData.amenities.slice(0, 12).map((item) => {
                    const active = selectedAmenities.includes(item.slug);
                    return (
                      <button
                        key={item.slug}
                        type="button"
                        onClick={() =>
                          onFilterChange({
                            amenities: toggleArrayValue(currentParams.amenities, item.slug),
                            page: 1,
                          })
                        }
                        className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-medium transition-colors ${
                          active
                            ? 'border-brand-600 bg-brand-600 text-white'
                            : 'border-gray-200 bg-white text-ink hover:bg-gray-100'
                        }`}
                      >
                        {active && <Check className="h-3 w-3" aria-hidden="true" />}
                        {item.name}
                      </button>
                    );
                  })}
                </div>
              </div>
            )}

            {filtersData.payment_methods.length > 0 && (
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-muted">
                  Payment Methods
                </p>
                <div className="flex flex-wrap gap-1.5">
                  {filtersData.payment_methods.slice(0, 10).map((item) => {
                    const active = selectedPayments.includes(item.slug);
                    return (
                      <button
                        key={item.slug}
                        type="button"
                        onClick={() =>
                          onFilterChange({
                            payment_methods: toggleArrayValue(
                              currentParams.payment_methods,
                              item.slug
                            ),
                            page: 1,
                          })
                        }
                        className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-medium transition-colors ${
                          active
                            ? 'border-brand-600 bg-brand-600 text-white'
                            : 'border-gray-200 bg-white text-ink hover:bg-gray-100'
                        }`}
                      >
                        {active && <Check className="h-3 w-3" aria-hidden="true" />}
                        {item.name}
                      </button>
                    );
                  })}
                </div>
              </div>
            )}
          </div>
        )}

        {locationError && (
          <p className="mt-1.5 text-xs text-rose-600" role="alert">
            {locationError}
          </p>
        )}

        {/* Result count + removable active filter chips */}
        <div className="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-ink-muted">
          <p className="font-medium">
            Showing <span className="font-bold text-ink">{totalResults}</span>{' '}
            {totalResults === 1 ? 'listing' : 'listings'}
            {sponsoredCount > 0 ? ` (+${sponsoredCount} featured)` : ''}
          </p>

          <div className="flex flex-wrap items-center gap-1.5">
            {!fixedCategorySlug && currentParams.category && (
              <button
                type="button"
                onClick={() => onFilterChange({ category: undefined, page: 1 })}
                className="inline-flex items-center gap-1 rounded-full bg-brand-50 px-2.5 py-0.5 font-semibold text-brand-700 hover:bg-brand-100"
              >
                Category: {currentParams.category}
                <X className="h-3 w-3" aria-hidden="true" />
              </button>
            )}
            {!fixedLocalitySlug && currentParams.locality && (
              <button
                type="button"
                onClick={() => onFilterChange({ locality: undefined, page: 1 })}
                className="inline-flex items-center gap-1 rounded-full bg-brand-50 px-2.5 py-0.5 font-semibold text-brand-700 hover:bg-brand-100"
              >
                Locality: {currentParams.locality}
                <X className="h-3 w-3" aria-hidden="true" />
              </button>
            )}
            {currentParams.q && (
              <button
                type="button"
                onClick={() => {
                  setKeywordInput('');
                  onFilterChange({ q: undefined, page: 1 });
                }}
                className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-0.5 font-semibold text-ink hover:bg-gray-200"
              >
                &ldquo;{currentParams.q}&rdquo;
                <X className="h-3 w-3" aria-hidden="true" />
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
