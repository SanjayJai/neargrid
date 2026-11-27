'use client';

import { useCallback, useEffect, useRef, useState, useTransition } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { bffFetch } from '@/shared/api/bff-client';
import type {
  SearchBusinessHitDto,
  SearchFiltersDataDto,
  SearchQueryParams,
  SearchResponseMetaDto,
} from '@/shared/api/directory-types';
import { Breadcrumbs } from '@/shared/ui/Breadcrumbs';
import { DirectoryCardSkeleton } from '@/shared/ui/Skeletons';
import { EmptyState, ErrorState } from '@/shared/ui/StateFeedback';
import {
  buildShareableSearchUrl,
  parseRawSearchParams,
  urlSearchParamsToRecord,
} from '@/modules/customer/lib/search-url';
import { DirectoryListingCard } from './DirectoryListingCard';
import { HorizontalFilterBar } from './HorizontalFilterBar';
import { SearchPagination } from './SearchPagination';

interface SearchResultsClientProps {
  initialHits: SearchBusinessHitDto[];
  initialMeta: SearchResponseMetaDto | null;
  initialFiltersData: SearchFiltersDataDto | null;
  initialParams: SearchQueryParams;
  cityDisplayName?: string;
}

export function SearchResultsClient({
  initialHits,
  initialMeta,
  initialFiltersData,
  initialParams,
  cityDisplayName,
}: SearchResultsClientProps) {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [, startTransition] = useTransition();

  const [hits, setHits] = useState<SearchBusinessHitDto[]>(initialHits);
  const [meta, setMeta] = useState<SearchResponseMetaDto | null>(initialMeta);
  const filtersData = initialFiltersData;
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const abortControllerRef = useRef<AbortController | null>(null);
  const isInitialMount = useRef(true);

  // Compute current params from the active URL searchParams (preserving multi-value keys)
  const rawParamsRecord = urlSearchParamsToRecord(searchParams);
  const currentParams = parseRawSearchParams(rawParamsRecord);

  // Keep fallback city if none in URL
  if (!currentParams.city && initialParams.city) {
    currentParams.city = initialParams.city;
  }

  const activeCityName =
    cityDisplayName ||
    filtersData?.cities.find((c) => c.slug === currentParams.city)?.name ||
    currentParams.city ||
    'Chennai';

  const hasActiveFilters = Boolean(
    currentParams.locality ||
    currentParams.category ||
    currentParams.min_rating ||
    currentParams.verified_only ||
    currentParams.open_now ||
    currentParams.lat ||
    (currentParams.attr && Object.keys(currentParams.attr).length > 0) ||
    (currentParams.sort && currentParams.sort !== 'relevance')
  );

  // Fetch results when searchParams change (supports browser back/forward)
  const fetchResults = useCallback(
    async (params: SearchQueryParams) => {
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
      const controller = new AbortController();
      abortControllerRef.current = controller;

      setLoading(true);
      setError(null);

      try {
        const qs = buildShareableSearchUrl({
          ...params,
        }).replace('/search', '');

        const url = `/api/public/search${qs}`;
        const res = await bffFetch<SearchBusinessHitDto[]>(url, {
          signal: controller.signal,
        });

        if (res.ok && res.payload && res.payload.status === 'success') {
          setHits(res.payload.data ?? []);
          setMeta((res.payload.meta as SearchResponseMetaDto) ?? null);
        } else {
          const msg =
            (res.payload && res.payload.status === 'error' && res.payload.error.message) ||
            'Failed to load search results. Please try again.';
          setError(msg);
        }
      } catch (err: unknown) {
        if ((err as Error)?.name === 'AbortError') return;
        setError('Network error while searching businesses. Please check your connection.');
      } finally {
        setLoading(false);
      }
    },
    []
  );

  // Watch searchParams for back/forward browser navigation
  useEffect(() => {
    if (isInitialMount.current) {
      isInitialMount.current = false;
      return;
    }
    const parsed = parseRawSearchParams(urlSearchParamsToRecord(searchParams));
    if (!parsed.city && initialParams.city) parsed.city = initialParams.city;
    fetchResults(parsed);
  }, [searchParams, fetchResults, initialParams.city]);

  // Navigate & update URL
  const updateParamsAndUrl = (patch: Partial<SearchQueryParams>) => {
    const nextParams: SearchQueryParams = {
      ...currentParams,
      ...patch,
    };

    // If changing filters (other than page), reset page to 1
    if (!('page' in patch)) {
      nextParams.page = 1;
    }

    const nextUrl = buildShareableSearchUrl({
      city: nextParams.city,
      locality: nextParams.locality,
      localities: nextParams.localities,
      category: nextParams.category,
      q: nextParams.q,
      lat: nextParams.lat,
      lng: nextParams.lng,
      radius_km: nextParams.radius_km,
      min_rating: nextParams.min_rating,
      verified_only: nextParams.verified_only,
      open_now: nextParams.open_now,
      sort: nextParams.sort,
      page: nextParams.page,
      locale: nextParams.locale,
    });

    startTransition(() => {
      router.push(nextUrl, { scroll: false });
    });
  };

  const handleResetFilters = () => {
    updateParamsAndUrl({
      q: undefined,
      locality: undefined,
      category: undefined,
      localities: undefined,
      amenities: undefined,
      payment_methods: undefined,
      min_rating: undefined,
      verified_only: undefined,
      open_now: undefined,
      lat: undefined,
      lng: undefined,
      radius_km: undefined,
      sort: 'relevance',
      page: 1,
    });
  };

  // Build page link for SSR pagination
  const buildPageHref = (page: number) => {
    return buildShareableSearchUrl({
      ...currentParams,
      page,
    });
  };

  const totalResults = meta?.counts?.total_organic ?? hits.length;
  const sponsoredCount = meta?.counts?.sponsored_count ?? 0;
  const currentPage = meta?.pagination?.current_page ?? currentParams.page ?? 1;
  const totalPages = meta?.pagination?.last_page ?? 1;

  // Title generation
  const pageTitle = currentParams.q
    ? `Results for "${currentParams.q}" in ${activeCityName}`
    : currentParams.category
      ? `${currentParams.category.replace(/-/g, ' ')} in ${activeCityName}`
      : `Businesses in ${activeCityName}`;

  return (
    <div className="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
      {/* Breadcrumbs */}
      <div className="mb-4">
        <Breadcrumbs
          items={[
            { label: 'Home', href: '/' },
            { label: activeCityName, href: `/search?city=${encodeURIComponent(currentParams.city || 'chennai')}` },
            { label: currentParams.q ? `Search: ${currentParams.q}` : 'Search Results' },
          ]}
        />
      </div>

      {/* Search Header Banner */}
      <div className="mb-4 flex flex-col justify-between gap-2 border-b border-gray-200 pb-3 sm:flex-row sm:items-center">
        <div>
          <h1 className="text-xl sm:text-2xl font-bold capitalize text-ink">
            {pageTitle}
          </h1>
          <p className="mt-0.5 text-xs text-ink-muted">
            {loading ? (
              'Searching verified directory...'
            ) : (
              <>
                Showing <strong className="text-ink">{totalResults}</strong> verified business
                {totalResults === 1 ? '' : 'es'}
                {sponsoredCount > 0 && ` (${sponsoredCount} sponsored)`}
              </>
            )}
          </p>
        </div>
      </div>

      {/* Full-Width Horizontal Filter Bar */}
      <HorizontalFilterBar
        currentParams={currentParams}
        filtersData={filtersData}
        onFilterChange={updateParamsAndUrl}
        onResetFilters={handleResetFilters}
        hasActiveFilters={hasActiveFilters}
        totalResults={totalResults}
        sponsoredCount={sponsoredCount}
        showSearchWithin
        locale={currentParams.locale === 'ta' ? 'ta' : 'en'}
      />

      {/* Full-Width Business Results */}
      <div className="mt-5 w-full space-y-4">

          {/* Results State */}
          {loading ? (
            <DirectoryCardSkeleton count={5} />
          ) : error ? (
            <ErrorState
              title="Search Unavailable"
              message={error}
              onRetry={() => fetchResults(currentParams)}
            />
          ) : hits.length === 0 ? (
            <EmptyState
              title="No businesses found"
              description={`We couldn't find any businesses matching your search in ${activeCityName}. Try broadening your keywords or removing filters.`}
              action={
                <button
                  type="button"
                  onClick={handleResetFilters}
                  className="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-700"
                >
                  Reset All Filters
                </button>
              }
            />
          ) : (
            <div className="space-y-4" role="feed" aria-label="Search results list">
              {hits.map((hit, index) => (
                <DirectoryListingCard
                  key={hit.public_id}
                  hit={hit}
                  priority={index === 0}
                />
              ))}
            </div>
          )}

          {/* Pagination */}
          {!loading && hits.length > 0 && totalPages > 1 && (
            <div className="pt-2">
              <SearchPagination
                currentPage={currentPage}
                totalPages={totalPages}
                totalItems={totalResults}
                perPage={currentParams.per_page ?? 20}
                buildPageHref={buildPageHref}
                onPageChange={(p) => updateParamsAndUrl({ page: p })}
              />
            </div>
          )}
      </div>
    </div>
  );
}
