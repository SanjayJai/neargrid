import Link from 'next/link';
import {
  ArrowRight,
  Building2,
  CheckCircle2,
  ChevronRight,
  MapPin,
  ShieldCheck,
  Sparkles,
} from 'lucide-react';
import {
  fetchPublicCategories,
  fetchPublicCities,
  fetchPublicLocalities,
  fetchPublicSearch,
} from '@/lib/api/public-directory';
import { HomeDirectoryCard } from '@/modules/customer/components/HomeDirectoryCard';
import { LiveSearchBar } from '@/modules/customer/components/LiveSearchBar';
import { getCategoryVisual } from '@/modules/customer/lib/category-icons';
import {
  buildShareableSearchUrl,
  normalizeLocale,
  sanitizeSlug,
  type SupportedLocale,
} from '@/modules/customer/lib/search-url';
import { EmptyState, ErrorState } from '@/shared/ui/StateFeedback';

interface HomePageProps {
  searchParams: {
    city?: string;
    locale?: string;
  };
}

const QUICK_SEARCH_TERMS: Record<SupportedLocale, Array<{ label: string; q: string }>> = {
  en: [
    { label: 'Plumbers', q: 'plumber' },
    { label: 'Electricians', q: 'electrician' },
    { label: 'Dentists', q: 'dentist' },
    { label: 'Hospitals', q: 'hospital' },
    { label: 'Restaurants', q: 'restaurant' },
    { label: 'AC Repair', q: 'ac repair' },
  ],
  ta: [
    { label: 'பிளம்பர்', q: 'பிளம்பர்' },
    { label: 'எலக்ட்ரீஷியன்', q: 'எலக்ட்ரீஷியன்' },
    { label: 'பல் மருத்துவர்', q: 'பல் மருத்துவர்' },
    { label: 'மருத்துவமனை', q: 'மருத்துவமனை' },
    { label: 'உணவகம்', q: 'உணவகம்' },
    { label: 'ஏசி சர்வீஸ்', q: 'ஏசி சர்வீஸ்' },
  ],
};

