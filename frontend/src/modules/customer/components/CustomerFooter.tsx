import Link from 'next/link';
import { DEMO_CATEGORIES, DEMO_CITIES } from '@/shared/data/demo-directory';

export function CustomerFooter() {
  return (
    <footer className="border-t border-zinc-200 bg-zinc-50 text-zinc-700">
      <div className="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <div className="grid grid-cols-1 gap-10 md:grid-cols-4">
          <div className="space-y-3">
            <div className="flex items-center gap-2.5 text-zinc-950">
              <span className="flex h-7 w-7 items-center justify-center rounded-md bg-zinc-950 text-xs font-bold text-white">
                NG
              </span>
              <span className="text-base font-semibold tracking-tight">NearGrid</span>
            </div>
            <p className="text-sm leading-relaxed text-zinc-600">
              An independent, privacy-first local business directory built for transparent discovery
              across English and Tamil communities.
            </p>
          </div>

          <div>
            <h2 className="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-950">
              Popular Categories
            </h2>
            <ul className="space-y-2 text-sm">
              {DEMO_CATEGORIES.slice(0, 5).map((cat) => (
                <li key={cat.slug}>
                  <Link
                    href={`/search?category=${cat.slug}`}
                    className="transition-colors hover:text-zinc-950"
                  >
                    {cat.name}
                  </Link>
                </li>
              ))}
            </ul>
          </div>

          <div>
            <h2 className="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-950">
              Cities
            </h2>
            <ul className="space-y-2 text-sm">
              {DEMO_CITIES.map((city) => (
                <li key={city.slug}>
                  <Link
                    href={`/search?city=${city.slug}`}
                    className="transition-colors hover:text-zinc-950"
                  >
                    {city.name} <span className="text-zinc-400">· {city.nameTa}</span>
                  </Link>
                </li>
              ))}
            </ul>
          </div>

          <div>
            <h2 className="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-950">
              Platform
            </h2>
            <ul className="space-y-2 text-sm">
              <li>
                <Link href="/dashboard" className="transition-colors hover:text-zinc-950">
                  Business Owner Dashboard
                </Link>
              </li>
              <li>
                <Link href="/login" className="transition-colors hover:text-zinc-950">
                  OTP Sign In
                </Link>
              </li>
              <li>
                <Link href="/search" className="transition-colors hover:text-zinc-950">
                  Browse Demo Listings
                </Link>
              </li>
            </ul>
          </div>
        </div>

        <div className="mt-10 flex flex-col items-start justify-between gap-3 border-t border-zinc-200 pt-6 text-xs text-zinc-500 sm:flex-row sm:items-center">
          <p>© {new Date().getFullYear()} NearGrid Directory. Cleanroom foundation build.</p>
          <p>Consent-first enquiries · Zero silent PII capture · Clearly labelled sponsorships</p>
        </div>
      </div>
    </footer>
  );
}
