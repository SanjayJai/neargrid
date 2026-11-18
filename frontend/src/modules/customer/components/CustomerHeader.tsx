'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import {
  Building2,
  ChevronDown,
  LogOut,
  MapPin,
  Menu,
  Search,
  User as UserIcon,
  X,
} from 'lucide-react';
import type { UserDto } from '@/lib/bff/types';
import { bffFetch } from '@/shared/api/bff-client';
import { DEMO_CITIES } from '@/shared/data/demo-directory';

interface CustomerHeaderProps {
  user: UserDto | null;
}

export function CustomerHeader({ user }: CustomerHeaderProps) {
  const router = useRouter();
  const [selectedCity, setSelectedCity] = useState(DEMO_CITIES[0]);
  const [cityMenuOpen, setCityMenuOpen] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);

  async function handleLogout() {
    setLoggingOut(true);
    try {
      await bffFetch('/api/auth/logout', { method: 'POST' });
      router.refresh();
    } finally {
      setLoggingOut(false);
    }
  }

  return (
    <header className="sticky top-0 z-40 border-b border-zinc-200 bg-white/95 backdrop-blur">
      <div className="mx-auto flex h-16 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
        <div className="flex items-center gap-6">
          <Link
            href="/"
            className="flex items-center gap-2.5 text-zinc-950 transition-opacity hover:opacity-80"
          >
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-zinc-950 text-xs font-bold tracking-tight text-white">
              NG
            </span>
            <span className="text-base font-semibold tracking-tight">NearGrid</span>
          </Link>

          {/* Location selector */}
          <div className="relative hidden sm:block">
            <button
              type="button"
              onClick={() => setCityMenuOpen((prev) => !prev)}
              aria-expanded={cityMenuOpen}
              aria-haspopup="listbox"
              className="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-sm font-medium text-zinc-900 transition-colors hover:border-zinc-300 hover:bg-zinc-100"
            >
              <MapPin className="h-4 w-4 text-zinc-700" aria-hidden="true" />
              <span>{selectedCity.name}</span>
              <ChevronDown className="h-3.5 w-3.5 text-zinc-500" aria-hidden="true" />
            </button>

            {cityMenuOpen && (
              <div
                role="listbox"
                aria-label="Select city"
                className="absolute left-0 mt-2 w-60 rounded-xl border border-zinc-200 bg-white p-1.5 shadow-lg"
              >
                {DEMO_CITIES.map((city) => (
                  <button
                    key={city.slug}
                    type="button"
                    role="option"
                    aria-selected={selectedCity.slug === city.slug}
                    onClick={() => {
                      setSelectedCity(city);
                      setCityMenuOpen(false);
                    }}
                    className={`flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm transition-colors ${
                      selectedCity.slug === city.slug
                        ? 'bg-zinc-950 text-white'
                        : 'text-zinc-800 hover:bg-zinc-100'
                    }`}
                  >
                    <span className="font-medium">{city.name}</span>
                    <span
                      className={`text-xs ${
                        selectedCity.slug === city.slug ? 'text-zinc-300' : 'text-zinc-500'
                      }`}
                    >
                      {city.nameTa}
                    </span>
                  </button>
                ))}
              </div>
            )}
          </div>
        </div>

        {/* Desktop navigation */}
        <nav aria-label="Primary navigation" className="hidden items-center gap-6 md:flex">
          <Link
            href={`/search?city=${selectedCity.slug}`}
            className="inline-flex items-center gap-1.5 text-sm font-medium text-zinc-700 transition-colors hover:text-zinc-950"
          >
            <Search className="h-4 w-4" aria-hidden="true" />
            Explore Listings
          </Link>
          <Link
            href="/dashboard"
            className="inline-flex items-center gap-1.5 text-sm font-medium text-zinc-700 transition-colors hover:text-zinc-950"
          >
            <Building2 className="h-4 w-4" aria-hidden="true" />
            For Businesses
          </Link>
        </nav>

        {/* Auth actions */}
        <div className="hidden items-center gap-3 md:flex">
          {user ? (
            <>
              <Link
                href="/dashboard/settings"
                className="inline-flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-1.5 text-sm font-medium text-zinc-900 transition-colors hover:bg-zinc-50"
              >
                <UserIcon className="h-4 w-4 text-zinc-600" aria-hidden="true" />
                <span>{user.name ?? user.phone_e164 ?? 'Account'}</span>
              </Link>
              <button
                type="button"
                onClick={handleLogout}
                disabled={loggingOut}
                className="inline-flex items-center gap-1.5 rounded-lg bg-zinc-950 px-3.5 py-1.5 text-sm font-medium text-white transition-colors hover:bg-zinc-800 disabled:opacity-50"
              >
                <LogOut className="h-3.5 w-3.5" aria-hidden="true" />
                {loggingOut ? 'Signing out...' : 'Sign out'}
              </button>
            </>
          ) : (
            <>
              <Link
                href="/login"
                className="rounded-lg px-3.5 py-2 text-sm font-medium text-zinc-800 transition-colors hover:bg-zinc-100"
              >
                Log in
              </Link>
              <Link
                href="/dashboard"
                className="rounded-lg bg-zinc-950 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-zinc-800"
              >
                Business Portal
              </Link>
            </>
          )}
        </div>

        {/* Mobile menu trigger */}
        <button
          type="button"
          onClick={() => setMobileOpen((prev) => !prev)}
          aria-expanded={mobileOpen}
          aria-label="Toggle navigation menu"
          className="inline-flex items-center justify-center rounded-lg border border-zinc-200 p-2 text-zinc-900 md:hidden"
        >
          {mobileOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
        </button>
      </div>

      {/* Mobile navigation drawer */}
      {mobileOpen && (
        <div className="border-t border-zinc-200 bg-white px-4 py-4 md:hidden">
          <div className="mb-4">
            <label htmlFor="mobile-city" className="mb-1 block text-xs font-medium text-zinc-500">
              Active City
            </label>
            <select
              id="mobile-city"
              value={selectedCity.slug}
              onChange={(e) => {
                const found = DEMO_CITIES.find((c) => c.slug === e.target.value);
                if (found) setSelectedCity(found);
              }}
              className="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950"
            >
              {DEMO_CITIES.map((city) => (
                <option key={city.slug} value={city.slug}>
                  {city.name} ({city.nameTa})
                </option>
              ))}
            </select>
          </div>
          <nav className="flex flex-col gap-2">
            <Link
              href={`/search?city=${selectedCity.slug}`}
              onClick={() => setMobileOpen(false)}
              className="rounded-lg px-3 py-2 text-sm font-medium text-zinc-800 hover:bg-zinc-100"
            >
              Explore Listings
            </Link>
            <Link
              href="/dashboard"
              onClick={() => setMobileOpen(false)}
              className="rounded-lg px-3 py-2 text-sm font-medium text-zinc-800 hover:bg-zinc-100"
            >
              Business Portal
            </Link>
            {user ? (
              <button
                type="button"
                onClick={handleLogout}
                className="mt-2 rounded-lg bg-zinc-950 px-3 py-2 text-center text-sm font-medium text-white"
              >
                Sign out ({user.name ?? user.phone_e164})
              </button>
            ) : (
              <Link
                href="/login"
                onClick={() => setMobileOpen(false)}
                className="mt-2 rounded-lg bg-zinc-950 px-3 py-2 text-center text-sm font-medium text-white"
              >
                Log in with OTP
              </Link>
            )}
          </nav>
        </div>
      )}
    </header>
  );
}
