import type { Metadata } from 'next';
import { notFound, permanentRedirect } from 'next/navigation';
import {
  fetchPublicCategoryLanding,
  fetchPublicCities,
  fetchPublicSearch,
  fetchPublicSearchFilters,
} from '@/lib/api/public-directory';
import { CategoryLandingView } from '@/modules/customer/components/CategoryLandingView';
import {
  normalizeLocale,
  parseRawSearchParams,
  sanitizeSlug,
} from '@/modules/customer/lib/search-url';

interface CategoryPageProps {
  params: { slug: string[] };
  searchParams: Record<string, string | string[] | undefined>;
}

export async function generateMetadata({
  params,
  searchParams,
}: CategoryPageProps): Promise<Metadata> {
  const slugPath = (params.slug ?? []).join('/');
  const locale = normalizeLocale(
    Array.isArray(searchParams.locale) ? searchParams.locale[0] : searchParams.locale
  );

  const res = await fetchPublicCategoryLanding(slugPath, locale);
  if (!res.ok || !res.data || res.data.redirect || !res.data.seo) {
    return {
      title: 'Category | NearGrid',
      robots: { index: false, follow: true },
    };
  }

  const seo = res.data.seo;
  const hasFilters = Boolean(
    searchParams.q ||
      searchParams.locality ||
      searchParams.min_rating ||
      searchParams.verified_only ||
      searchParams.open_now ||
      searchParams.lat
  );

  return {
    title: seo.title,
    description: seo.description,
    robots: hasFilters
      ? { index: false, follow: true }
      : {
          index: seo.indexable,
          follow: true,
        },
    alternates: {
      canonical: seo.canonical_url,
      languages: seo.alternates,
    },
    openGraph: {
      title: seo.open_graph.title,
      description: seo.open_graph.description,
      url: seo.open_graph.url,
      ...(seo.open_graph.image_url ? { images: [seo.open_graph.image_url] } : {}),
    },
  };
}

export default async function DynamicCategoryPage({
  params,
  searchParams,
}: CategoryPageProps) {
  const slugSegments = (params.slug ?? []).filter(Boolean);
  if (slugSegments.length === 0) {
    notFound();
  }

  const slugPath = slugSegments.join('/');
  const parsed = parseRawSearchParams(searchParams);
  const locale = parsed.locale ?? 'en';

  const [landingRes, citiesRes] = await Promise.all([
    fetchPublicCategoryLanding(slugPath, locale),
    fetchPublicCities(locale),
  ]);

  if (!landingRes.ok || !landingRes.data) {
    notFound();
  }

  if (landingRes.data.redirect && landingRes.data.target_path) {
    permanentRedirect(landingRes.data.target_path);
  }

  if (landingRes.data.kind === 'category' && landingRes.data.category && !landingRes.data.category.has_landing_page) {
    notFound();
  }

  const cities = citiesRes.ok && citiesRes.data ? citiesRes.data : [];
  const requestedCity = sanitizeSlug(parsed.city);
  const activeCity =
    (requestedCity ? cities.find((c) => c.slug === requestedCity) : null) ?? cities[0] ?? null;
  const selectedCitySlug = activeCity?.slug ?? requestedCity ?? 'chennai';
  const selectedCityName = activeCity?.name ?? selectedCitySlug;

  const categorySlug = landingRes.data.category?.slug;
  const searchParamsForApi = {
    ...parsed,
    city: selectedCitySlug,
    category: categorySlug ?? parsed.category,
  };

  const [searchRes, filtersRes] = await Promise.all([
    fetchPublicSearch(searchParamsForApi),
    fetchPublicSearchFilters({
      city: selectedCitySlug,
      category: categorySlug,
      locale,
    }),
  ]);

  const basePath = `/c/${slugPath}`;
  const cityQuery = requestedCity ? `?city=${encodeURIComponent(selectedCitySlug)}` : '';
  const breadcrumbs = [
    { label: 'Home', href: requestedCity ? `/${cityQuery}` : '/' },
    ...(landingRes.data.breadcrumbs ?? []).map((b, idx, arr) => {
      if (idx >= arr.length - 1) {
        return { label: b.name, href: undefined };
      }
      const pathname = new URL(b.url, 'https://neargrid.in').pathname;
      return {
        label: b.name,
        href: `${pathname}${cityQuery}`,
      };
    }),
  ];

  if (landingRes.data.kind === 'collection' && landingRes.data.collection) {
    breadcrumbs.push({ label: landingRes.data.collection.name, href: undefined });
  }

  return (
    <>
      {landingRes.data.seo?.json_ld && (
        <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{
            __html: JSON.stringify(landingRes.data.seo.json_ld),
          }}
        />
      )}
      <CategoryLandingView
        landing={landingRes.data}
        cities={cities}
        selectedCitySlug={selectedCitySlug}
        selectedCityName={selectedCityName}
        hits={searchRes.ok && searchRes.data ? searchRes.data : []}
        meta={searchRes.ok && searchRes.meta ? searchRes.meta : null}
        filtersData={filtersRes.ok && filtersRes.data ? filtersRes.data : null}
        currentParams={searchParamsForApi}
        basePath={basePath}
        breadcrumbs={breadcrumbs}
      />
    </>
  );
}
