'use client';

import Link from 'next/link';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import {
  Building2,
  ChevronDown,
  Crosshair,
  Globe,
  Loader2,
  LogOut,
  MapPin,
  Menu,
  User as UserIcon,
  X,
} from 'lucide-react';
import type { UserDto } from '@/lib/bff/types';
import { bffFetch } from '@/shared/api/bff-client';
import type { ReferenceCityDto } from '@/shared/api/directory-types';
import { NearGridLogo } from '@/shared/ui/NearGridLogo';
import {
  buildShareableSearchUrl,
  normalizeLocale,
  sanitizeSlug,
  type SupportedLocale,
} from '@/modules/customer/lib/search-url';

interface CustomerHeaderProps {
  user: UserDto | null;
  cities: ReferenceCityDto[];
}

export function CustomerHeader({ user, cities }: CustomerHeaderProps) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const cityDropdownRef = useRef<HTMLDivElement>(null);

  const locale: SupportedLocale = normalizeLocale(searchParams.get('locale'));
  const urlCitySlug = sanitizeSlug(searchParams.get('city'));
  const defaultCity = cities[0]?.slug ?? 'chennai';
  const activeCitySlug =
    (urlCitySlug && cities.some((c) => c.slug === urlCitySlug) ? urlCitySlug : null) ??
    urlCitySlug ??
    defaultCity;

  const activeCity = cities.find((c) => c.slug === activeCitySlug) ?? null;

  const [cityMenuOpen, setCityMenuOpen] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const [locating, setLocating] = useState(false);
  const [geoNotice, setGeoNotice] = useState<string | null>(null);

  useEffect(() => {
    function handleOutside(event: MouseEvent) {
      if (cityDropdownRef.current && !cityDropdownRef.current.contains(event.target as Node)) {
        setCityMenuOpen(false);
      }
    }
    document.addEventListener('mousedown', handleOutside);
    return () => document.removeEventListener('mousedown', handleOutside);
  }, []);

  function updateQueryParams(patch: Record<string, string | null>) {
    const next = new URLSearchParams(searchParams.toString());
    for (const [key, value] of Object.entries(patch)) {
      if (value === null || value === '') {
        next.delete(key);
      } else {
        next.set(key, value);
      }
    }
    const qs = next.toString();
    const targetPath = pathname === '/' || pathname === '/search' ? pathname : '/';
    router.push(qs ? `${targetPath}?${qs}` : targetPath);
  }

  function handleSelectCity(slug: string) {
    setCityMenuOpen(false);
    setMobileOpen(false);
    setGeoNotice(null);
    updateQueryParams({ city: slug, locality: null });
  }

  function handleToggleLocale(nextLocale: SupportedLocale) {
    setMobileOpen(false);
    updateQueryParams({ locale: nextLocale === 'en' ? null : nextLocale });
  }

  /**
   * Near Me geolocation is strictly requested only after explicit user interaction.
   */
  function handleNearMe() {
    setGeoNotice(null);
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
      setGeoNotice(
        locale === 'ta'
          ? 'இருப்பிட சேவை ஆதரிக்கப்படவில்லை.'
          : 'Geolocation is unavailable in this browser.'
      );
      return;
    }

    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setLocating(false);
        setCityMenuOpen(false);
        setMobileOpen(false);
        router.push(
          buildShareableSearchUrl({
            lat: pos.coords.latitude,
            lng: pos.coords.longitude,
            radiusKm: 10,
            sort: 'distance',
            locale,
          })
        );
      },
      (err) => {
        setLocating(false);
        setGeoNotice(
          err.code === err.PERMISSION_DENIED
            ? locale === 'ta'
              ? 'இருப்பிட அனுமதி மறுக்கப்பட்டது.'
              : 'Location permission denied. Select a city manually.'
            : locale === 'ta'
              ? 'இருப்பிடத்தைக் கண்டறிய முடியவில்லை.'
              : 'Unable to detect your location.'
        );
      },
      { enableHighAccuracy: false, timeout: 8000, maximumAge: 60_000 }
    );
  }

  async function handleLogout() {
    setLoggingOut(true);
    try {
      await bffFetch('/api/auth/logout', { method: 'POST' });
      setMobileOpen(false);
      router.refresh();
    } finally {
      setLoggingOut(false);
    }
  }

  return (
    <header className="sticky top-0 z-40 border-b border-gray-200 bg-white shadow-sm">
      <div className="mx-auto flex h-16 max-w-7xl items-center justify-between gap-3 px-4 sm:px-6 lg:px-8">
        {/* Left: NearGrid Brand + Live City Selector + Near Me */}
        <div className="flex min-w-0 items-center gap-3 sm:gap-4">
          <NearGridLogo href={locale === 'ta' ? '/?locale=ta' : '/'} />

          <div ref={cityDropdownRef} className="relative hidden sm:block">
            <button
              type="button"
              onClick={() => setCityMenuOpen((prev) => !prev)}
              aria-expanded={cityMenuOpen}
              aria-haspopup="listbox"
              aria-label="Select city"
              className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-gray-50 px-3 py-1.5 text-sm font-semibold text-ink transition-colors hover:border-brand-400 hover:bg-brand-50"
            >
              <MapPin className="h-4 w-4 text-signal-600" aria-hidden="true" />
              <span className="max-w-[120px] truncate">{activeCity?.name ?? activeCitySlug}</span>
              <ChevronDown className="h-3.5 w-3.5 text-ink-subtle" aria-hidden="true" />
            </button>

            {cityMenuOpen && (
              <div
                role="listbox"
                aria-label="Available cities"
                className="absolute left-0 mt-2 w-72 rounded-xl border border-gray-200 bg-white p-2 shadow-xl"
              >
                <button
                  type="button"
                  onClick={handleNearMe}
                  disabled={locating}
                  className="mb-2 flex w-full items-center justify-center gap-1.5 rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 text-xs font-semibold text-brand-700 hover:bg-brand-100 disabled:opacity-60"
                >
                  {locating ? (
                    <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                  ) : (
                    <Crosshair className="h-3.5 w-3.5" aria-hidden="true" />
                  )}
                  <span>
                    {locating
                      ? 'Detecting location...'
                      : locale === 'ta'
                        ? 'எனக்கு அருகில் (Near Me)'
                        : 'Use My Location (Near Me)'}
                  </span>
                </button>

                {geoNotice && (
                  <p role="alert" className="mb-2 rounded-md bg-rose-50 px-2.5 py-1.5 text-xs text-rose-700">
                    {geoNotice}
                  </p>
                )}

                {cities.length === 0 ? (
                  <p className="px-3 py-2 text-xs text-ink-subtle">No cities loaded.</p>
                ) : (
                  <div className="max-h-64 space-y-0.5 overflow-y-auto">
                    {cities.map((city) => {
                      const isSelected = city.slug === activeCitySlug;
                      return (
                        <button
                          key={city.slug}
                          type="button"
                          role="option"
                          aria-selected={isSelected}
                          onClick={() => handleSelectCity(city.slug)}
                          className={`flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm transition-colors ${
                            isSelected
                              ? 'bg-brand-600 font-semibold text-white'
                              : 'text-ink hover:bg-gray-100'
                          }`}
                        >
                          <span>{city.name}</span>
                          {city.is_popular && (
                            <span
                              className={`text-[10px] font-semibold uppercase ${
                                isSelected ? 'text-brand-100' : 'text-ink-subtle'
                              }`}
                            >
                              Popular
                            </span>
                          )}
                        </button>
                      );
                    })}
                  </div>
                )}
              </div>
            )}
          </div>

          {/* Direct Near Me desktop pill */}
          <button
            type="button"
            onClick={handleNearMe}
            disabled={locating}
            className="hidden items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-700 transition-colors hover:border-brand-300 hover:bg-brand-50 disabled:opacity-60 lg:inline-flex"
          >
            {locating ? (
              <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
            ) : (
              <Crosshair className="h-3.5 w-3.5 text-signal-600" aria-hidden="true" />
            )}
            <span>{locale === 'ta' ? 'அருகில்' : 'Near Me'}</span>
          </button>
        </div>

        {/* Right: EN / தமிழ் Toggle + Free Listing CTA + Account Actions */}
        <div className="hidden items-center gap-2.5 md:flex">
          {/* Language Switcher */}
          <div
            role="group"
            aria-label="Language selector"
            className="inline-flex items-center rounded-lg border border-gray-200 bg-gray-50 p-0.5 text-xs font-semibold"
          >
            <Globe className="mx-1.5 h-3.5 w-3.5 text-ink-subtle" aria-hidden="true" />
            <button
              type="button"
              onClick={() => handleToggleLocale('en')}
              aria-pressed={locale === 'en'}
              className={`rounded-md px-2 py-1 transition-colors ${
                locale === 'en' ? 'bg-brand-600 text-white' : 'text-ink-muted hover:text-ink'
              }`}
            >
              EN
            </button>
            <button
              type="button"
              onClick={() => handleToggleLocale('ta')}
              aria-pressed={locale === 'ta'}
              className={`rounded-md px-2 py-1 transition-colors ${
                locale === 'ta' ? 'bg-brand-600 text-white' : 'text-ink-muted hover:text-ink'
              }`}
            >
              தமிழ்
            </button>
          </div>

          {/* Free Listing CTA */}
          <Link
            href="/dashboard"
            className="inline-flex items-center gap-1.5 rounded-lg border border-signal-500/30 bg-signal-50 px-3 py-1.5 text-xs font-bold text-signal-700 transition-colors hover:bg-signal-100"
          >
            <Building2 className="h-3.5 w-3.5 text-signal-600" aria-hidden="true" />
            <span>{locale === 'ta' ? 'இலவச பதிவு' : 'Free Listing'}</span>
            <span className="rounded bg-signal-600 px-1.5 py-0.5 text-[9px] font-extrabold uppercase tracking-wide text-white">
              FREE
            </span>
          </Link>

          {/* Auth actions */}
          {user ? (
            <div className="flex items-center gap-2">
              <Link
                href="/dashboard/settings"
                className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-ink hover:bg-gray-50"
              >
                <UserIcon className="h-3.5 w-3.5 text-brand-600" aria-hidden="true" />
                <span className="max-w-[110px] truncate">
                  {user.name ?? user.phone_e164 ?? 'Account'}
                </span>
              </Link>
              <button
                type="button"
                onClick={handleLogout}
                disabled={loggingOut}
                className="inline-flex items-center gap-1 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
              >
                <LogOut className="h-3.5 w-3.5" aria-hidden="true" />
                {loggingOut ? '...' : 'Sign out'}
              </button>
            </div>
          ) : (
            <Link
              href="/login"
              className="rounded-lg bg-brand-600 px-4 py-2 text-xs font-bold text-white shadow-sm transition-colors hover:bg-brand-700"
            >
              {locale === 'ta' ? 'உள்நுழைக' : 'Login / Sign Up'}
            </Link>
          )}
        </div>

        {/* Mobile Right Controls */}
        <div className="flex items-center gap-2 md:hidden">
          <button
            type="button"
            onClick={() => handleToggleLocale(locale === 'en' ? 'ta' : 'en')}
            aria-label="Switch language"
            className="rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1.5 text-xs font-bold text-brand-700"
          >
            {locale === 'en' ? 'தமிழ்' : 'EN'}
          </button>

          <button
            type="button"
            onClick={() => setMobileOpen((prev) => !prev)}
            aria-expanded={mobileOpen}
            aria-label="Toggle navigation menu"
            className="inline-flex items-center justify-center rounded-lg border border-gray-200 p-2 text-ink"
          >
            {mobileOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
          </button>
        </div>
      </div>

      {/* Inline geolocation alert bar on desktop if user clicked direct Near Me and denied */}
      {geoNotice && !cityMenuOpen && !mobileOpen && (
        <div
          role="alert"
          className="border-t border-rose-200 bg-rose-50 px-4 py-2 text-center text-xs font-medium text-rose-800"
        >
          {geoNotice}
        </div>
      )}

      {/* Mobile Slide-Down Drawer */}
      {mobileOpen && (
        <div className="border-t border-gray-200 bg-white px-4 py-4 shadow-lg md:hidden">
          <div className="mb-3 space-y-2">
            <label htmlFor="mobile-city-select" className="block text-xs font-semibold text-ink-muted">
              {locale === 'ta' ? 'நகரத்தைத் தேர்ந்தெடுக்கவும்' : 'Active City'}
            </label>
            <div className="flex gap-2">
              <select
                id="mobile-city-select"
                value={activeCitySlug}
                onChange={(e) => handleSelectCity(e.target.value)}
                className="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-ink"
              >
                {cities.map((city) => (
                  <option key={city.slug} value={city.slug}>
                    {city.name}
                  </option>
                ))}
              </select>
              <button
                type="button"
                onClick={handleNearMe}
                disabled={locating}
                className="inline-flex items-center gap-1 rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 text-xs font-semibold text-brand-700"
              >
                <Crosshair className="h-4 w-4 text-signal-600" aria-hidden="true" />
                Near Me
              </button>
            </div>
            {geoNotice && (
              <p role="alert" className="rounded-md bg-rose-50 px-2.5 py-1.5 text-xs text-rose-700">
                {geoNotice}
              </p>
            )}
          </div>

          <nav aria-label="Mobile navigation" className="flex flex-col gap-1.5 border-t border-gray-100 pt-3">
            <Link
              href={buildShareableSearchUrl({ city: activeCitySlug, locale })}
              onClick={() => setMobileOpen(false)}
              className="rounded-lg px-3 py-2 text-sm font-medium text-ink hover:bg-gray-100"
            >
              {locale === 'ta' ? 'வணிகங்களைத் தேடுங்கள்' : `Explore ${activeCity?.name ?? 'Local'} Listings`}
            </Link>
            <Link
              href="/dashboard"
              onClick={() => setMobileOpen(false)}
              className="flex items-center justify-between rounded-lg bg-signal-50 px-3 py-2 text-sm font-bold text-signal-700"
            >
              <span>{locale === 'ta' ? 'இலவச வணிகப் பதிவு' : 'Free Business Listing'}</span>
              <span className="rounded bg-signal-600 px-1.5 py-0.5 text-[10px] text-white">FREE</span>
            </Link>

            {user ? (
              <>
                <Link
                  href="/dashboard/settings"
                  onClick={() => setMobileOpen(false)}
                  className="rounded-lg px-3 py-2 text-sm font-medium text-ink hover:bg-gray-100"
                >
                  Account &amp; Devices ({user.name ?? user.phone_e164})
                </Link>
                <button
                  type="button"
                  onClick={handleLogout}
                  disabled={loggingOut}
                  className="mt-1 rounded-lg bg-brand-600 px-3 py-2.5 text-center text-sm font-semibold text-white"
                >
                  {loggingOut ? 'Signing out...' : 'Sign out'}
                </button>
              </>
            ) : (
              <Link
                href="/login"
                onClick={() => setMobileOpen(false)}
                className="mt-1 rounded-lg bg-brand-600 px-3 py-2.5 text-center text-sm font-semibold text-white"
              >
                {locale === 'ta' ? 'OTP மூலம் உள்நுழைக' : 'Login / Sign Up with OTP'}
              </Link>
            )}
          </nav>
        </div>
      )}
    </header>
  );
}
