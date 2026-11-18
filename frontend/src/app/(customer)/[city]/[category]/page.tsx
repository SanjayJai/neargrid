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
  buildCanonicalCategoryPath,
  parseRawSearchParams,
} from '@/modules/customer/lib/search-url';

interface CategoryPageProps {
  params: {
    city: string;
    category: string;
  };
  searchParams: Record<string, string | string[] | undefined>;
}

export async function generateMetadata({
  params,
  searchParams,
}: CategoryPageProps): Promise<Metadata> {
  // Prevent collision with /business/
  if (params.category === 'business') {
    return {};
  }

  const parsed = parseRawSearchParams(searchParams);
  parsed.city = params.city;
  parsed.category = params.category;

  const siteUrl = (
    process.env.NEXT_PUBLIC_SITE_URL || 'http://localhost:3000'
  ).replace(/\/$/, '');

  const [searchRes, citiesRes, categoriesRes] = await Promise.all([
    fetchPublicSearch(parsed),
    fetchPublicCities(parsed.locale),
    fetchPublicCategories(parsed.locale),
  ]);

  const cityName =
    citiesRes.data?.find((c) => c.slug === params.city)?.name ??
    params.city.charAt(0).toUpperCase() + params.city.slice(1);
  const categoryName =
    categoriesRes.data?.find((c) => c.slug === params.category)?.name ??
    params.category.replace(/-/g, ' ');

  const title = `Best ${categoryName} in ${cityName} | NearGrid`;
  const description = `Find verified, top-rated ${categoryName.toLowerCase()} in ${cityName}. Browse ratings, reviews, phone numbers, addresses, and opening hours on NearGrid.`;

  const metaSeo = searchRes.meta?.seo;
  const canonicalUrl =
    metaSeo?.canonical_url ??
    `${siteUrl}${buildCanonicalCategoryPath(params.city, params.category, parsed.page ?? 1)}`;

  const robots = metaSeo?.robots ?? 'index, follow';

  return {
    title,
    description,
    robots: {
      index: robots.startsWith('index'),
      follow: robots.includes('follow'),
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

export default async function CategoryPage({
  params,
  searchParams,
}: CategoryPageProps) {
  if (params.category === 'business') {
    notFound();
  }

  const parsedParams = parseRawSearchParams(searchParams);
  parsedParams.city = params.city;
  parsedParams.category = params.category;

  const [searchRes, filtersRes, citiesRes, categoriesRes] = await Promise.all([
    fetchPublicSearch(parsedParams),
    fetchPublicSearchFilters({
      city: params.city,
      category: params.category,
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

  const matchedCity = cities.find((c) => c.slug === params.city);
  const cityName = matchedCity?.name ?? params.city.charAt(0).toUpperCase() + params.city.slice(1);

  const matchedCategory = categories.find((c) => c.slug === params.category);
  const categoryName =
    matchedCategory?.name ?? params.category.replace(/-/g, ' ');

  const siteUrl = (
    process.env.NEXT_PUBLIC_SITE_URL || 'http://localhost:3000'
  ).replace(/\/$/, '');
  const baseCanonicalPath = `/${encodeURIComponent(params.city)}/${encodeURIComponent(params.category)}`;

  const breadcrumbs = [
    { label: 'Home', href: '/' },
    { label: cityName, href: `/search?city=${encodeURIComponent(params.city)}` },
    { label: categoryName },
  ];

  // Schema.org ItemList JSON-LD for top organic listings on Page 1 (docs/12-seo-architecture.md Section 4.2)
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
            url: `${siteUrl}/${encodeURIComponent(h.city_slug ?? params.city)}/business/${encodeURIComponent(h.slug)}`,
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
        citySlug={params.city}
        cityName={cityName}
        categorySlug={params.category}
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
