'use client';

import { useState, useTransition } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { MapPin } from 'lucide-react';
import type {
  SearchBusinessHitDto,
  SearchFiltersDataDto,
  SearchQueryParams,
  SearchResponseMetaDto,
} from '@/shared/api/directory-types';
import { Breadcrumbs, type BreadcrumbItem } from '@/shared/ui/Breadcrumbs';
import { DirectoryCardSkeleton } from '@/shared/ui/Skeletons';
import { EmptyState } from '@/shared/ui/StateFeedback';
import { DirectoryListingCard } from './DirectoryListingCard';
import { QuickFilterBar } from './QuickFilterBar';
import { SearchFilterSidebar } from './SearchFilterSidebar';
import { SearchPagination } from './SearchPagination';

interface CanonicalDirectoryViewProps {
  citySlug: string;
  cityName: string;
  categorySlug: string;
  categoryName: string;
  localitySlug?: string;
  localityName?: string;
  hits: SearchBusinessHitDto[];
  meta: SearchResponseMetaDto | null;
  filtersData: SearchFiltersDataDto | null;
  currentParams: SearchQueryParams;
  baseCanonicalPath: string;
  breadcrumbs: BreadcrumbItem[];
}

export function CanonicalDirectoryView({
  citySlug,
  cityName,
  categorySlug,
  categoryName,
  localitySlug,
  localityName,
  hits,
  meta,
  filtersData,
  currentParams,
  baseCanonicalPath,
  breadcrumbs,
}: CanonicalDirectoryViewProps) {
  const router = useRouter();
  const [isPending, startTransition] = useTransition();
  const [mobileFiltersOpen, setMobileFiltersOpen] = useState(false);

  const totalResults = meta?.counts?.total_organic ?? hits.length;
  const sponsoredCount = meta?.counts?.sponsored_count ?? 0;
  const currentPage = meta?.pagination?.current_page ?? currentParams.page ?? 1;
  const totalPages = meta?.pagination?.last_page ?? 1;

  const hasActiveFilters = Boolean(
    (!localitySlug && currentParams.locality) ||
    currentParams.min_rating ||
    currentParams.verified_only ||
    currentParams.open_now ||
    currentParams.lat ||
    (currentParams.sort && currentParams.sort !== 'relevance')
  );

  function handleFilterChange(patch: Partial<SearchQueryParams>) {
    const nextParams = new URLSearchParams();

    // Preserve locality if present and not on fixed locality route
    const loc = localitySlug ?? (patch.locality !== undefined ? patch.locality : currentParams.locality);
    if (loc && !localitySlug) nextParams.set('locality', loc);

    const minRating = patch.min_rating !== undefined ? patch.min_rating : currentParams.min_rating;
    if (minRating) nextParams.set('min_rating', String(minRating));

    const verified = patch.verified_only !== undefined ? patch.verified_only : currentParams.verified_only;
    if (verified) nextParams.set('verified_only', '1');

    const openNow = patch.open_now !== undefined ? patch.open_now : currentParams.open_now;
    if (openNow) nextParams.set('open_now', '1');

    const sort = patch.sort !== undefined ? patch.sort : currentParams.sort;
    if (sort && sort !== 'relevance') nextParams.set('sort', sort);

    const lat = patch.lat !== undefined ? patch.lat : currentParams.lat;
    const lng = patch.lng !== undefined ? patch.lng : currentParams.lng;
    if (typeof lat === 'number' && typeof lng === 'number') {
      nextParams.set('lat', lat.toFixed(6));
      nextParams.set('lng', lng.toFixed(6));
      const radius = patch.radius_km !== undefined ? patch.radius_km : currentParams.radius_km ?? 10;
      nextParams.set('radius_km', String(radius));
    }

    const page = patch.page !== undefined ? patch.page : 1;
    if (page > 1) nextParams.set('page', String(page));

    const qs = nextParams.toString();
    const targetUrl = qs ? `${baseCanonicalPath}?${qs}` : baseCanonicalPath;

    startTransition(() => {
      router.push(targetUrl, { scroll: false });
    });
  }

  function handleResetFilters() {
    startTransition(() => {
      router.push(baseCanonicalPath, { scroll: false });
    });
  }

  function buildPageHref(targetPage: number): string {
    const nextParams = new URLSearchParams();

    if (!localitySlug && currentParams.locality) nextParams.set('locality', currentParams.locality);
    if (currentParams.min_rating) nextParams.set('min_rating', String(currentParams.min_rating));
    if (currentParams.verified_only) nextParams.set('verified_only', '1');
    if (currentParams.open_now) nextParams.set('open_now', '1');
    if (currentParams.sort && currentParams.sort !== 'relevance') nextParams.set('sort', currentParams.sort);
    if (currentParams.lat && currentParams.lng) {
      nextParams.set('lat', currentParams.lat.toFixed(6));
      nextParams.set('lng', currentParams.lng.toFixed(6));
      if (currentParams.radius_km) nextParams.set('radius_km', String(currentParams.radius_km));
    }

    if (targetPage > 1) {
      nextParams.set('page', String(targetPage));
    }

    const qs = nextParams.toString();
    return qs ? `${baseCanonicalPath}?${qs}` : baseCanonicalPath;
  }

  // Heading text
  const headingTitle = localityName
    ? `Top ${categoryName} in ${localityName}, ${cityName}`
    : `Best ${categoryName} in ${cityName}`;

  const localities = filtersData?.localities ?? [];

  return (
    <div className="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
      {/* Breadcrumb Navigation */}
      <div className="mb-4">
        <Breadcrumbs items={breadcrumbs} />
      </div>

      {/* Directory Page Header */}
      <div className="mb-5 border-b border-gray-200 pb-4">
        <h1 className="text-xl sm:text-2xl lg:text-3xl font-bold text-ink">
          {headingTitle}
        </h1>
        <p className="mt-1 text-xs sm:text-sm text-ink-muted">
          Showing <strong className="text-ink">{totalResults}</strong> verified {categoryName.toLowerCase()} in{' '}
          {localityName ? `${localityName}, ${cityName}` : cityName}
          {sponsoredCount > 0 ? ` (${sponsoredCount} featured)` : ''}.
        </p>
      </div>

      {/* Main 2-column Layout */}
      <div className="flex flex-col gap-6 lg:flex-row">
        {/* Left Filter Sidebar */}
        <SearchFilterSidebar
          filtersData={filtersData}
          currentParams={currentParams}
          onFilterChange={handleFilterChange}
          onResetFilters={handleResetFilters}
          isMobileOpen={mobileFiltersOpen}
          onCloseMobile={() => setMobileFiltersOpen(false)}
          fixedCity={citySlug}
          fixedCategory={categorySlug}
          fixedLocality={localitySlug}
        />

        {/* Right Listings Column */}
        <div className="flex-1 min-w-0 space-y-4">
          {/* Quick Filter Bar */}
          <QuickFilterBar
            currentParams={currentParams}
            onFilterChange={handleFilterChange}
            onResetFilters={handleResetFilters}
            onOpenMobileFilters={() => setMobileFiltersOpen(true)}
            hasActiveFilters={hasActiveFilters}
          />

          {/* Results State */}
          {isPending ? (
            <DirectoryCardSkeleton count={5} />
          ) : hits.length === 0 ? (
            <EmptyState
              title={`No ${categoryName} found`}
              description={
                hasActiveFilters
                  ? 'No businesses match the applied filters. Try broadening your filter selection.'
                  : `Currently there are no active verified ${categoryName.toLowerCase()} listed in this area.`
              }
              action={
                hasActiveFilters ? (
                  <button
                    type="button"
                    onClick={handleResetFilters}
                    className="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-700"
                  >
                    Reset Filters
                  </button>
                ) : (
                  <Link
                    href={`/search?city=${encodeURIComponent(citySlug)}`}
                    className="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-700"
                  >
                    Explore all businesses in {cityName}
                  </Link>
                )
              }
            />
          ) : (
            <div className="space-y-4" role="feed" aria-label="Business listings">
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
          {!isPending && hits.length > 0 && totalPages > 1 && (
            <div className="pt-2">
              <SearchPagination
                currentPage={currentPage}
                totalPages={totalPages}
                totalItems={totalResults}
                perPage={currentParams.per_page ?? 20}
                buildPageHref={buildPageHref}
                onPageChange={(p) => handleFilterChange({ page: p })}
              />
            </div>
          )}

          {/* Locality Cross-linking Mesh (per docs/12-seo-architecture.md) */}
          {localities.length > 0 && (
            <div className="mt-8 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
              <h2 className="text-sm font-bold text-ink flex items-center gap-1.5">
                <MapPin className="h-4 w-4 text-signal-600" aria-hidden="true" />
                <span>
                  {localityName
                    ? `Other Popular Localities for ${categoryName} in ${cityName}`
                    : `Explore ${categoryName} in Popular Localities in ${cityName}`}
                </span>
              </h2>
              <div className="mt-3 flex flex-wrap gap-2">
                {localityName && (
                  <Link
                    href={`/${encodeURIComponent(citySlug)}/${encodeURIComponent(categorySlug)}`}
                    className="rounded-lg border border-brand-300 bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700 hover:bg-brand-100"
                  >
                    All {cityName}
                  </Link>
                )}
                {localities
                  .filter((loc) => loc.slug !== localitySlug && loc.active_listing_count > 0)
                  .slice(0, 12)
                  .map((loc) => (
                    <Link
                      key={loc.slug}
                      href={`/${encodeURIComponent(citySlug)}/${encodeURIComponent(loc.slug)}/${encodeURIComponent(categorySlug)}`}
                      className="rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs text-ink hover:border-brand-400 hover:text-brand-700 hover:bg-white transition-colors"
                    >
                      <span>{loc.name}</span>
                      <span className="ml-1 text-[10px] text-ink-muted">({loc.active_listing_count})</span>
                    </Link>
                  ))}
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
