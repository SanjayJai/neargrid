import type { Metadata } from 'next';
import { notFound, permanentRedirect } from 'next/navigation';
import {
  fetchPublicCategories,
  fetchPublicCities,
  fetchPublicLocalities,
  fetchPublicSearch,
  fetchPublicSearchFilters,
} from '@/lib/api/public-directory';
import { CanonicalDirectoryView } from '@/modules/customer/components/CanonicalDirectoryView';
import { isSafeInternalRedirectPath } from '@/modules/customer/lib/business-profile';
import {
  buildOrganicItemListJsonLd,
  evaluateCanonicalDirectorySeo,
  resolveCategoryDisplayName,
} from '@/modules/customer/lib/directory-seo';
import { parseRawSearchParams, sanitizeSlug } from '@/modules/customer/lib/search-url';

/**
 * Canonical 3-segment City + Locality + Category route:
 * `/{city}/{locality}/{category}` (e.g. `/chennai/t-nagar/dentists`).
 *
 * Route segment mapping:
 * - `params.city`     -> `citySlug`
 * - `params.segment`  -> `localitySlug` (shared dynamic folder with 2-segment `/{city}/{segment}`)
 * - `params.category` -> `categorySlug`
 *
 * Static `/{city}/business/{slug}` takes route precedence over `[segment]` in Next.js App Router.
 */
interface CityLocalityCategoryPageProps {
  params: {
    city: string;
    segment: string; // Locality slug in /{city}/{locality}/{category}
    category: string; // Category slug in /{city}/{locality}/{category}
  };
  searchParams: Record<string, string | string[] | undefined>;
}

export async function generateMetadata({
  params,
  searchParams,
}: CityLocalityCategoryPageProps): Promise<Metadata> {
  const citySlug = sanitizeSlug(params.city);
  const localitySlug = sanitizeSlug(params.segment);
  const categorySlug = sanitizeSlug(params.category);

  if (!citySlug || !localitySlug || !categorySlug || localitySlug === 'business') {
    return {
      robots: { index: false, follow: false },
    };
  }

  const parsed = parseRawSearchParams(searchParams);
  const queryParams = {
    ...parsed,
    city: citySlug,
    locality: localitySlug,
    category: categorySlug,
  };

  const [searchRes, citiesRes, categoriesRes, localitiesRes] = await Promise.all([
    fetchPublicSearch(queryParams),
    fetchPublicCities(parsed.locale),
    fetchPublicCategories(parsed.locale),
    fetchPublicLocalities(citySlug, parsed.locale),
  ]);

  if (searchRes.status === 301) {
    return {
      robots: { index: false, follow: false },
    };
  }

  const cityName =
    citiesRes.data?.find((c) => c.slug === citySlug)?.name ??
    citySlug.charAt(0).toUpperCase() + citySlug.slice(1);
  const localityName =
    localitiesRes.data?.find((l) => l.slug === localitySlug)?.name ??
    localitySlug.replace(/-/g, ' ');
  const categoryName = resolveCategoryDisplayName(categoriesRes.data, categorySlug);

  const totalOrganic = searchRes.meta?.counts?.total_organic ?? searchRes.data?.length ?? 0;

  // Enforces <3 thin-page noindex, filter/sort noindex, and canonical normalization per docs/12-seo-architecture.md
  const seo = evaluateCanonicalDirectorySeo({
    pageKind: 'city_locality_category',
    citySlug,
    localitySlug,
    categorySlug,
    params: parsed,
    apiOk: searchRes.ok,
    totalOrganic,
    backendSeo: searchRes.meta?.seo ?? null,
  });

  const title = `Top ${categoryName} in ${localityName}, ${cityName}`;
  const description = `Find top-rated, verified ${categoryName.toLowerCase()} in ${localityName}, ${cityName}. Compare ratings, read reviews, check addresses, masked contact info, and opening hours on NearGrid.`;

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

export default async function CityLocalityCategoryPage({
  params,
  searchParams,
}: CityLocalityCategoryPageProps) {
  const citySlug = sanitizeSlug(params.city);
  const localitySlug = sanitizeSlug(params.segment);
  const categorySlug = sanitizeSlug(params.category);

  if (!citySlug || !localitySlug || !categorySlug || localitySlug === 'business') {
    notFound();
  }

  const parsedParams = parseRawSearchParams(searchParams);
  parsedParams.city = citySlug;
  parsedParams.locality = localitySlug;
  parsedParams.category = categorySlug;

  const [searchRes, filtersRes, citiesRes, categoriesRes, localitiesRes] = await Promise.all([
    fetchPublicSearch(parsedParams),
    fetchPublicSearchFilters({
      city: citySlug,
      category: categorySlug,
      locale: parsedParams.locale,
    }),
    fetchPublicCities(parsedParams.locale),
    fetchPublicCategories(parsedParams.locale),
    fetchPublicLocalities(citySlug, parsedParams.locale),
  ]);

  if (searchRes.status === 301 && isSafeInternalRedirectPath(searchRes.redirectTargetPath)) {
    permanentRedirect(searchRes.redirectTargetPath);
  }

  const hits = searchRes.ok && searchRes.data ? searchRes.data : [];
  const meta = searchRes.ok && searchRes.meta ? searchRes.meta : null;
  const filtersData = filtersRes.ok && filtersRes.data ? filtersRes.data : null;
  const cities = citiesRes.ok && citiesRes.data ? citiesRes.data : [];
  const categories = categoriesRes.ok && categoriesRes.data ? categoriesRes.data : [];
  const localities = localitiesRes.ok && localitiesRes.data ? localitiesRes.data : [];

  const matchedCity = cities.find((c) => c.slug === citySlug);
  const cityName =
    matchedCity?.name ?? citySlug.charAt(0).toUpperCase() + citySlug.slice(1);

  const matchedLocality = localities.find((l) => l.slug === localitySlug);
  const localityName = matchedLocality?.name ?? localitySlug.replace(/-/g, ' ');

  const categoryName = resolveCategoryDisplayName(categories, categorySlug);

  const baseCanonicalPath = `/${encodeURIComponent(citySlug)}/${encodeURIComponent(localitySlug)}/${encodeURIComponent(categorySlug)}`;
  const currentPage = meta?.pagination?.current_page ?? parsedParams.page ?? 1;

  // Breadcrumb hierarchy per docs/12-seo-architecture.md: Home -> City -> Locality -> Category
  const breadcrumbs = [
    { label: 'Home', href: '/' },
    { label: cityName, href: `/search?city=${encodeURIComponent(citySlug)}` },
    {
      label: localityName,
      href: `/search?city=${encodeURIComponent(citySlug)}&locality=${encodeURIComponent(localitySlug)}`,
    },
    { label: categoryName, href: baseCanonicalPath },
  ];

  // Schema.org ItemList JSON-LD for Page 1 organic listings only
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
        localitySlug={localitySlug}
        localityName={localityName}
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
