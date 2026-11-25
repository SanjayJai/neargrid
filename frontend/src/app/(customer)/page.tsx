import Link from 'next/link';
import {
  ArrowRight,
  Building2,
  Car,
  Compass,
  HeartPulse,
  MapPin,
  ShieldCheck,
  ShoppingBag,
  Utensils,
} from 'lucide-react';
import {
  fetchPublicCategoryTree,
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
    { label: 'Restaurants', q: 'restaurant' },
    { label: 'Dentists', q: 'dentist' },
    { label: 'Hospitals', q: 'hospital' },
    { label: 'Plumbers', q: 'plumber' },
    { label: 'Electricians', q: 'electrician' },
    { label: 'AC Repair', q: 'ac repair' },
  ],
  ta: [
    { label: 'உணவகம்', q: 'உணவகம்' },
    { label: 'பல் மருத்துவர்', q: 'பல் மருத்துவர்' },
    { label: 'மருத்துவமனை', q: 'மருத்துவமனை' },
    { label: 'பிளம்பர்', q: 'பிளம்பர்' },
    { label: 'எலக்ட்ரீஷியன்', q: 'எலக்ட்ரீஷியன்' },
    { label: 'ஏசி சர்வீஸ்', q: 'ஏசி சர்வீஸ்' },
  ],
};

const PASTEL_ICON_BG = [
  'bg-blue-50',
  'bg-indigo-50',
  'bg-emerald-50',
  'bg-amber-50',
  'bg-sky-50',
  'bg-violet-50',
  'bg-orange-50',
  'bg-teal-50',
  'bg-rose-50',
  'bg-cyan-50',
] as const;

