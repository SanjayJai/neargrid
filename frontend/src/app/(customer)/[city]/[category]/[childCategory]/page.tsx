import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import {
  fetchPublicCategories,
  fetchPublicCities,
  fetchPublicLocalities,
  fetchPublicSearch,
  fetchPublicSearchFilters,
} from '@/lib/api/public-directory';
import { CanonicalDirectoryView } from '@/modules/customer/components/CanonicalDirectoryView';
import {
  buildCanonicalLocalityCategoryPath,
  parseRawSearchParams,
} from '@/modules/customer/lib/search-url';

interface LocalityCategoryPageProps {
  params: {
    city: string;
    category: string; // Locality slug in the 3-segment URL /{city}/{locality}/{category}
    childCategory: string; // Category slug in the 3-segment URL
  };
  searchParams: Record<string, string | string[] | undefined>;
}

export async function generateMetadata({
  params,
  searchParams,
}: LocalityCategoryPageProps): Promise<Metadata> {
  const citySlug = params.city;
  const localitySlug = params.category;
  const categorySlug = params.childCategory;

  if (localitySlug === 'business') {
    return {};
  }

  const parsed = parseRawSearchParams(searchParams);
  parsed.city = citySlug;
  parsed.locality = localitySlug;
  parsed.category = categorySlug;

  const siteUrl = (
    process.env.NEXT_PUBLIC_SITE_URL || 'http://localhost:3000'
  ).replace(/\/$/, '');

  const [searchRes, citiesRes, categoriesRes, localitiesRes] = await Promise.all([
    fetchPublicSearch(parsed),
    fetchPublicCities(parsed.locale),
    fetchPublicCategories(parsed.locale),
    fetchPublicLocalities(citySlug, parsed.locale),
  ]);

  const cityName =
    citiesRes.data?.find((c) => c.slug === citySlug)?.name ??
    citySlug.charAt(0).toUpperCase() + citySlug.slice(1);
  const localityName =
    localitiesRes.data?.find((l) => l.slug === localitySlug)?.name ??
    localitySlug.replace(/-/g, ' ');
  const categoryName =
    categoriesRes.data?.find((c) => c.slug === categorySlug)?.name ??
    categorySlug.replace(/-/g, ' ');

  const title = `Top ${categoryName} in ${localityName}, ${cityName} | NearGrid`;
  const description = `Find top-rated, verified ${categoryName.toLowerCase()} in ${localityName}, ${cityName}. Compare ratings, read reviews, check addresses, phone numbers, and hours on NearGrid.`;

  const metaSeo = searchRes.meta?.seo;
  const totalOrganic = searchRes.meta?.counts?.total_organic ?? searchRes.data?.length ?? 0;

  // Thin-page policy: <3 listings in locality-category page must be noindex per docs/12-seo-architecture.md
  const isThinPage = totalOrganic < 3;
  const backendRobots = metaSeo?.robots ?? (isThinPage ? 'noindex, follow' : 'index, follow');
  const shouldIndex = !isThinPage && backendRobots.startsWith('index');

  const canonicalUrl =
    metaSeo?.canonical_url ??
    `${siteUrl}${buildCanonicalLocalityCategoryPath(citySlug, localitySlug, categorySlug, parsed.page ?? 1)}`;

  return {
    title,
    description,
    robots: {
      index: shouldIndex,
      follow: true,
    },
    alternates: {
      canonical: canonicalUrl,
    },
    openGraph: {
      title,
      description,
      url: canonicalUrl,
      type: 'website',
    },
  };
}

export default async function LocalityCategoryPage({
  params,
  searchParams,
}: LocalityCategoryPageProps) {
  const citySlug = params.city;
  const localitySlug = params.category;
  const categorySlug = params.childCategory;

  if (localitySlug === 'business') {
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

  const hits = searchRes.ok && searchRes.data ? searchRes.data : [];
  const meta = searchRes.ok && searchRes.meta ? searchRes.meta : null;
  const filtersData = filtersRes.ok && filtersRes.data ? filtersRes.data : null;
  const cities = citiesRes.ok && citiesRes.data ? citiesRes.data : [];
  const categories = categoriesRes.ok && categoriesRes.data ? categoriesRes.data : [];
  const localities = localitiesRes.ok && localitiesRes.data ? localitiesRes.data : [];

  const matchedCity = cities.find((c) => c.slug === citySlug);
  const cityName = matchedCity?.name ?? citySlug.charAt(0).toUpperCase() + citySlug.slice(1);

  const matchedLocality = localities.find((l) => l.slug === localitySlug);
  const localityName = matchedLocality?.name ?? localitySlug.replace(/-/g, ' ');

  const matchedCategory = categories.find((c) => c.slug === categorySlug);
  const categoryName = matchedCategory?.name ?? categorySlug.replace(/-/g, ' ');

  const siteUrl = (
    process.env.NEXT_PUBLIC_SITE_URL || 'http://localhost:3000'
  ).replace(/\/$/, '');
  const baseCanonicalPath = `/${encodeURIComponent(citySlug)}/${encodeURIComponent(localitySlug)}/${encodeURIComponent(categorySlug)}`;

  const breadcrumbs = [
    { label: 'Home', href: '/' },
    { label: cityName, href: `/search?city=${encodeURIComponent(citySlug)}` },
    {
      label: categoryName,
      href: `/${encodeURIComponent(citySlug)}/${encodeURIComponent(categorySlug)}`,
    },
    { label: localityName },
  ];

  // Schema.org ItemList JSON-LD for top organic listings on Page 1
  const organicHits = hits.filter((h) => !h.is_sponsored).slice(0, 10);
  const itemListJsonLd =
    organicHits.length > 0
      ? {
          '@context': 'https://schema.org',
          '@type': 'ItemList',
          itemListElement: organicHits.map((h, i) => ({
            '@type': 'ListItem',
            position: i + 1,
            name: h.name,
            url: `${siteUrl}/${encodeURIComponent(h.city_slug ?? citySlug)}/business/${encodeURIComponent(h.slug)}`,
          })),
        }
      : null;

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
