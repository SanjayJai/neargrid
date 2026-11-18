import type { Metadata } from 'next';
import {
  fetchPublicCities,
  fetchPublicSearch,
  fetchPublicSearchFilters,
} from '@/lib/api/public-directory';
import { SearchResultsClient } from '@/modules/customer/components/SearchResultsClient';
import {
  buildCanonicalCategoryPath,
  buildCanonicalLocalityCategoryPath,
  parseRawSearchParams,
} from '@/modules/customer/lib/search-url';

interface SearchPageProps {
  searchParams: Record<string, string | string[] | undefined>;
}

export async function generateMetadata({
  searchParams,
}: SearchPageProps): Promise<Metadata> {
  const parsed = parseRawSearchParams(searchParams);
  const city = parsed.city ?? 'chennai';
  const siteUrl = (
    process.env.NEXT_PUBLIC_SITE_URL || 'http://localhost:3000'
  ).replace(/\/$/, '');

  const cityLabel = city.charAt(0).toUpperCase() + city.slice(1);
  const title = parsed.q
    ? `Search "${parsed.q}" in ${cityLabel}`
    : parsed.category
      ? `${parsed.category.replace(/-/g, ' ')} in ${cityLabel}`
      : `Search Local Businesses in ${cityLabel}`;

  // Per docs/12-seo-architecture.md: strip filter/tracking query params from canonical.
  // Point bare city+category(/locality) search views to their canonical directory URL; otherwise /search.
  let canonicalPath = '/search';
  if (!parsed.q && parsed.city && parsed.category && parsed.locality) {
    canonicalPath = buildCanonicalLocalityCategoryPath(
      parsed.city,
      parsed.locality,
      parsed.category,
      1
    );
  } else if (!parsed.q && parsed.city && parsed.category) {
    canonicalPath = buildCanonicalCategoryPath(parsed.city, parsed.category, 1);
  }

  return {
    title,
    description: `Find top-rated, verified local businesses, phone numbers, addresses, ratings and opening hours in ${city}.`,
    // Faceted/interactive search is strictly noindex, follow per docs/12-seo-architecture.md
    robots: {
      index: false,
      follow: true,
    },
    alternates: {
      canonical: `${siteUrl}${canonicalPath}`,
    },
  };
}

export default async function SearchPage({ searchParams }: SearchPageProps) {
  const parsedParams = parseRawSearchParams(searchParams);

  // If no city provided, default to 'chennai'
  if (!parsedParams.city) {
    parsedParams.city = 'chennai';
  }

  const [searchRes, filtersRes, citiesRes] = await Promise.all([
    fetchPublicSearch(parsedParams),
    fetchPublicSearchFilters({
      city: parsedParams.city,
      category: parsedParams.category,
      locale: parsedParams.locale,
    }),
    fetchPublicCities(parsedParams.locale),
  ]);

  const hits = searchRes.ok && searchRes.data ? searchRes.data : [];
  const meta = searchRes.ok && searchRes.meta ? searchRes.meta : null;
  const filtersData = filtersRes.ok && filtersRes.data ? filtersRes.data : null;
  const cities = citiesRes.ok && citiesRes.data ? citiesRes.data : [];

  const matchedCity = cities.find((c) => c.slug === parsedParams.city);
  const cityDisplayName = matchedCity?.name ?? parsedParams.city;

  return (
    <SearchResultsClient
      initialHits={hits}
      initialMeta={meta}
      initialFiltersData={filtersData}
      initialParams={parsedParams}
      cityDisplayName={cityDisplayName}
    />
  );
}
