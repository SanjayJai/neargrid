import Link from 'next/link';
import {
  ArrowRight,
  Building2,
  Car,
  CheckCircle2,
  GraduationCap,
  MapPin,
  ShieldCheck,
  Stethoscope,
  Truck,
  Utensils,
  Wind,
  Wrench,
  Zap,
} from 'lucide-react';
import { BusinessCard } from '@/modules/customer/components/BusinessCard';
import { HeroSearchForm } from '@/modules/customer/components/HeroSearchForm';
import {
  DEMO_BUSINESSES,
  DEMO_CATEGORIES,
  DEMO_CITIES,
} from '@/shared/data/demo-directory';
import { DemoBanner } from '@/shared/ui/DemoBanner';

const ICON_MAP = {
  stethoscope: Stethoscope,
  utensils: Utensils,
  wind: Wind,
  wrench: Wrench,
  zap: Zap,
  car: Car,
  'graduation-cap': GraduationCap,
  truck: Truck,
} as const;

export default function HomePage() {
  return (
    <div>
      {/* Hero section */}
      <section className="border-b border-zinc-200 bg-white">
        <div className="mx-auto max-w-7xl px-4 py-14 sm:px-6 sm:py-20 lg:px-8">
          <div className="max-w-3xl">
            <span className="inline-flex items-center gap-1.5 rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 text-xs font-medium text-zinc-700">
              <ShieldCheck className="h-3.5 w-3.5 text-zinc-950" aria-hidden="true" />
              Verified local discovery · English &amp; தமிழ் ready
            </span>

            <h1 className="mt-5 text-4xl font-semibold tracking-tight text-zinc-950 sm:text-5xl">
              Find trusted local businesses without the clutter.
            </h1>

            <p className="mt-4 max-w-2xl text-base leading-relaxed text-zinc-600 sm:text-lg">
              Compare verified clinics, home-service specialists, and neighborhood restaurants with
              transparent reviews, clear sponsorship labels, and privacy-first enquiries.
            </p>
          </div>

          <div className="mt-8 max-w-4xl">
            <HeroSearchForm />
          </div>

          {/* Quick city chips */}
          <div className="mt-6 flex flex-wrap items-center gap-2 text-xs">
            <span className="inline-flex items-center gap-1 font-medium text-zinc-500">
              <MapPin className="h-3.5 w-3.5" aria-hidden="true" />
              Popular cities:
            </span>
            {DEMO_CITIES.map((city) => (
              <Link
                key={city.slug}
                href={`/search?city=${city.slug}`}
                className="rounded-full border border-zinc-200 bg-white px-3 py-1 font-medium text-zinc-700 transition-colors hover:border-zinc-950 hover:text-zinc-950"
              >
                {city.name} <span className="text-zinc-400">· {city.nameTa}</span>
              </Link>
            ))}
          </div>
        </div>
      </section>

      {/* Popular categories */}
      <section aria-labelledby="categories-heading" className="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
        <div className="mb-8 flex items-end justify-between gap-4">
          <div>
            <h2 id="categories-heading" className="text-2xl font-semibold tracking-tight text-zinc-950">
              Popular Categories
            </h2>
            <p className="mt-1 text-sm text-zinc-600">
              Browse curated service categories with English and Tamil terminology support.
            </p>
          </div>
          <Link
            href="/search"
            className="hidden items-center gap-1 text-sm font-semibold text-zinc-950 hover:underline sm:inline-flex"
          >
            All listings
            <ArrowRight className="h-4 w-4" aria-hidden="true" />
          </Link>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {DEMO_CATEGORIES.map((category) => {
            const IconComponent = ICON_MAP[category.icon as keyof typeof ICON_MAP] ?? Building2;
            return (
              <Link
                key={category.slug}
                href={`/search?category=${category.slug}`}
                className="group flex flex-col justify-between rounded-2xl border border-zinc-200 bg-white p-5 transition-all hover:border-zinc-950 hover:shadow-sm"
              >
                <div className="flex items-start justify-between gap-3">
                  <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-zinc-100 text-zinc-950 transition-colors group-hover:bg-zinc-950 group-hover:text-white">
                    <IconComponent className="h-5 w-5" aria-hidden="true" />
                  </span>
                  <span className="text-xs font-medium text-zinc-400">
                    {category.listingCountDemo} demo
                  </span>
                </div>
                <div className="mt-4">
                  <h3 className="text-base font-semibold text-zinc-950">{category.name}</h3>
                  <p className="mt-0.5 text-xs text-zinc-500">{category.nameTa}</p>
                  <p className="mt-2.5 text-xs text-zinc-500">
                    {category.subcategories.join(' · ')}
                  </p>
                </div>
              </Link>
            );
          })}
        </div>
      </section>

      {/* Featured businesses (clearly identified demo data) */}
      <section aria-labelledby="featured-heading" className="border-y border-zinc-200 bg-zinc-50/70">
        <div className="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
          <div className="mb-6 flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
            <div>
              <h2 id="featured-heading" className="text-2xl font-semibold tracking-tight text-zinc-950">
                Featured Local Businesses
              </h2>
              <p className="mt-1 text-sm text-zinc-600">
                Previewing card hierarchy, verification badges, and transparent sponsorship labels.
              </p>
            </div>
            <Link
              href="/search"
              className="inline-flex items-center gap-1 text-sm font-semibold text-zinc-950 hover:underline"
            >
              Explore search workspace
              <ArrowRight className="h-4 w-4" aria-hidden="true" />
            </Link>
          </div>

          <div className="mb-6">
            <DemoBanner description="Featured businesses below are fictional demo fixtures created for Phase 3A visual verification. Live Laravel Directory API listings will replace them in Phase 3B." />
          </div>

          <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
            {DEMO_BUSINESSES.map((biz) => (
              <BusinessCard key={biz.publicId} business={biz} />
            ))}
          </div>
        </div>
      </section>

      {/* Business owner CTA */}
      <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        <div className="flex flex-col justify-between gap-8 rounded-3xl border border-zinc-200 bg-zinc-950 p-8 text-white sm:p-12 lg:flex-row lg:items-center">
          <div className="max-w-2xl">
            <span className="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-zinc-200">
              <CheckCircle2 className="h-3.5 w-3.5" aria-hidden="true" />
              For Local Business Owners
            </span>
            <h2 className="mt-4 text-2xl font-semibold tracking-tight sm:text-3xl">
              Manage your storefront, hours, and consented customer enquiries in one dashboard.
            </h2>
            <p className="mt-3 text-sm leading-relaxed text-zinc-300">
              Sign in with mobile OTP via our Next.js BFF to manage your account, active sessions,
              registered devices, and business portal workspace.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            <Link
              href="/dashboard"
              className="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-semibold text-zinc-950 transition-colors hover:bg-zinc-200"
            >
              Open Business Dashboard
              <ArrowRight className="h-4 w-4" aria-hidden="true" />
            </Link>
            <Link
              href="/login"
              className="inline-flex items-center rounded-xl border border-zinc-700 px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-zinc-900"
            >
              Sign in with OTP
            </Link>
          </div>
        </div>
      </section>
    </div>
  );
}