export default async function HomePage({ searchParams }: HomePageProps) {
  const locale = normalizeLocale(searchParams.locale);
  const requestedCity = sanitizeSlug(searchParams.city);

  const [citiesRes, treeRes, legacyCategoriesRes] = await Promise.all([
    fetchPublicCities(locale),
    fetchPublicCategoryTree(locale),
    fetchPublicCategories(locale),
  ]);

  const cities = citiesRes.ok && citiesRes.data ? citiesRes.data : [];
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

  // Build up to 16 category tiles from 5-level CategoryTree (fallback to legacy 2-level categories)
  const treeCategories = treeRes.ok && treeRes.data ? treeRes.data.categories : [];
  const fallbackCategories = legacyCategoriesRes.ok && legacyCategoriesRes.data ? legacyCategoriesRes.data : [];

  const categoryTiles =
    treeCategories.length > 0
      ? treeCategories
          .flatMap((root) => (root.children.length > 0 ? [root, ...root.children] : [root]))
          .slice(0, 16)
          .map((node) => ({
            public_id: node.public_id,
            slug: node.slug,
            name: node.name,
            icon_name: node.icon_name,
            href: `${node.path}?city=${encodeURIComponent(activeCitySlug)}`,
            badge: node.is_popular ? 'NEW' : null,
          }))
      : fallbackCategories
          .flatMap((root) => (root.children.length > 0 ? root.children : [root]))
          .slice(0, 16)
          .map((cat) => ({
            public_id: cat.public_id,
            slug: cat.slug,
            name: cat.name,
            icon_name: cat.icon_name,
            href: `/${encodeURIComponent(activeCitySlug)}/${encodeURIComponent(cat.slug)}`,
            badge: cat.is_featured ? 'NEW' : null,
          }));

  const quickChips = QUICK_SEARCH_TERMS[locale];

  const featuredCards = [
    {
      title: locale === 'ta' ? 'உணவு & உணவகங்கள்' : 'Dining & Restaurants',
      cta: locale === 'ta' ? 'பார்க்க →' : 'Explore →',
      href: `/search?city=${encodeURIComponent(activeCitySlug)}&q=restaurant`,
      gradient: 'from-slate-900 via-brand-800 to-brand-600',
      icon: Utensils,
    },
    {
      title: locale === 'ta' ? 'மருத்துவம் & கிளினிக்குகள்' : 'Healthcare & Clinics',
      cta: locale === 'ta' ? 'பார்க்க →' : 'Find Now →',
      href: `/search?city=${encodeURIComponent(activeCitySlug)}&q=dentist`,
      gradient: 'from-sky-900 via-sky-700 to-cyan-600',
      icon: HeartPulse,
    },
    {
      title: locale === 'ta' ? 'கார் & வாகன சேவை' : 'Auto & Local Services',
      cta: locale === 'ta' ? 'முன்பதிவு →' : 'Explore →',
      href: `/search?city=${encodeURIComponent(activeCitySlug)}&q=service`,
      gradient: 'from-zinc-900 via-zinc-700 to-amber-700',
      icon: Car,
    },
    {
      title: locale === 'ta' ? 'சரிபார்க்கப்பட்ட வணிகங்கள்' : 'Verified Top Businesses',
      cta: locale === 'ta' ? 'பார்க்க →' : 'Browse Now →',
      href: `/search?city=${encodeURIComponent(activeCitySlug)}&verified_only=1`,
      gradient: 'from-indigo-950 via-brand-700 to-blue-500',
      icon: ShoppingBag,
    },
  ];

  return (
    <div className="bg-surface-canvas pb-12">
      {/* 1. Reference-Matched Centered Hero Banner */}
      <section className="relative overflow-hidden border-b border-gray-200 bg-gradient-to-b from-blue-50 via-sky-50/60 to-white">
        <div
          aria-hidden="true"
          className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_top_left,_rgba(29,78,216,0.08),transparent_45%),radial-gradient(circle_at_top_right,_rgba(249,115,22,0.08),transparent_45%)]"
        />
        <div className="relative mx-auto max-w-7xl px-4 py-10 sm:px-6 sm:py-12 lg:px-8">
          <div className="mx-auto max-w-4xl text-center">
            <h1 className="text-3xl font-extrabold tracking-tight text-ink sm:text-4xl lg:text-5xl">
              {locale === 'ta' ? (
                <>
                  <span className="text-brand-600">{activeCityLabel}</span> நகரை கண்டறியுங்கள்.
                </>
              ) : (
                <>
                  Discover <span className="text-brand-600">{activeCityLabel}</span>.{' '}
                  <span className="relative inline-block">
                    Find
                    <span
                      aria-hidden="true"
                      className="absolute -bottom-1 left-0 right-0 h-1 rounded-full bg-signal-500"
                    />
                  </span>{' '}
                  What You Need.
                </>
              )}
            </h1>
            <p className="mx-auto mt-3 max-w-2xl text-sm text-ink-muted sm:text-base">
              {locale === 'ta'
                ? 'உங்கள் அருகிலுள்ள நம்பகமான உள்ளூர் வணிகங்கள் மற்றும் சேவைகளைக் கண்டறியுங்கள்.'
                : 'Explore trusted local businesses, clinics, restaurants and verified services near you.'}
            </p>

            {/* Centered Unified Search Bar */}
            <div className="mx-auto mt-6 max-w-3xl text-left">
              <LiveSearchBar
                cities={cities}
                initialCitySlug={activeCitySlug}
                initialLocalities={localities}
                locale={locale}
                variant="hero"
              />
            </div>

            {/* Quick Category / Search Pill Chips */}
            <div className="mt-4 flex flex-wrap items-center justify-center gap-2">
              {quickChips.map((chip) => (
                <Link
                  key={chip.q}
                  href={buildShareableSearchUrl({
                    city: activeCitySlug,
                    q: chip.q,
                    locale,
                  })}
                  className="rounded-full border border-gray-200 bg-white px-3.5 py-1.5 text-xs font-semibold text-ink shadow-sm transition-colors hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700"
                >
                  {chip.label}
                </Link>
              ))}
            </div>

            <p className="mt-4 text-xs font-medium text-ink-subtle">
              Local listings &bull; Free to explore &bull; {activeCityLabel} &amp; nearby areas
            </p>
          </div>
        </div>
      </section>

      {/* 2. 4-Card Featured Discovery Strip */}
      <section className="mx-auto -mt-4 max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {featuredCards.map((card) => {
            const Icon = card.icon;
            return (
              <Link
                key={card.title}
                href={card.href}
                className={`group relative flex min-h-[124px] flex-col justify-between overflow-hidden rounded-2xl bg-gradient-to-br ${card.gradient} p-5 text-white shadow-md transition-transform hover:-translate-y-0.5`}
              >
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <span className="mb-2 inline-flex h-8 w-8 items-center justify-center rounded-xl bg-white/15">
                      <Icon className="h-4 w-4 text-white" aria-hidden="true" />
                    </span>
                    <h2 className="text-base font-extrabold leading-snug">{card.title}</h2>
                  </div>
                </div>
                <span className="mt-3 inline-flex w-fit items-center rounded-full bg-white px-3 py-1 text-xs font-bold text-ink shadow-sm transition-colors group-hover:bg-signal-500 group-hover:text-white">
                  {card.cta}
                </span>
              </Link>
            );
          })}
        </div>
      </section>

      {/* 3. Explore Categories 8x2 Icon Grid */}
      <section
        aria-labelledby="home-categories-heading"
        className="mx-auto mt-8 max-w-7xl px-4 sm:px-6 lg:px-8"
      >
        <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
          <div className="mb-5 flex flex-wrap items-end justify-between gap-2">
            <div>
              <h2
                id="home-categories-heading"
                className="text-lg font-extrabold tracking-tight text-ink sm:text-xl"
              >
                {locale === 'ta' ? 'வகைகளை ஆராயுங்கள்' : 'Explore Categories'}
              </h2>
              <p className="mt-0.5 text-xs text-ink-muted">
                {locale === 'ta'
                  ? `${activeCityLabel} நகரின் அனைத்து சேவைகளும் ஒரே இடத்தில்`
                  : `Everything happening around ${activeCityLabel}, all in one place`}
              </p>
            </div>
            <Link
              href={`/search?city=${encodeURIComponent(activeCitySlug)}`}
              className="inline-flex items-center gap-1 text-xs font-bold text-brand-600 hover:text-brand-700"
            >
              {locale === 'ta' ? 'அனைத்தையும் பார்க்க' : 'Explore All'}
              <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
            </Link>
          </div>

          {categoryTiles.length === 0 ? (
            <EmptyState
              title="Categories unavailable"
              description="Unable to load categories right now."
            />
          ) : (
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
              {categoryTiles.map((cat, idx) => {
                const visual = getCategoryVisual(cat.icon_name ?? cat.slug);
                const Icon = visual.icon;
                const pastel = PASTEL_ICON_BG[idx % PASTEL_ICON_BG.length];

                return (
                  <Link
                    key={cat.public_id}
                    href={cat.href}
                    className="group relative flex flex-col items-center rounded-xl border border-gray-100 bg-white p-3 text-center transition-all hover:border-brand-200 hover:bg-brand-50/40 hover:shadow-sm"
                  >
                    {cat.badge && (
                      <span className="absolute right-2 top-2 rounded bg-signal-500 px-1.5 py-0.5 text-[9px] font-extrabold uppercase text-white">
                        {cat.badge}
                      </span>
                    )}
                    <span
                      className={`flex h-12 w-12 items-center justify-center rounded-2xl ${pastel} ${visual.textClass} transition-transform group-hover:scale-105`}
                    >
                      <Icon className="h-5 w-5" aria-hidden="true" />
                    </span>
                    <span className="mt-2.5 line-clamp-2 text-xs font-semibold text-ink group-hover:text-brand-700">
                      {cat.name}
                    </span>
                  </Link>
                );
              })}
            </div>
          )}
        </div>
      </section>

      {/* 4. Verified Local Businesses Showcase */}
      <section
        aria-labelledby="home-businesses-heading"
        className="mx-auto mt-8 max-w-7xl px-4 sm:px-6 lg:px-8"
      >
        <div className="mb-4 flex flex-wrap items-end justify-between gap-2">
          <div>
            <h2
              id="home-businesses-heading"
              className="text-lg font-extrabold tracking-tight text-ink sm:text-xl"
            >
              {locale === 'ta'
                ? `${activeCityLabel} - சரிபார்க்கப்பட்ட வணிகங்கள்`
                : `Featured Businesses in ${activeCityLabel}`}
            </h2>
            <p className="mt-0.5 text-xs text-ink-muted">
              Real-time verified listings with ratings, timings and direct enquiry support.
            </p>
          </div>
          <Link
            href={`/search?city=${encodeURIComponent(activeCitySlug)}`}
            className="inline-flex items-center gap-1 text-xs font-bold text-brand-600 hover:text-brand-700"
          >
            View all in {activeCityLabel}
            <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
          </Link>
        </div>

        {!searchRes.ok ? (
          <ErrorState
            title="Businesses temporarily unavailable"
            message="Start the Laravel API server to load live businesses."
          />
        ) : liveBusinesses.length === 0 ? (
          <EmptyState
            title={`No listings yet in ${activeCityLabel}`}
            description="Try switching cities or add your business listing for free."
          />
        ) : (
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            {liveBusinesses.map((hit) => (
              <HomeDirectoryCard key={hit.public_id} hit={hit} />
            ))}
          </div>
        )}
      </section>

      {/* 5. Location Discovery & Merchant CTA */}
      <section className="mx-auto mt-8 max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="grid gap-6 lg:grid-cols-3">
          <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm lg:col-span-2">
            <h2 className="flex items-center gap-2 text-base font-extrabold text-ink">
              <Compass className="h-4 w-4 text-brand-600" aria-hidden="true" />
              Explore Popular Areas in {activeCityLabel}
            </h2>
            <div className="mt-3 flex flex-wrap gap-2">
              {localities.slice(0, 14).map((loc) => (
                <Link
                  key={loc.slug}
                  href={`/search?city=${encodeURIComponent(activeCitySlug)}&locality=${encodeURIComponent(loc.slug)}`}
                  className="inline-flex items-center gap-1 rounded-full border border-gray-200 bg-surface-subtle px-3 py-1 text-xs font-medium text-ink hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700"
                >
                  <MapPin className="h-3 w-3 text-signal-500" aria-hidden="true" />
                  {loc.name}
                </Link>
              ))}
              {cities.slice(0, 8).map((c) => (
                <Link
                  key={c.slug}
                  href={`/?city=${encodeURIComponent(c.slug)}`}
                  className={`inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs font-semibold ${
                    c.slug === activeCitySlug
                      ? 'border-brand-600 bg-brand-600 text-white'
                      : 'border-gray-200 bg-white text-ink-muted hover:border-brand-300 hover:text-brand-700'
                  }`}
                >
                  {c.name}
                </Link>
              ))}
            </div>
          </div>

          <div className="flex flex-col justify-between rounded-2xl bg-gradient-to-br from-brand-700 to-brand-800 p-5 text-white shadow-sm">
            <div>
              <span className="inline-flex items-center gap-1 rounded-full bg-white/15 px-2.5 py-0.5 text-[11px] font-bold">
                <ShieldCheck className="h-3.5 w-3.5 text-signal-500" aria-hidden="true" />
                For Business Owners
              </span>
              <h2 className="mt-2.5 text-lg font-extrabold">List Your Business Free</h2>
              <p className="mt-1 text-xs text-blue-100">
                Get discovered across {activeCityLabel}, manage enquiries and earn verified badges.
              </p>
            </div>
            <Link
              href="/dashboard"
              className="mt-4 inline-flex w-fit items-center gap-1.5 rounded-xl bg-signal-500 px-4 py-2 text-xs font-bold text-white hover:bg-signal-600"
            >
              <Building2 className="h-3.5 w-3.5" aria-hidden="true" />
              Open Merchant Workspace
            </Link>
          </div>
        </div>
      </section>
    </div>
  );
}
