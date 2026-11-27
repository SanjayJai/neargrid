'use client';

import { useTransition } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Layers, MapPin, Sparkles } from 'lucide-react';
import type {
  CategoryLandingResponseDto,
  ReferenceCityDto,
  SearchBusinessHitDto,
  SearchFiltersDataDto,
  SearchQueryParams,
  SearchResponseMetaDto,
} from '@/shared/api/directory-types';
import { Breadcrumbs, type BreadcrumbItem } from '@/shared/ui/Breadcrumbs';
import { DirectoryCardSkeleton } from '@/shared/ui/Skeletons';
import { EmptyState } from '@/shared/ui/StateFeedback';
import { DirectoryListingCard } from './DirectoryListingCard';
import { HorizontalFilterBar } from './HorizontalFilterBar';
import { SearchPagination } from './SearchPagination';
import { AdPlacementSlot } from './ads/AdPlacementSlot';

interface CategoryLandingViewProps {
  landing: CategoryLandingResponseDto;
  cities: ReferenceCityDto[];
  selectedCitySlug: string;
  selectedCityName: string;
  hits: SearchBusinessHitDto[];
  meta: SearchResponseMetaDto | null;
  filtersData: SearchFiltersDataDto | null;
  currentParams: SearchQueryParams;
  basePath: string;
  breadcrumbs: BreadcrumbItem[];
}