export default async function HomePage({ searchParams }: HomePageProps) {
  const locale = normalizeLocale(searchParams.locale);
  const requestedCity = sanitizeSlug(searchParams.city);

  const [citiesRes, categoriesRes] = await Promise.all([
    fetchPublicCities(locale),
    fetchPublicCategories(locale),
  ]);

  const cities = citiesRes.ok && citiesRes.data ? citiesRes.data : [];
  const categories = categoriesRes.ok && categoriesRes.data ? categoriesRes.data : [];

  const activeCity =
    (requestedCity ? cities.find((c) => c.slug === requestedCity) : null) ?? cities[0] ?? null;
  const activeCitySlug = activeCity?.slug ?? requestedCity ?? 'chennai';
  const activeCityLabel = activeCity?.name ?? (locale === 'ta' ? 'சென்னை' : 'Chennai');

  const [localitiesRes, searchRes] = await Promise.all([
    fetchPublicLocalities(activeCitySlug, locale),
    fetchPublicSearch({
      city: activeCitySlug,
      per_page: 6,
      sort: 'relevance',
      locale,
    }),
  ]);

  const localities = localitiesRes.ok && localitiesRes.data ? localitiesRes.data : [];
  const liveBusinesses = searchRes.ok && searchRes.data ? searchRes.data : [];

  // Flatten top featured subcategories (with root fallback) for the dense iconic grid
  const gridCategories = categories
    .flatMap((root) => (root.children.length > 0 ? root.children : [root]))
    .slice(0, 16);

  const quickChips = QUICK_SEARCH_TERMS[locale];

  return (
    <div className="pb-12">
      {/* 1. Prominent Search-First Directory Banner */}
      <section className="border-b border-gray-200 bg-gradient-to-b from-brand-50/90 via-white to-white">
        <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 sm:py-10 lg:px-8">
          <div className="mx-auto max-w-4xl">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
              <h1 className="text-2xl font-extrabold tracking-tight text-ink sm:text-3xl">
                {locale === 'ta' ? (
                  <>
                    <span className="text-brand-700">{activeCityLabel}</span> பகுதியில் நம்பகமான
                    வணிகங்களைத் தேடுங்கள்
                  </>
                ) : (
                  <>
                    Search local businesses in{' '}
                    <span className="text-brand-700">{activeCityLabel}</span>
                  </>
                )}
              </h1>
              <span className="inline-flex items-center gap-1 rounded-full border border-brand-200 bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700">
                <ShieldCheck className="h-3.5 w-3.5" aria-hidden="true" />
                {locale === 'ta' ? 'ஆங்கிலம் & தமிழ் தேடல்' : 'English · தமிழ் · Tanglish'}
              </span>
            </div>

            {/* Centerpiece Two-Field Live Search Bar */}
            <LiveSearchBar
              cities={cities}
              initialCitySlug={activeCitySlug}
              initialLocalities={localities}
              locale={locale}
              variant="hero"
            />

            {/* Quick Popular Searches */}
            <div className="mt-3.5 flex flex-wrap items-center gap-2 text-xs">
              <span className="font-semibold text-ink-subtle">
                {locale === 'ta' ? 'பிரபல தேடல்கள்:' : 'Popular:'}
              </span>
              {quickChips.map((chip) => (
                <Link
                  key={chip.q}
                  href={buildShareableSearchUrl({ city: activeCitySlug, q: chip.q, locale })}
                  className="rounded-full border border-gray-200 bg-white px-3 py-1 font-medium text-ink-muted transition-colors hover:border-brand-400 hover:bg-brand-50 hover:text-brand-700"
                >
                  {chip.label}
                </Link>
              ))}
            </div>
          </div>
        </div>
      </section>

      {/* 2. Dense Responsive Category Icon Grid (Live GET /api/v1/categories) */}
      <section
        aria-labelledby="categories-grid-heading"
        className="mx-auto max-w-7xl px-4 pt-8 sm:px-6 lg:px-8"
      >
        <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-6">
          <div className="mb-4 flex items-center justify-between gap-3">
            <div>
              <h2
                id="categories-grid-heading"
                className="text-lg font-bold tracking-tight text-ink sm:text-xl"
              >
                {locale === 'ta' ? 'பிரபல சேவைப் பிரிவுகள்' : 'Popular Categories'}
              </h2>
              <p className="text-xs text-ink-subtle">
                {locale === 'ta'
                  ? `${activeCityLabel} பகுதியில் உள்ள அனைத்து முக்கிய சேவைகள்`
                  : `Quick access to essential services in ${activeCityLabel}`}
              </p>
            </div>
            <Link
              href={buildShareableSearchUrl({ city: activeCitySlug, locale })}
              className="inline-flex items-center gap-1 text-xs font-bold text-brand-700 hover:underline sm:text-sm"
            >
              {locale === 'ta' ? 'அனைத்தும் பார்க்க' : 'Explore All'}
              <ArrowRight className="h-4 w-4" aria-hidden="true" />
            </Link>
          </div>

          {!categoriesRes.ok ? (
            <ErrorState
              title="Categories temporarily unavailable"
              message={categoriesRes.error?.message ?? 'Unable to load directory categories.'}
            />
          ) : gridCategories.length === 0 ? (
            <EmptyState
              title="No active categories available"
              description="Categories will appear here once reference taxonomy data is loaded."
            />
          ) : (
            <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-8">
              {gridCategories.map((cat) => {
                const visual = getCategoryVisual(cat.icon_name);
                const Icon = visual.icon;
                return (
                  <Link
                    key={cat.public_id}
                    href={buildShareableSearchUrl({
                      city: activeCitySlug,
                      category: cat.slug,
                      locale,
                    })}
                    className="group flex flex-col items-center rounded-xl border border-gray-200/80 bg-white p-3 text-center transition-all hover:border-brand-400 hover:bg-brand-50/40 hover:shadow-sm"
                  >
                    <span
                      className={`flex h-12 w-12 items-center justify-center rounded-xl transition-transform group-hover:scale-105 ${visual.bgClass} ${visual.textClass}`}
                    >
                      <Icon className="h-6 w-6" aria-hidden="true" />
                    </span>
                    <span className="mt-2 line-clamp-2 text-xs font-semibold leading-snug text-ink group-hover:text-brand-700">
                      {cat.name}
                    </span>
                  </Link>
                );
              })}
            </div>
          )}
        </div>
      </section>

      {/* 3. Justdial-Style Curated Service Verticals (Live Category Hierarchy) */}
      {categories.length > 0 && (
        <section
          aria-labelledby="verticals-heading"
          className="mx-auto max-w-7xl px-4 pt-8 sm:px-6 lg:px-8"
        >
          <div className="mb-4 flex items-center justify-between">
            <h2 id="verticals-heading" className="text-lg font-bold tracking-tight text-ink sm:text-xl">
              {locale === 'ta'
                ? `${activeCityLabel} சேவைத் தொகுப்புகள்`
                : `Browse Services in ${activeCityLabel}`}
            </h2>
          </div>

          <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            {categories.slice(0, 6).map((root) => {
              const visual = getCategoryVisual(root.icon_name);
              const RootIcon = visual.icon;
              return (
                <div
                  key={root.public_id}
                  className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"
                >
                  <div className="mb-3 flex items-center justify-between gap-2 border-b border-gray-100 pb-2.5">
                    <Link
                      href={buildShareableSearchUrl({
                        city: activeCitySlug,
                        category: root.slug,
                        locale,
                      })}
                      className="flex items-center gap-2.5 text-sm font-bold text-ink hover:text-brand-700"
                    >
                      <span
                        className={`flex h-8 w-8 items-center justify-center rounded-lg ${visual.bgClass} ${visual.textClass}`}
                      >
                        <RootIcon className="h-4 w-4" aria-hidden="true" />
                      </span>
                      <span>{root.name}</span>
                    </Link>
                    <Link
                      href={buildShareableSearchUrl({
                        city: activeCitySlug,
                        category: root.slug,
                        locale,
                      })}
                      aria-label={`View all ${root.name}`}
                      className="text-xs font-semibold text-brand-600 hover:underline"
                    >
                      View all
                    </Link>
                  </div>

                  <div className="grid grid-cols-2 gap-2">
                    {root.children.slice(0, 4).map((child) => {
                      const childVis = getCategoryVisual(child.icon_name);
                      const ChildIcon = childVis.icon;
                      return (
                        <Link
                          key={child.public_id}
                          href={buildShareableSearchUrl({
                            city: activeCitySlug,
                            category: child.slug,
                            locale,
                          })}
                          className="flex items-center gap-2 rounded-lg border border-gray-100 bg-gray-50/70 px-2.5 py-2 text-xs font-medium text-ink transition-colors hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700"
                        >
                          <ChildIcon className={`h-3.5 w-3.5 shrink-0 ${childVis.textClass}`} aria-hidden="true" />
                          <span className="truncate">{child.name}</span>
                        </Link>
                      );
                    })}
                  </div>
                </div>
              );
            })}
          </div>
        </section>
      )}

      {/* 4. Popular & Verified Businesses in Active City (100% Live GET /api/v1/search) */}
      <section
        aria-labelledby="live-businesses-heading"
        className="mx-auto max-w-7xl px-4 pt-8 sm:px-6 lg:px-8"
      >
        <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
          <div>
            <h2
              id="live-businesses-heading"
              className="text-lg font-bold tracking-tight text-ink sm:text-xl"
            >
              {locale === 'ta'
                ? `${activeCityLabel} பகுதியில் உள்ள வணிகங்கள்`
                : `Top Local Businesses in ${activeCityLabel}`}
            </h2>
            <p className="text-xs text-ink-subtle">
              {locale === 'ta'
                ? 'நேரடி NearGrid தரவுத்தளத்திலிருந்து பெறப்பட்ட சரிபார்க்கப்பட்ட பட்டியல்கள்'
                : 'Live published businesses ranked by relevance, verification, and ratings'}
            </p>
          </div>
          <Link
            href={buildShareableSearchUrl({ city: activeCitySlug, locale })}
            className="inline-flex items-center gap-1 text-xs font-bold text-brand-700 hover:underline sm:text-sm"
          >
            {locale === 'ta' ? 'மேலும் காண்க' : 'See all results'}
            <ChevronRight className="h-4 w-4" aria-hidden="true" />
          </Link>
        </div>

        {!searchRes.ok ? (
          <ErrorState
            title="Unable to load businesses right now"
            message={searchRes.error?.message ?? 'The directory search API did not respond.'}
          />
        ) : liveBusinesses.length === 0 ? (
          <EmptyState
            title={
              locale === 'ta'
                ? `${activeCityLabel} பகுதியில் இன்னும் வணிகங்கள் பதிவாகவில்லை`
                : `No published businesses in ${activeCityLabel} yet`
            }
            description={
              locale === 'ta'
                ? 'உங்கள் வணிகத்தை இலவசமாகப் பதிவு செய்து வாடிக்கையாளர்களைச் சென்றடையுங்கள்.'
                : 'Be the first verified local business listed in this city. Register for free through the NearGrid Business Portal.'
            }
            action={
              <Link
                href="/dashboard"
                className="inline-flex items-center gap-1.5 rounded-lg bg-signal-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-signal-700"
              >
                <Building2 className="h-4 w-4" aria-hidden="true" />
                {locale === 'ta' ? 'உங்கள் வணிகத்தைப் பதிவு செய்க' : 'List Your Business Free'}
              </Link>
            }
          />
        ) : (
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {liveBusinesses.map((hit) => (
              <HomeDirectoryCard key={hit.public_id} hit={hit} />
            ))}
          </div>
        )}
      </section>

      {/* 5. City & Locality Discovery Links (Live GET /api/v1/locations/*) */}
      <section
        aria-labelledby="discovery-locations-heading"
        className="mx-auto max-w-7xl px-4 pt-8 sm:px-6 lg:px-8"
      >
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
          {/* Localities in Active City */}
          <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm lg:col-span-7">
            <div className="mb-3 flex items-center gap-2">
              <MapPin className="h-4 w-4 text-signal-600" aria-hidden="true" />
              <h2
                id="discovery-locations-heading"
                className="text-base font-bold text-ink"
              >
                {locale === 'ta'
                  ? `${activeCityLabel} முக்கிய பகுதிகள்`
                  : `Popular Localities in ${activeCityLabel}`}
              </h2>
            </div>

            {localities.length === 0 ? (
              <p className="text-xs text-ink-subtle">No localities available for this city yet.</p>
            ) : (
              <div className="flex flex-wrap gap-2">
                {localities.map((loc) => (
                  <Link
                    key={loc.slug}
                    href={buildShareableSearchUrl({
                      city: activeCitySlug,
                      locality: loc.slug,
                      locale,
                    })}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-ink transition-colors hover:border-brand-400 hover:bg-brand-50 hover:text-brand-700"
                  >
                    <span>{loc.name}</span>
                    {loc.pincode && <span className="text-[11px] text-ink-subtle">{loc.pincode}</span>}
                  </Link>
                ))}
              </div>
            )}
          </div>

          {/* Explore Other Cities */}
          <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm lg:col-span-5">
            <div className="mb-3 flex items-center gap-2">
              <Sparkles className="h-4 w-4 text-brand-600" aria-hidden="true" />
              <h2 className="text-base font-bold text-ink">
                {locale === 'ta' ? 'பிற நகரங்களில் தேடுங்கள்' : 'Explore Cities on NearGrid'}
              </h2>
            </div>

            {!citiesRes.ok ? (
              <p className="text-xs text-rose-700">Unable to load cities right now.</p>
            ) : (
              <div className="flex flex-wrap gap-2">
                {cities.map((city) => {
                  const isCurrent = city.slug === activeCitySlug;
                  return (
                    <Link
                      key={city.slug}
                      href={`/?city=${encodeURIComponent(city.slug)}${locale === 'ta' ? '&locale=ta' : ''}`}
                      className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors ${
                        isCurrent
                          ? 'bg-brand-600 text-white'
                          : 'border border-gray-200 bg-white text-ink hover:border-brand-400 hover:bg-brand-50 hover:text-brand-700'
                      }`}
                    >
                      {city.name}
                    </Link>
                  );
                })}
              </div>
            )}
          </div>
        </div>
      </section>

      {/* 6. Free Business Listing CTA Banner */}
      <section className="mx-auto max-w-7xl px-4 pt-8 sm:px-6 lg:px-8">
        <div className="flex flex-col justify-between gap-6 rounded-2xl bg-gradient-to-r from-brand-800 via-brand-700 to-brand-600 p-6 text-white shadow-md sm:p-8 lg:flex-row lg:items-center">
          <div className="max-w-2xl">
            <span className="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-bold uppercase tracking-wide text-amber-200">
              <CheckCircle2 className="h-3.5 w-3.5" aria-hidden="true" />
              {locale === 'ta' ? 'வணிக உரிமையாளர்களுக்கு' : 'Grow with NearGrid Business'}
            </span>
            <h2 className="mt-3 text-xl font-extrabold tracking-tight sm:text-2xl">
              {locale === 'ta'
                ? 'உங்கள் வணிகத்தை NearGrid-இல் இலவசமாகப் பதிவு செய்யுங்கள்'
                : 'List your business for FREE and reach local customers in your city'}
            </h2>
            <p className="mt-2 text-sm text-brand-100">
              {locale === 'ta'
                ? 'புகைப்படங்கள், சேவை விலைகள், திறப்பு நேரங்கள் மற்றும் KYC சரிபார்ப்பை ஒரே இடத்தில் நிர்வகிக்கவும்.'
                : 'Manage storefront photos, services, opening hours, team access, and KYC verification securely from your NearGrid Business Dashboard.'}
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            <Link
              href="/dashboard"
              className="inline-flex items-center gap-2 rounded-xl bg-signal-600 px-5 py-3 text-sm font-bold text-white shadow-sm transition-colors hover:bg-signal-700"
            >
              {locale === 'ta' ? 'இலவசமாகப் பதிவு செய்க' : 'Start Free Listing'}
              <ArrowRight className="h-4 w-4" aria-hidden="true" />
            </Link>
            <Link
              href="/login"
              className="inline-flex items-center rounded-xl border border-white/30 bg-white/10 px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-white/20"
            >
              {locale === 'ta' ? 'OTP உள்நுழைவு' : 'Sign in with OTP'}
            </Link>
          </div>
        </div>
      </section>
    </div>
  );
}
