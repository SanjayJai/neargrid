import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import {
  fetchPublicCategories,
  fetchPublicCities,
  fetchPublicSearch,
  fetchPublicSearchFilters,
} from '@/lib/api/public-directory';
import { CanonicalDirectoryView } from '@/modules/customer/components/CanonicalDirectoryView';
import {
  buildOrganicItemListJsonLd,
  evaluateCanonicalDirectorySeo,
  resolveCategoryDisplayName,
} from '@/modules/customer/lib/directory-seo';
import { parseRawSearchParams, sanitizeSlug } from '@/modules/customer/lib/search-url';

/**
 * Canonical 2-segment City + Category route: `/{city}/{category}` (e.g. `/chennai/dentists`).
 *
 * Next.js App Router requires dynamic segments at the same directory depth to share one folder name:
 * - 2-segment `/{city}/{segment}`           -> `segment` is the `category` slug (this file)
 * - 3-segment `/{city}/{segment}/{category}` -> `segment` is the `locality` slug, `category` is the `category` slug
 * - Static `/{city}/business/{slug}`        -> takes priority over `[segment]` for business profiles
 */
interface CityCategoryPageProps {
  params: {
    city: string;
    segment: string; // Category slug for 2-segment /{city}/{category} URLs
  };
  searchParams: Record<string, string | string[] | undefined>;
}

export async function generateMetadata({
  params,
  searchParams,
}: CityCategoryPageProps): Promise<Metadata> {
  const citySlug = sanitizeSlug(params.city);
  const categorySlug = sanitizeSlug(params.segment);

  if (!citySlug || !categorySlug || categorySlug === 'business') {
    return {
      robots: { index: false, follow: false },
    };
  }

  const parsed = parseRawSearchParams(searchParams);
  const queryParams = {
    ...parsed,
    city: citySlug,
    category: categorySlug,
  };

  const [searchRes, citiesRes, categoriesRes] = await Promise.all([
    fetchPublicSearch(queryParams),
    fetchPublicCities(parsed.locale),
    fetchPublicCategories(parsed.locale),
  ]);

  const cityName =
    citiesRes.data?.find((c) => c.slug === citySlug)?.name ??
    citySlug.charAt(0).toUpperCase() + citySlug.slice(1);
  const categoryName = resolveCategoryDisplayName(categoriesRes.data, categorySlug);

  const totalOrganic = searchRes.meta?.counts?.total_organic ?? searchRes.data?.length ?? 0;

  const seo = evaluateCanonicalDirectorySeo({
    pageKind: 'city_category',
    citySlug,
    categorySlug,
    params: parsed,
    apiOk: searchRes.ok,
    totalOrganic,
    backendSeo: searchRes.meta?.seo ?? null,
  });

  const title = `Best ${categoryName} in ${cityName}`;
  const description = `Find verified, top-rated ${categoryName.toLowerCase()} in ${cityName}. Browse ratings, reviews, masked contact details, addresses, and opening hours on NearGrid.`;

  return {
    title,
    description,
    robots: seo.robots,
    alternates: {
      canonical: seo.canonicalUrl,
    },
    openGraph: {
      title,
      description,
      url: seo.canonicalUrl,
      type: 'website',
    },
  };
}

export default async function CityCategoryPage({
  params,
  searchParams,
}: CityCategoryPageProps) {
  const citySlug = sanitizeSlug(params.city);
  const categorySlug = sanitizeSlug(params.segment);

  if (!citySlug || !categorySlug || categorySlug === 'business') {
    notFound();
  }

  const parsedParams = parseRawSearchParams(searchParams);
  parsedParams.city = citySlug;
  parsedParams.category = categorySlug;

  const [searchRes, filtersRes, citiesRes, categoriesRes] = await Promise.all([
    fetchPublicSearch(parsedParams),
    fetchPublicSearchFilters({
      city: citySlug,
      category: categorySlug,
      locale: parsedParams.locale,
    }),
    fetchPublicCities(parsedParams.locale),
    fetchPublicCategories(parsedParams.locale),
  ]);

  const hits = searchRes.ok && searchRes.data ? searchRes.data : [];
  const meta = searchRes.ok && searchRes.meta ? searchRes.meta : null;
  const filtersData = filtersRes.ok && filtersRes.data ? filtersRes.data : null;
  const cities = citiesRes.ok && citiesRes.data ? citiesRes.data : [];
  const categories = categoriesRes.ok && categoriesRes.data ? categoriesRes.data : [];

  const matchedCity = cities.find((c) => c.slug === citySlug);
  const cityName =
    matchedCity?.name ?? citySlug.charAt(0).toUpperCase() + citySlug.slice(1);

  const categoryName = resolveCategoryDisplayName(categories, categorySlug);

  const baseCanonicalPath = `/${encodeURIComponent(citySlug)}/${encodeURIComponent(categorySlug)}`;
  const currentPage = meta?.pagination?.current_page ?? parsedParams.page ?? 1;

  const breadcrumbs = [
    { label: 'Home', href: '/' },
    { label: cityName, href: `/search?city=${encodeURIComponent(citySlug)}` },
    { label: categoryName, href: baseCanonicalPath },
  ];

  // Schema.org ItemList JSON-LD for Page 1 organic listings only (docs/12-seo-architecture.md Section 4.2)
  const itemListJsonLd = buildOrganicItemListJsonLd(hits, citySlug, currentPage);

  return (
    <>
      {itemListJsonLd && (
        <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: JSON.stringify(itemListJsonLd) }}
        />
      )}
      <CanonicalDirectoryView
        citySlug={citySlug}
        cityName={cityName}
        categorySlug={categorySlug}
        categoryName={categoryName}
        hits={hits}
        meta={meta}
        filtersData={filtersData}
        currentParams={parsedParams}
        baseCanonicalPath={baseCanonicalPath}
        breadcrumbs={breadcrumbs}
      />
    </>
  );
}
