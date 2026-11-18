import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound, permanentRedirect } from 'next/navigation';
import { fetchPublicBusinessProfile } from '@/lib/api/public-directory';
import { getServerAuthenticatedUser } from '@/lib/bff/session-cookie';
import { BusinessProfileView } from '@/modules/customer/components/BusinessProfileView';
import {
  buildFaqPageJsonLd,
  buildLocalBusinessJsonLd,
  evaluateBusinessProfileSeo,
  isSafeInternalRedirectPath,
  serializeSafeJsonLd,
} from '@/modules/customer/lib/business-profile';
import {
  normalizeLocale,
  sanitizeSlug,
} from '@/modules/customer/lib/search-url';
import type { BreadcrumbItem } from '@/shared/ui/Breadcrumbs';
import { ErrorState } from '@/shared/ui/StateFeedback';

interface BusinessProfilePageProps {
  params: {
    city: string;
    slug: string;
  };
  searchParams?: {
    locale?: string | string[];
    claim?: string | string[];
  };
}

function getSingleParam(val?: string | string[]): string | undefined {
  return Array.isArray(val) ? val[0] : val;
}

export async function generateMetadata({
  params,
  searchParams,
}: BusinessProfilePageProps): Promise<Metadata> {
  const citySlug = sanitizeSlug(params.city);
  const businessSlug = sanitizeSlug(params.slug);

  if (!citySlug || !businessSlug) {
    return {
      title: 'Business Not Found',
      robots: { index: false, follow: false },
    };
  }

  const locale = normalizeLocale(getSingleParam(searchParams?.locale));
  const res = await fetchPublicBusinessProfile(citySlug, businessSlug, locale);

  if (res.status === 301 || res.status === 404) {
    return {
      title: 'Business Not Found',
      robots: { index: false, follow: false },
    };
  }

  if (!res.ok || !res.data) {
    return {
      title: 'Business Profile Temporarily Unavailable',
      robots: { index: false, follow: true },
    };
  }

  const profile = res.data;
  const cityName =
    profile.address.city?.name ??
    citySlug.charAt(0).toUpperCase() + citySlug.slice(1);
  const localityName = profile.address.locality?.name;

  const title = localityName
    ? `${profile.name} — ${localityName}, ${cityName}`
    : `${profile.name} in ${cityName}`;

  const fallbackParts = [
    profile.name,
    profile.primary_category?.name ? `(${profile.primary_category.name})` : null,
    localityName ? `in ${localityName}, ${cityName}` : `in ${cityName}`,
  ].filter(Boolean);

  const description =
    profile.description?.trim() ||
    profile.tagline?.trim() ||
    `${fallbackParts.join(' ')} — view address, opening hours, services, and verified details on NearGrid.`;

  const seo = evaluateBusinessProfileSeo(profile, citySlug);

  return {
    title,
    description: description.slice(0, 160),
    robots: seo.robots,
    alternates: {
      canonical: seo.canonicalUrl,
    },
    openGraph: {
      title,
      description: description.slice(0, 160),
      url: seo.canonicalUrl,
      type: 'website',
    },
  };
}

export default async function BusinessProfilePage({
  params,
  searchParams,
}: BusinessProfilePageProps) {
  const citySlug = sanitizeSlug(params.city);
  const businessSlug = sanitizeSlug(params.slug);

  if (!citySlug || !businessSlug) {
    notFound();
  }

  const locale = normalizeLocale(getSingleParam(searchParams?.locale));
  const autoOpenClaim = getSingleParam(searchParams?.claim) === '1';

  const [res, authUser] = await Promise.all([
    fetchPublicBusinessProfile(citySlug, businessSlug, locale),
    getServerAuthenticatedUser(),
  ]);

  // Confirmed slug redirect (301 MOVED_PERMANENTLY from Laravel url_redirects)
  if (res.status === 301 && isSafeInternalRedirectPath(res.redirectTargetPath)) {
    permanentRedirect(res.redirectTargetPath);
  }

  // Missing, unpublished, suspended, or deleted business
  if (res.status === 404) {
    notFound();
  }

  // Honest upstream API failure state (never fabricate storefront data)
  if (!res.ok || !res.data) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
        <ErrorState
          title="Unable to load business profile"
          message={
            res.error?.message ||
            'The NearGrid directory API is temporarily unavailable. Please try again shortly.'
          }
        />
        <div className="mt-6 flex flex-wrap justify-center gap-3">
          <Link
            href={`/${encodeURIComponent(citySlug)}/business/${encodeURIComponent(businessSlug)}`}
            className="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-700"
          >
            Retry Loading Profile
          </Link>
          <Link
            href={`/search?city=${encodeURIComponent(citySlug)}`}
            className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-xs font-semibold text-ink hover:bg-gray-50"
          >
            Browse Businesses in {citySlug.charAt(0).toUpperCase() + citySlug.slice(1)}
          </Link>
        </div>
      </div>
    );
  }

  const profile = res.data;
  const resolvedCitySlug = profile.address.city?.slug ?? citySlug;
  const cityName =
    profile.address.city?.name ??
    resolvedCitySlug.charAt(0).toUpperCase() + resolvedCitySlug.slice(1);

  const seo = evaluateBusinessProfileSeo(profile, resolvedCitySlug);

  // BreadcrumbList: Home -> City -> Category (when present) -> Business
  const breadcrumbs: BreadcrumbItem[] = [
    { label: 'Home', href: '/' },
    { label: cityName, href: `/search?city=${encodeURIComponent(resolvedCitySlug)}` },
  ];

  if (profile.primary_category) {
    breadcrumbs.push({
      label: profile.primary_category.name,
      href: `/${encodeURIComponent(resolvedCitySlug)}/${encodeURIComponent(profile.primary_category.slug)}`,
    });
  }

  breadcrumbs.push({
    label: profile.name,
    href: seo.canonicalPath,
  });

  const localBusinessJsonLd = buildLocalBusinessJsonLd(profile, seo.canonicalUrl);
  const faqPageJsonLd = buildFaqPageJsonLd(profile);

  return (
    <>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: serializeSafeJsonLd(localBusinessJsonLd) }}
      />
      {faqPageJsonLd && (
        <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: serializeSafeJsonLd(faqPageJsonLd) }}
        />
      )}
      <BusinessProfileView
        profile={profile}
        citySlug={resolvedCitySlug}
        canonicalPath={seo.canonicalPath}
        canonicalUrl={seo.canonicalUrl}
        breadcrumbs={breadcrumbs}
        isAuthenticated={Boolean(authUser)}
        autoOpenClaim={autoOpenClaim}
      />
    </>
  );
}