export function CategoryLandingView({
  landing,
  cities,
  selectedCitySlug,
  selectedCityName,
  hits,
  meta,
  filtersData,
  currentParams,
  basePath,
  breadcrumbs,
}: CategoryLandingViewProps) {
  const router = useRouter();
  const [isPending, startTransition] = useTransition();

  const isCollection = landing.kind === 'collection';
  const title = isCollection
    ? landing.collection?.name ?? 'Collection'
    : landing.category?.name ?? 'Category';
  const description = isCollection
    ? landing.collection?.description ?? null
    : landing.seo?.description ?? null;
  const children = landing.category?.children ?? [];

  const totalResults = meta?.counts?.total_organic ?? hits.length;
  const sponsoredCount = meta?.counts?.sponsored_count ?? 0;
  const currentPage = meta?.pagination?.current_page ?? currentParams.page ?? 1;
  const totalPages = meta?.pagination?.last_page ?? 1;

  const hasActiveFilters = Boolean(
    currentParams.q ||
      currentParams.locality ||
      currentParams.min_rating ||
      currentParams.verified_only ||
      currentParams.open_now ||
      currentParams.lat ||
      (currentParams.amenities && currentParams.amenities.length > 0) ||
      (currentParams.payment_methods && currentParams.payment_methods.length > 0) ||
      (currentParams.sort && currentParams.sort !== 'relevance')
  );

  function buildUrl(patch: Partial<SearchQueryParams>): string {
    const next = new URLSearchParams();
    const city = patch.city !== undefined ? patch.city : selectedCitySlug;
    if (city) next.set('city', city);

    const q = patch.q !== undefined ? patch.q : currentParams.q;
    if (q) next.set('q', q);

    const loc = patch.locality !== undefined ? patch.locality : currentParams.locality;
    if (loc) next.set('locality', loc);

    const minRating = patch.min_rating !== undefined ? patch.min_rating : currentParams.min_rating;
    if (minRating) next.set('min_rating', String(minRating));

    const verified = patch.verified_only !== undefined ? patch.verified_only : currentParams.verified_only;
    if (verified) next.set('verified_only', '1');

    const openNow = patch.open_now !== undefined ? patch.open_now : currentParams.open_now;
    if (openNow) next.set('open_now', '1');

    const amenities = patch.amenities !== undefined ? patch.amenities : currentParams.amenities;
    if (amenities) {
      for (const a of amenities) next.append('amenities[]', a);
    }

    const payments =
      patch.payment_methods !== undefined ? patch.payment_methods : currentParams.payment_methods;
    if (payments) {
      for (const pm of payments) next.append('payment_methods[]', pm);
    }

    const sort = patch.sort !== undefined ? patch.sort : currentParams.sort;
    if (sort && sort !== 'relevance') next.set('sort', sort);

    const lat = patch.lat !== undefined ? patch.lat : currentParams.lat;
    const lng = patch.lng !== undefined ? patch.lng : currentParams.lng;
    if (typeof lat === 'number' && typeof lng === 'number') {
      next.set('lat', lat.toFixed(6));
      next.set('lng', lng.toFixed(6));
      next.set('radius_km', String(patch.radius_km ?? currentParams.radius_km ?? 10));
    }

    const page = patch.page !== undefined ? patch.page : 1;
    if (page > 1) next.set('page', String(page));

    if (currentParams.locale && currentParams.locale !== 'en') {
      next.set('locale', currentParams.locale);
    }

    const qs = next.toString();
    return qs ? `${basePath}?${qs}` : basePath;
  }

  function handleFilterChange(patch: Partial<SearchQueryParams>) {
    startTransition(() => {
      router.push(buildUrl(patch), { scroll: false });
    });
  }

  function handleResetFilters() {
    startTransition(() => {
      router.push(
        buildUrl({
          q: undefined,
          locality: undefined,
          min_rating: undefined,
          verified_only: undefined,
          open_now: undefined,
          amenities: undefined,
          payment_methods: undefined,
          lat: undefined,
          lng: undefined,
          radius_km: undefined,
          sort: 'relevance',
          page: 1,
        }),
        { scroll: false }
      );
    });
  }

  return (
    <div className="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
      <div className="mb-4">
        <Breadcrumbs items={breadcrumbs} />
      </div>

      {/* Category Hero Header */}
      <div className="mb-5 rounded-2xl border border-gray-200 bg-gradient-to-br from-brand-50/80 via-white to-signal-50/40 p-5 sm:p-6">
        <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
          <div>
            <div className="mb-2 inline-flex items-center gap-1.5 rounded-full bg-brand-100 px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide text-brand-700">
              {isCollection ? (
                <>
                  <Sparkles className="h-3 w-3" aria-hidden="true" />
                  Curated Collection
                </>
              ) : (
                <>
                  <Layers className="h-3 w-3" aria-hidden="true" />
                  {(landing.category?.node_type ?? 'category').replace('_', ' ')}
                </>
              )}
            </div>
            <h1 className="text-2xl font-extrabold tracking-tight text-ink sm:text-3xl">
              {title} in <span className="text-brand-600">{selectedCityName}</span>
            </h1>
            {description && (
              <p className="mt-1.5 max-w-2xl text-sm text-ink-muted">{description}</p>
            )}
          </div>

          {/* City switcher for this category */}
          {cities.length > 0 && (
            <div className="shrink-0">
              <label
                htmlFor="cat-city-select"
                className="mb-1 flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-ink-muted"
              >
                <MapPin className="h-3 w-3 text-signal-600" aria-hidden="true" />
                Location Context
              </label>
              <select
                id="cat-city-select"
                value={selectedCitySlug}
                onChange={(e) => handleFilterChange({ city: e.target.value, locality: undefined, page: 1 })}
                className="h-9 rounded-xl border border-gray-200 bg-white px-3 text-xs font-semibold text-ink shadow-sm focus:border-brand-500 focus:outline-none"
              >
                {cities.map((c) => (
                  <option key={c.slug} value={c.slug}>
                    {c.name}
                  </option>
                ))}
              </select>
            </div>
          )}
        </div>

        {/* Subcategories / Specialities Chips */}
        {children.length > 0 && (
          <div className="mt-5 border-t border-gray-200/80 pt-4">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-muted">
              Explore Subcategories &amp; Specialities
            </p>
            <div className="flex flex-wrap gap-2">
              {children.map((child) => (
                <Link
                  key={child.public_id}
                  href={`${child.path}?city=${encodeURIComponent(selectedCitySlug)}`}
                  className="inline-flex items-center rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-ink shadow-sm transition-colors hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700"
                >
                  {child.name}
                </Link>
              ))}
            </div>
          </div>
        )}
      </div>

      {/* Full-Width Horizontal Filter Bar (No Left Sidebar) */}
      <HorizontalFilterBar
        currentParams={currentParams}
        filtersData={filtersData}
        onFilterChange={handleFilterChange}
        onResetFilters={handleResetFilters}
        hasActiveFilters={hasActiveFilters}
        totalResults={totalResults}
        sponsoredCount={sponsoredCount}
        fixedCategorySlug={landing.category?.slug}
        showSearchWithin
        locale={currentParams.locale === 'ta' ? 'ta' : 'en'}
      />

      {/* Full-Width Listings */}
      <div className="mt-5 w-full space-y-4">
        <AdPlacementSlot
          placementKey="category.top_sponsored_cards"
          context={{
            pageType: 'category',
            routePath: basePath,
            locale: currentParams.locale === 'ta' ? 'ta' : 'en',
            citySlug: selectedCitySlug,
            localitySlug: currentParams.locality,
            categorySlug: landing.category?.slug,
          }}
        />
        {isPending ? (
          <DirectoryCardSkeleton count={5} />
        ) : hits.length === 0 ? (
          <EmptyState
            title={`No ${title} listings in ${selectedCityName}`}
            description={
              hasActiveFilters
                ? 'No businesses match the selected filters. Clear filters to see all listings.'
                : `No verified ${title.toLowerCase()} listings are currently available in ${selectedCityName}.`
            }
            action={
              hasActiveFilters ? (
                <button
                  type="button"
                  onClick={handleResetFilters}
                  className="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-700"
                >
                  Clear all filters
                </button>
              ) : (
                <Link
                  href={`/search?city=${encodeURIComponent(selectedCitySlug)}`}
                  className="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-700"
                >
                  Browse all businesses in {selectedCityName}
                </Link>
              )
            }
          />
        ) : (
          <div className="space-y-4" role="feed" aria-label={`${title} listings`}>
            {hits.map((hit, idx) => (
              <DirectoryListingCard key={hit.public_id} hit={hit} priority={idx === 0} />
            ))}
          </div>
        )}

        {!isPending && hits.length > 0 && totalPages > 1 && (
          <div className="pt-2">
            <SearchPagination
              currentPage={currentPage}
              totalPages={totalPages}
              totalItems={totalResults}
              perPage={currentParams.per_page ?? 20}
              buildPageHref={(p) => buildUrl({ page: p })}
              onPageChange={(p) => handleFilterChange({ page: p })}
            />
          </div>
        )}
      </div>
    </div>
  );
}
