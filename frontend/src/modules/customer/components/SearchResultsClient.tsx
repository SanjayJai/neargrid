'use client';

import { useMemo, useState } from 'react';
import { SlidersHorizontal } from 'lucide-react';
import {
  DEMO_BUSINESSES,
  DEMO_CATEGORIES,
  DEMO_CITIES,
} from '@/shared/data/demo-directory';
import { DemoBanner } from '@/shared/ui/DemoBanner';
import { BusinessCard } from './BusinessCard';
import { HeroSearchForm } from './HeroSearchForm';

interface SearchResultsClientProps {
  initialCity: string;
  initialQuery: string;
  initialCategory: string;
}

export function SearchResultsClient({
  initialCity,
  initialQuery,
  initialCategory,
}: SearchResultsClientProps) {
  const [category, setCategory] = useState(initialCategory);
  const [minRating, setMinRating] = useState<number>(0);
  const [verifiedOnly, setVerifiedOnly] = useState(false);
  const [openNow, setOpenNow] = useState(false);
  const [sortBy, setSortBy] = useState<'relevance' | 'rating' | 'distance'>('relevance');
  const [showMobileFilters, setShowMobileFilters] = useState(false);

  const activeCity = DEMO_CITIES.find((c) => c.slug === initialCity) ?? DEMO_CITIES[0];

  const filteredBusinesses = useMemo(() => {
    const q = initialQuery.trim().toLowerCase();

    const matches = DEMO_BUSINESSES.filter((biz) => {
      if (category && biz.categorySlug !== category) return false;
      if (verifiedOnly && !biz.isVerified) return false;
      if (openNow && !biz.isOpenNow) return false;
      if (minRating > 0 && biz.ratingAvg < minRating) return false;
      if (q) {
        const haystack = `${biz.name} ${biz.tagline} ${biz.categoryName} ${biz.localityName} ${biz.description}`.toLowerCase();
        if (!haystack.includes(q)) return false;
      }
      return true;
    });

    return [...matches].sort((a, b) => {
      if (sortBy === 'rating') return b.ratingAvg - a.ratingAvg;
      if (sortBy === 'distance') return a.distanceKm - b.distanceKm;
      if (a.isSponsored !== b.isSponsored) return a.isSponsored ? -1 : 1;
      return b.ratingAvg - a.ratingAvg;
    });
  }, [category, initialQuery, minRating, openNow, sortBy, verifiedOnly]);

  function resetFilters() {
    setCategory('');
    setMinRating(0);
    setVerifiedOnly(false);
    setOpenNow(false);
    setSortBy('relevance');
  }

  return (
    <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
      <div className="mb-6">
        <HeroSearchForm initialCity={activeCity.slug} initialQuery={initialQuery} />
      </div>

      <div className="mb-6">
        <DemoBanner description="Search results below are filtered client-side from fictional demo fixtures. Live MySQL 8.0 Spatial + English/Tamil FullText search (/api/v1/search) will connect in Phase 3B." />
      </div>

      <div className="mb-6 flex flex-col justify-between gap-4 border-b border-zinc-200 pb-4 sm:flex-row sm:items-center">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight text-zinc-950">
            {initialQuery
              ? `Results for “${initialQuery}” in ${activeCity.name}`
              : category
                ? `${DEMO_CATEGORIES.find((c) => c.slug === category)?.name ?? category} in ${activeCity.name}`
                : `Local Businesses in ${activeCity.name}`}
          </h1>
          <p className="mt-1 text-sm text-zinc-500">
            Showing {filteredBusinesses.length} demo listing
            {filteredBusinesses.length === 1 ? '' : 's'}
          </p>
        </div>

        <div className="flex items-center gap-3">
          <button
            type="button"
            onClick={() => setShowMobileFilters((prev) => !prev)}
            className="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-900 lg:hidden"
          >
            <SlidersHorizontal className="h-4 w-4" aria-hidden="true" />
            Filters
          </button>

          <label htmlFor="sort-select" className="text-xs font-medium text-zinc-500">
            Sort by
          </label>
          <select
            id="sort-select"
            value={sortBy}
            onChange={(e) => setSortBy(e.target.value as 'relevance' | 'rating' | 'distance')}
            className="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-900"
          >
            <option value="relevance">Relevance</option>
            <option value="rating">Highest Rated</option>
            <option value="distance">Nearest First</option>
          </select>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-8 lg:grid-cols-12">
        <aside
          aria-label="Search filters"
          className={`space-y-6 rounded-2xl border border-zinc-200 bg-white p-5 lg:col-span-3 lg:block lg:self-start ${
            showMobileFilters ? 'block' : 'hidden'
          }`}
        >
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-semibold text-zinc-950">Filters</h2>
            <button
              type="button"
              onClick={resetFilters}
              className="text-xs font-medium text-zinc-500 underline-offset-2 hover:text-zinc-950 hover:underline"
            >
              Reset all
            </button>
          </div>

          <div>
            <label htmlFor="filter-category" className="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-zinc-500">
              Category
            </label>
            <select
              id="filter-category"
              value={category}
              onChange={(e) => setCategory(e.target.value)}
              className="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950"
            >
              <option value="">All Categories</option>
              {DEMO_CATEGORIES.map((cat) => (
                <option key={cat.slug} value={cat.slug}>
                  {cat.name}
                </option>
              ))}
            </select>
          </div>

          <div>
            <span className="mb-2 block text-xs font-semibold uppercase tracking-wider text-zinc-500">
              Minimum Rating
            </span>
            <div className="space-y-2 text-sm">
              {[0, 4.0, 4.5].map((threshold) => (
                <label key={threshold} className="flex cursor-pointer items-center gap-2 text-zinc-800">
                  <input
                    type="radio"
                    name="min-rating"
                    checked={minRating === threshold}
                    onChange={() => setMinRating(threshold)}
                    className="h-4 w-4 accent-zinc-950"
                  />
                  <span>{threshold === 0 ? 'Any rating' : `${threshold.toFixed(1)}+ stars`}</span>
                </label>
              ))}
            </div>
          </div>

          <div className="space-y-2.5 border-t border-zinc-100 pt-4 text-sm">
            <label className="flex cursor-pointer items-center gap-2 text-zinc-800">
              <input
                type="checkbox"
                checked={verifiedOnly}
                onChange={(e) => setVerifiedOnly(e.target.checked)}
                className="h-4 w-4 rounded accent-zinc-950"
              />
              <span>Verified businesses only</span>
            </label>
            <label className="flex cursor-pointer items-center gap-2 text-zinc-800">
              <input
                type="checkbox"
                checked={openNow}
                onChange={(e) => setOpenNow(e.target.checked)}
                className="h-4 w-4 rounded accent-zinc-950"
              />
              <span>Open now</span>
            </label>
          </div>
        </aside>

        <section aria-label="Business results" className="lg:col-span-9">
          {filteredBusinesses.length === 0 ? (
            <div className="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 p-10 text-center">
              <h2 className="text-base font-semibold text-zinc-950">No demo listings match those filters</h2>
              <p className="mt-1 text-sm text-zinc-600">
                Try clearing a filter or broadening your search term.
              </p>
              <button
                type="button"
                onClick={resetFilters}
                className="mt-4 rounded-lg bg-zinc-950 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800"
              >
                Clear Filters
              </button>
            </div>
          ) : (
            <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
              {filteredBusinesses.map((biz) => (
                <BusinessCard key={biz.publicId} business={biz} />
              ))}
            </div>
          )}
        </section>
      </div>
    </div>
  );
}
