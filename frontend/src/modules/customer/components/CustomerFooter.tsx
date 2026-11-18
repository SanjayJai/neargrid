import Link from 'next/link';
import type { ReferenceCategoryDto, ReferenceCityDto } from '@/shared/api/directory-types';
import { NearGridLogo } from '@/shared/ui/NearGridLogo';
import { buildShareableSearchUrl } from '@/modules/customer/lib/search-url';

interface CustomerFooterProps {
  cities: ReferenceCityDto[];
  categories: ReferenceCategoryDto[];
}

export function CustomerFooter({ cities, categories }: CustomerFooterProps) {
  const defaultCity = cities[0]?.slug ?? 'chennai';
  const featuredCategories = categories
    .flatMap((root) => (root.children.length > 0 ? root.children : [root]))
    .slice(0, 8);

  return (
    <footer className="border-t border-gray-200 bg-white text-ink-muted">
      <div className="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <div className="grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-4">
          <div className="space-y-3">
            <NearGridLogo href="/" />
            <p className="text-xs leading-relaxed text-ink-muted">
              NearGrid is an independent local business directory built for fast English, Tamil
              (தமிழ்), and Tanglish discovery with verified listings, clear sponsorship labels, and
              privacy-first enquiries.
            </p>
          </div>

          <div>
            <h2 className="mb-3 text-xs font-bold uppercase tracking-wider text-ink">
              Popular Categories
            </h2>
            {featuredCategories.length === 0 ? (
              <p className="text-xs text-ink-subtle">Categories load from live directory API.</p>
            ) : (
              <ul className="grid grid-cols-1 gap-1.5 text-xs">
                {featuredCategories.map((cat) => (
                  <li key={cat.public_id}>
                    <Link
                      href={buildShareableSearchUrl({ city: defaultCity, category: cat.slug })}
                      className="transition-colors hover:text-brand-700 hover:underline"
                    >
                      {cat.name}
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div>
            <h2 className="mb-3 text-xs font-bold uppercase tracking-wider text-ink">
              Explore Cities
            </h2>
            {cities.length === 0 ? (
              <p className="text-xs text-ink-subtle">Cities load from live locations API.</p>
            ) : (
              <ul className="grid grid-cols-2 gap-1.5 text-xs">
                {cities.slice(0, 10).map((city) => (
                  <li key={city.slug}>
                    <Link
                      href={`/?city=${encodeURIComponent(city.slug)}`}
                      className="transition-colors hover:text-brand-700 hover:underline"
                    >
                      {city.name}
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div>
            <h2 className="mb-3 text-xs font-bold uppercase tracking-wider text-ink">
              For Business Owners
            </h2>
            <ul className="space-y-2 text-xs">
              <li>
                <Link
                  href="/dashboard"
                  className="font-semibold text-signal-700 transition-colors hover:underline"
                >
                  List Your Business Free
                </Link>
              </li>
              <li>
                <Link href="/dashboard" className="transition-colors hover:text-brand-700 hover:underline">
                  Business Owner Dashboard
                </Link>
              </li>
              <li>
                <Link href="/login" className="transition-colors hover:text-brand-700 hover:underline">
                  Sign in with Mobile OTP
                </Link>
              </li>
              <li>
                <Link
                  href={buildShareableSearchUrl({ city: defaultCity })}
                  className="transition-colors hover:text-brand-700 hover:underline"
                >
                  Browse Local Directory
                </Link>
              </li>
            </ul>
          </div>
        </div>

        <div className="mt-10 flex flex-col items-start justify-between gap-2 border-t border-gray-200 pt-6 text-xs text-ink-subtle sm:flex-row sm:items-center">
          <p>© {new Date().getFullYear()} NearGrid Directory. Independent Local Discovery Platform.</p>
          <p>English &amp; தமிழ் Search · Verified Listings · Clearly Labelled Sponsorships</p>
        </div>
      </div>
    </footer>
  );
}
