'use client';

import { useMemo, useState } from 'react';
import {
  CheckCircle2,
  ChevronDown,
  ChevronUp,
  Clock,
  Crosshair,
  Filter,
  MapPin,
  RotateCcw,
  Search,
  Star,
  X,
} from 'lucide-react';
import type {
  SearchFiltersDataDto,
  SearchQueryParams,
} from '@/shared/api/directory-types';

interface SearchFilterSidebarProps {
  filtersData: SearchFiltersDataDto | null;
  currentParams: SearchQueryParams;
  onFilterChange: (patch: Partial<SearchQueryParams>) => void;
  onResetFilters: () => void;
  isMobileOpen?: boolean;
  onCloseMobile?: () => void;
  fixedCity?: string;
  fixedCategory?: string;
  fixedLocality?: string;
}

export function SearchFilterSidebar({
  filtersData,
  currentParams,
  onFilterChange,
  onResetFilters,
  isMobileOpen = false,
  onCloseMobile,
  fixedCategory,
  fixedLocality,
}: SearchFilterSidebarProps) {
  const [localitySearch, setLocalitySearch] = useState('');
  const [showAllLocalities, setShowAllLocalities] = useState(false);
  const [showAllCategories, setShowAllCategories] = useState(false);

  const localities = useMemo(() => filtersData?.localities ?? [], [filtersData?.localities]);
  const categories = filtersData?.categories ?? [];
  const ratingOptions = filtersData?.rating_options ?? [
    { value: 4.5, label: '4.5+ Stars' },
    { value: 4, label: '4.0+ Stars' },
    { value: 3.5, label: '3.5+ Stars' },
  ];

  const filteredLocalities = useMemo(() => {
    if (!localitySearch.trim()) return localities;
    const q = localitySearch.toLowerCase().trim();
    return localities.filter(
      (loc) => loc.name.toLowerCase().includes(q) || loc.slug.toLowerCase().includes(q)
    );
  }, [localities, localitySearch]);

  const displayedLocalities = showAllLocalities
    ? filteredLocalities
    : filteredLocalities.slice(0, 8);

  const displayedCategories = showAllCategories
    ? categories
    : categories.slice(0, 10);

  const activeLocality = currentParams.locality ?? '';
  const activeCategory = currentParams.category ?? '';
  const activeMinRating = currentParams.min_rating ?? 0;
  const isVerifiedOnly = Boolean(currentParams.verified_only);
  const isOpenNow = Boolean(currentParams.open_now);
  const activeRadius = currentParams.radius_km ?? 10;
  const hasCoordinates =
    typeof currentParams.lat === 'number' && typeof currentParams.lng === 'number';

  const filterContent = (
    <div className="space-y-6">
      {/* Top Header (desktop sidebar only; mobile drawer renders its own header & reset footer) */}
      <div className="hidden lg:flex items-center justify-between border-b border-gray-200 pb-3">
        <div className="flex items-center gap-2">
          <Filter className="h-4 w-4 text-brand-600" aria-hidden="true" />
          <h2 className="text-sm font-bold text-ink">Filters</h2>
        </div>
        <button
          type="button"
          onClick={onResetFilters}
          className="inline-flex items-center gap-1 text-xs font-semibold text-ink-muted hover:text-brand-600"
        >
          <RotateCcw className="h-3 w-3" aria-hidden="true" />
          <span>Reset All</span>
        </button>
      </div>

      {/* Trust & Status Toggles */}
      <div className="space-y-2.5">
        <h3 className="text-xs font-bold uppercase tracking-wider text-ink-muted">
          Quick Filters
        </h3>
        <label className="flex items-center gap-2 text-xs font-medium text-ink cursor-pointer hover:text-brand-700">
          <input
            type="checkbox"
            checked={isVerifiedOnly}
            onChange={(e) =>
              onFilterChange({
                verified_only: e.target.checked || undefined,
                page: 1,
              })
            }
            className="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
          />
          <CheckCircle2 className="h-3.5 w-3.5 text-trust-600" aria-hidden="true" />
          <span>Verified Businesses Only</span>
        </label>

        <label className="flex items-center gap-2 text-xs font-medium text-ink cursor-pointer hover:text-brand-700">
          <input
            type="checkbox"
            checked={isOpenNow}
            onChange={(e) =>
              onFilterChange({
                open_now: e.target.checked || undefined,
                page: 1,
              })
            }
            className="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
          />
          <Clock className="h-3.5 w-3.5 text-emerald-600" aria-hidden="true" />
          <span>Open Now Only</span>
        </label>
      </div>

      {/* Locality Filter (if not fixed by canonical locality route) */}
      {!fixedLocality && localities.length > 0 && (
        <div className="space-y-2.5 border-t border-gray-100 pt-4">
          <div className="flex items-center justify-between">
            <h3 className="text-xs font-bold uppercase tracking-wider text-ink-muted flex items-center gap-1">
              <MapPin className="h-3 w-3 text-signal-600" aria-hidden="true" />
              <span>Locality</span>
            </h3>
            {activeLocality && (
              <button
                type="button"
                onClick={() => onFilterChange({ locality: undefined, page: 1 })}
                className="text-[11px] text-brand-600 hover:underline"
              >
                Clear
              </button>
            )}
          </div>

          {/* Quick search localities */}
          {localities.length > 6 && (
            <div className="relative">
              <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-gray-400" aria-hidden="true" />
              <input
                type="text"
                placeholder="Search localities..."
                value={localitySearch}
                onChange={(e) => setLocalitySearch(e.target.value)}
                className="w-full rounded-lg border border-gray-200 bg-gray-50 py-1.5 pl-8 pr-3 text-xs text-ink placeholder:text-gray-400 focus:bg-white focus:outline-none focus:border-brand-500"
              />
            </div>
          )}

          <div className="space-y-1.5 max-h-56 overflow-y-auto pr-1">
            <label className="flex items-center justify-between text-xs cursor-pointer py-0.5 hover:text-brand-700">
              <div className="flex items-center gap-2">
                <input
                  type="radio"
                  name="locality-radio"
                  checked={!activeLocality}
                  onChange={() => onFilterChange({ locality: undefined, page: 1 })}
                  className="h-3.5 w-3.5 text-brand-600 focus:ring-brand-500"
                />
                <span className={!activeLocality ? 'font-semibold text-brand-700' : 'text-ink'}>
                  All Localities
                </span>
              </div>
            </label>

            {displayedLocalities.map((loc) => {
              const isSelected = activeLocality === loc.slug;
              return (
                <label
                  key={loc.slug}
                  className="flex items-center justify-between text-xs cursor-pointer py-0.5 hover:text-brand-700"
                >
                  <div className="flex items-center gap-2 min-w-0">
                    <input
                      type="radio"
                      name="locality-radio"
                      checked={isSelected}
                      onChange={() => onFilterChange({ locality: loc.slug, page: 1 })}
                      className="h-3.5 w-3.5 text-brand-600 focus:ring-brand-500"
                    />
                    <span
                      className={`truncate ${
                        isSelected ? 'font-semibold text-brand-700' : 'text-ink'
                      }`}
                    >
                      {loc.name}
                    </span>
                  </div>
                  {loc.active_listing_count > 0 && (
                    <span className="ml-2 rounded-full bg-gray-100 px-1.5 py-0.2 text-[10px] font-medium text-ink-muted shrink-0">
                      {loc.active_listing_count}
                    </span>
                  )}
                </label>
              );
            })}
          </div>

          {filteredLocalities.length > 8 && (
            <button
              type="button"
              onClick={() => setShowAllLocalities(!showAllLocalities)}
              className="inline-flex items-center gap-1 text-[11px] font-semibold text-brand-600 hover:underline"
            >
              <span>{showAllLocalities ? 'Show Less' : `+${filteredLocalities.length - 8} More`}</span>
              {showAllLocalities ? (
                <ChevronUp className="h-3 w-3" aria-hidden="true" />
              ) : (
                <ChevronDown className="h-3 w-3" aria-hidden="true" />
              )}
            </button>
          )}
        </div>
      )}

      {/* Categories (if not on a fixed category page) */}
      {!fixedCategory && categories.length > 0 && (
        <div className="space-y-2.5 border-t border-gray-100 pt-4">
          <div className="flex items-center justify-between">
            <h3 className="text-xs font-bold uppercase tracking-wider text-ink-muted">
              Category
            </h3>
            {activeCategory && (
              <button
                type="button"
                onClick={() => onFilterChange({ category: undefined, page: 1 })}
                className="text-[11px] text-brand-600 hover:underline"
              >
                Clear
              </button>
            )}
          </div>

          <div className="space-y-1.5 max-h-52 overflow-y-auto pr-1">
            <label className="flex items-center justify-between text-xs cursor-pointer py-0.5 hover:text-brand-700">
              <div className="flex items-center gap-2">
                <input
                  type="radio"
                  name="category-radio"
                  checked={!activeCategory}
                  onChange={() => onFilterChange({ category: undefined, page: 1 })}
                  className="h-3.5 w-3.5 text-brand-600 focus:ring-brand-500"
                />
                <span className={!activeCategory ? 'font-semibold text-brand-700' : 'text-ink'}>
                  All Categories
                </span>
              </div>
            </label>

            {displayedCategories.map((cat) => {
              const isSelected = activeCategory === cat.slug;
              return (
                <label
                  key={cat.slug}
                  className="flex items-center justify-between text-xs cursor-pointer py-0.5 hover:text-brand-700"
                >
                  <div className="flex items-center gap-2 min-w-0">
                    <input
                      type="radio"
                      name="category-radio"
                      checked={isSelected}
                      onChange={() => onFilterChange({ category: cat.slug, page: 1 })}
                      className="h-3.5 w-3.5 text-brand-600 focus:ring-brand-500"
                    />
                    <span
                      className={`truncate ${
                        isSelected ? 'font-semibold text-brand-700' : 'text-ink'
                      }`}
                    >
                      {cat.name}
                    </span>
                  </div>
                </label>
              );
            })}
          </div>

          {categories.length > 10 && (
            <button
              type="button"
              onClick={() => setShowAllCategories(!showAllCategories)}
              className="inline-flex items-center gap-1 text-[11px] font-semibold text-brand-600 hover:underline"
            >
              <span>{showAllCategories ? 'Show Less' : `+${categories.length - 10} More`}</span>
              {showAllCategories ? (
                <ChevronUp className="h-3 w-3" aria-hidden="true" />
              ) : (
                <ChevronDown className="h-3 w-3" aria-hidden="true" />
              )}
            </button>
          )}
        </div>
      )}

      {/* Customer Ratings */}
      <div className="space-y-2 border-t border-gray-100 pt-4">
        <h3 className="text-xs font-bold uppercase tracking-wider text-ink-muted">
          Customer Rating
        </h3>
        <div className="space-y-1.5">
          <label className="flex items-center gap-2 text-xs cursor-pointer hover:text-brand-700">
            <input
              type="radio"
              name="rating-filter"
              checked={activeMinRating === 0}
              onChange={() => onFilterChange({ min_rating: undefined, page: 1 })}
              className="h-3.5 w-3.5 text-brand-600 focus:ring-brand-500"
            />
            <span className={activeMinRating === 0 ? 'font-semibold text-brand-700' : 'text-ink'}>
              Any Rating
            </span>
          </label>

          {ratingOptions.map((opt) => {
            const isSelected = activeMinRating === opt.value;
            return (
              <label
                key={opt.value}
                className="flex items-center gap-2 text-xs cursor-pointer hover:text-brand-700"
              >
                <input
                  type="radio"
                  name="rating-filter"
                  checked={isSelected}
                  onChange={() => onFilterChange({ min_rating: opt.value, page: 1 })}
                  className="h-3.5 w-3.5 text-brand-600 focus:ring-brand-500"
                />
                <span className="flex items-center gap-1">
                  <Star className="h-3.5 w-3.5 fill-amber-500 text-amber-500" aria-hidden="true" />
                  <span className={isSelected ? 'font-semibold text-brand-700' : 'text-ink'}>
                    {opt.label}
                  </span>
                </span>
              </label>
            );
          })}
        </div>
      </div>

      {/* Distance / Radius (enforced by Laravel spatial query only when GPS coordinates are active) */}
      {hasCoordinates && (
        <div className="space-y-2 border-t border-gray-100 pt-4">
          <h3 className="text-xs font-bold uppercase tracking-wider text-ink-muted flex items-center gap-1">
            <Crosshair className="h-3 w-3 text-signal-600" aria-hidden="true" />
            <span>Search Radius</span>
          </h3>
          <div className="grid grid-cols-4 gap-1.5">
            {[5, 10, 25, 50].map((km) => {
              const isSelected = activeRadius === km;
              return (
                <button
                  key={km}
                  type="button"
                  onClick={() => onFilterChange({ radius_km: km, page: 1 })}
                  className={`rounded-lg py-1 text-center text-xs font-semibold transition-colors ${
                    isSelected
                      ? 'bg-signal-600 text-white shadow-2xs'
                      : 'border border-gray-200 bg-gray-50 text-ink hover:bg-gray-100'
                  }`}
                >
                  {km} km
                </button>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );

  return (
    <>
      {/* Desktop Sticky Sidebar */}
      <aside
        aria-label="Directory search filters"
        className="hidden lg:block w-64 shrink-0 self-start sticky top-20 rounded-xl border border-gray-200 bg-white p-5 shadow-sm"
      >
        {filterContent}
      </aside>

      {/* Mobile Drawer */}
      {isMobileOpen && (
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="mobile-filters-heading"
          className="fixed inset-0 z-50 flex lg:hidden bg-black/60 backdrop-blur-xs"
          onClick={(e) => {
            if (e.target === e.currentTarget) onCloseMobile?.();
          }}
        >
          <div className="relative ml-auto flex h-full w-full max-w-xs flex-col bg-white shadow-xl animate-in slide-in-from-right duration-200">
            <div className="flex items-center justify-between border-b border-gray-200 px-4 py-3">
              <h2 id="mobile-filters-heading" className="text-sm font-bold text-ink">
                Filters
              </h2>
              <button
                type="button"
                onClick={onCloseMobile}
                className="rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                aria-label="Close filters drawer"
              >
                <X className="h-5 w-5" aria-hidden="true" />
              </button>
            </div>

            <div className="flex-1 overflow-y-auto p-4">{filterContent}</div>

            <div className="border-t border-gray-200 p-4 flex gap-2">
              <button
                type="button"
                onClick={onResetFilters}
                className="flex-1 rounded-lg border border-gray-300 py-2 text-xs font-semibold text-ink-muted hover:bg-gray-50"
              >
                Reset
              </button>
              <button
                type="button"
                onClick={onCloseMobile}
                className="flex-1 rounded-lg bg-brand-600 py-2 text-xs font-semibold text-white hover:bg-brand-700"
              >
                Apply Filters
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
