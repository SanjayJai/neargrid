'use client';

import { useRouter } from 'next/navigation';
import { FormEvent, KeyboardEvent, useEffect, useId, useMemo, useRef, useState } from 'react';
import {
  Building2,
  CheckCircle2,
  ChevronDown,
  Crosshair,
  FolderSearch,
  Loader2,
  MapPin,
  Search,
  Sparkles,
  X,
} from 'lucide-react';
import type { ApiEnvelope } from '@/lib/bff/types';
import type {
  ReferenceCityDto,
  ReferenceLocalityDto,
  SearchSuggestionsDto,
} from '@/shared/api/directory-types';
import {
  buildShareableSearchUrl,
  sanitizeSearchText,
  type SupportedLocale,
} from '@/modules/customer/lib/search-url';

interface LiveSearchBarProps {
  cities: ReferenceCityDto[];
  initialCitySlug: string;
  initialLocalities?: ReferenceLocalityDto[];
  initialLocalitySlug?: string;
  initialQuery?: string;
  locale?: SupportedLocale;
  variant?: 'hero' | 'compact';
}

interface FlatSuggestionItem {
  id: string;
  group: 'category' | 'service' | 'locality' | 'city' | 'business';
  label: string;
  sublabel?: string;
  verified?: boolean;
  href: string;
}

const DEBOUNCE_MS = 220;
const MIN_SUGGESTION_CHARS = 2;

export function LiveSearchBar({
  cities,
  initialCitySlug,
  initialLocalities = [],
  initialLocalitySlug = '',
  initialQuery = '',
  locale = 'en',
  variant = 'hero',
}: LiveSearchBarProps) {
  const router = useRouter();
  const listboxId = useId();
  const containerRef = useRef<HTMLDivElement>(null);

  const [citySlug, setCitySlug] = useState(initialCitySlug);
  const [localitySlug, setLocalitySlug] = useState(initialLocalitySlug);
  const [localities, setLocalities] = useState<ReferenceLocalityDto[]>(initialLocalities);
  const [loadingLocalities, setLoadingLocalities] = useState(false);

  const [locationMenuOpen, setLocationMenuOpen] = useState(false);
  const [locationFilter, setLocationFilter] = useState('');

  const [query, setQuery] = useState(initialQuery);
  const [suggestions, setSuggestions] = useState<SearchSuggestionsDto | null>(null);
  const [suggestionsOpen, setSuggestionsOpen] = useState(false);
  const [loadingSuggestions, setLoadingSuggestions] = useState(false);
  const [suggestionsError, setSuggestionsError] = useState<string | null>(null);
  const [activeIndex, setActiveIndex] = useState(-1);

  const [locatingNearMe, setLocatingNearMe] = useState(false);
  const [geoError, setGeoError] = useState<string | null>(null);

  // Sync props when route searchParams change
  useEffect(() => {
    setCitySlug(initialCitySlug);
  }, [initialCitySlug]);

  useEffect(() => {
    setLocalities(initialLocalities);
  }, [initialLocalities]);

  // Close popovers on outside click
  useEffect(() => {
    function handleOutsideClick(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setLocationMenuOpen(false);
        setSuggestionsOpen(false);
      }
    }
    document.addEventListener('mousedown', handleOutsideClick);
    return () => document.removeEventListener('mousedown', handleOutsideClick);
  }, []);

  // Load localities when user switches city in the location picker
  async function handleSelectCity(nextCitySlug: string) {
    setCitySlug(nextCitySlug);
    setLocalitySlug('');
    setLocationFilter('');
    setGeoError(null);

    setLoadingLocalities(true);
    try {
      const res = await fetch(
        `/api/public/locations/cities/${encodeURIComponent(nextCitySlug)}/localities?locale=${locale}`,
        { cache: 'no-store' }
      );
      if (res.ok) {
        const json = (await res.json()) as ApiEnvelope<ReferenceLocalityDto[]>;
        if (json.status === 'success') {
          setLocalities(json.data);
        }
      }
    } catch {
      setLocalities([]);
    } finally {
      setLoadingLocalities(false);
    }
  }

  // Debounced live autocomplete with AbortController to cancel obsolete in-flight requests
  useEffect(() => {
    const trimmed = sanitizeSearchText(query);
    if (trimmed.length < MIN_SUGGESTION_CHARS) {
      setSuggestions(null);
      setLoadingSuggestions(false);
      setSuggestionsError(null);
      setActiveIndex(-1);
      return;
    }

    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
      setLoadingSuggestions(true);
      setSuggestionsError(null);

      try {
        const qs = new URLSearchParams({
          q: trimmed,
          locale,
        });
        if (citySlug) qs.set('city', citySlug);

        const response = await fetch(`/api/public/search/suggestions?${qs.toString()}`, {
          method: 'GET',
          headers: { Accept: 'application/json' },
          signal: controller.signal,
          cache: 'no-store',
        });

        if (!response.ok) {
          throw new Error('Suggestions temporarily unavailable.');
        }

        const json = (await response.json()) as ApiEnvelope<SearchSuggestionsDto>;
        if (json.status === 'success') {
          setSuggestions(json.data);
          setActiveIndex(-1);
        } else {
          setSuggestionsError(json.error.message);
        }
      } catch (err) {
        if ((err as Error)?.name === 'AbortError') {
          return;
        }
        setSuggestions(null);
        setSuggestionsError('Suggestions unavailable — press Enter to search directly.');
      } finally {
        if (!controller.signal.aborted) {
          setLoadingSuggestions(false);
        }
      }
    }, DEBOUNCE_MS);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [query, citySlug, locale]);

  const activeCity = useMemo(
    () => cities.find((c) => c.slug === citySlug) ?? cities[0] ?? null,
    [cities, citySlug]
  );

  const activeLocality = useMemo(
    () => localities.find((l) => l.slug === localitySlug) ?? null,
    [localities, localitySlug]
  );

  const locationDisplayLabel = activeLocality
    ? `${activeLocality.name}, ${activeCity?.name ?? citySlug}`
    : (activeCity?.name ?? (locale === 'ta' ? 'நகரம்' : 'Select City'));

  const flatSuggestions = useMemo<FlatSuggestionItem[]>(() => {
    if (!suggestions) return [];
    const items: FlatSuggestionItem[] = [];

    for (const cat of suggestions.categories) {
      items.push({
        id: `cat-${cat.public_id}`,
        group: 'category',
        label: cat.name,
        sublabel: locale === 'ta' ? 'பிரிவு' : 'Category',
        href: buildShareableSearchUrl({ city: citySlug, locality: localitySlug, category: cat.slug, locale }),
      });
    }

    for (const svc of suggestions.services) {
      items.push({
        id: `svc-${svc.name}`,
        group: 'service',
        label: svc.name,
        sublabel: locale === 'ta' ? 'சேவை' : 'Service',
        href: buildShareableSearchUrl({ city: citySlug, locality: localitySlug, q: svc.name, locale }),
      });
    }

    for (const biz of suggestions.businesses) {
      const targetCity = biz.city_slug ?? citySlug;
      items.push({
        id: `biz-${biz.public_id}`,
        group: 'business',
        label: biz.name,
        sublabel: biz.locality_name ?? targetCity,
        verified: biz.is_verified,
        href: `/${encodeURIComponent(targetCity)}/business/${encodeURIComponent(biz.slug)}`,
      });
    }

    for (const loc of suggestions.localities) {
      items.push({
        id: `loc-${loc.slug}`,
        group: 'locality',
        label: loc.name,
        sublabel: loc.pincode ? `${loc.city_slug ?? citySlug} · ${loc.pincode}` : (loc.city_slug ?? citySlug),
        href: buildShareableSearchUrl({
          city: loc.city_slug ?? citySlug,
          locality: loc.slug,
          locale,
        }),
      });
    }

    for (const c of suggestions.cities) {
      items.push({
        id: `city-${c.slug}`,
        group: 'city',
        label: c.name,
        sublabel: locale === 'ta' ? 'நகரம்' : 'City',
        href: buildShareableSearchUrl({ city: c.slug, locale }),
      });
    }

    return items;
  }, [suggestions, citySlug, localitySlug, locale]);

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSuggestionsOpen(false);
    setLocationMenuOpen(false);
    const targetUrl = buildShareableSearchUrl({
      city: citySlug,
      locality: localitySlug,
      q: query,
      locale,
    });
    router.push(targetUrl);
  }

  function handleKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (!suggestionsOpen || flatSuggestions.length === 0) {
      if (event.key === 'Escape') {
        setSuggestionsOpen(false);
      }
      return;
    }

    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setActiveIndex((prev) => (prev + 1) % flatSuggestions.length);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActiveIndex((prev) => (prev <= 0 ? flatSuggestions.length - 1 : prev - 1));
    } else if (event.key === 'Enter' && activeIndex >= 0 && activeIndex < flatSuggestions.length) {
      event.preventDefault();
      const chosen = flatSuggestions[activeIndex];
      setSuggestionsOpen(false);
      router.push(chosen.href);
    } else if (event.key === 'Escape') {
      setSuggestionsOpen(false);
      setActiveIndex(-1);
    }
  }

  /**
   * Geolocation is ONLY requested after explicit user click on "Near Me".
   */
  function handleNearMeClick() {
    setGeoError(null);
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
      setGeoError(
        locale === 'ta'
          ? 'இந்த உலாவியில் இருப்பிட சேவை ஆதரிக்கப்படவில்லை.'
          : 'Geolocation is not supported in this browser.'
      );
      return;
    }

    setLocatingNearMe(true);
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setLocatingNearMe(false);
        setLocationMenuOpen(false);
        const url = buildShareableSearchUrl({
          q: query,
          lat: position.coords.latitude,
          lng: position.coords.longitude,
          radiusKm: 10,
          sort: 'distance',
          locale,
        });
        router.push(url);
      },
      (err) => {
        setLocatingNearMe(false);
        if (err.code === err.PERMISSION_DENIED) {
          setGeoError(
            locale === 'ta'
              ? 'இருப்பிட அனுமதி மறுக்கப்பட்டது. நகரத்தை நேரடியாகத் தேர்ந்தெடுக்கவும்.'
              : 'Location permission was denied. Please choose your city manually.'
          );
        } else {
          setGeoError(
            locale === 'ta'
              ? 'தற்போதைய இருப்பிடத்தைக் கண்டறிய முடியவில்லை.'
              : 'Unable to detect your location right now.'
          );
        }
      },
      { enableHighAccuracy: false, timeout: 8000, maximumAge: 60_000 }
    );
  }

  const filteredCities = useMemo(() => {
    const q = locationFilter.trim().toLowerCase();
    if (!q) return cities;
    return cities.filter(
      (c) => c.name.toLowerCase().includes(q) || c.name_en.toLowerCase().includes(q) || c.slug.includes(q)
    );
  }, [cities, locationFilter]);

  const filteredLocalities = useMemo(() => {
    const q = locationFilter.trim().toLowerCase();
    if (!q) return localities;
    return localities.filter(
      (l) => l.name.toLowerCase().includes(q) || (l.pincode ?? '').includes(q) || l.slug.includes(q)
    );
  }, [localities, locationFilter]);

  const isHero = variant === 'hero';

  return (
    <div ref={containerRef} className="relative w-full">
      <form
        onSubmit={handleSubmit}
        role="search"
        aria-label={locale === 'ta' ? 'உள்ளூர் வணிகங்களைத் தேடுங்கள்' : 'Search local businesses'}
        className={`flex flex-col rounded-xl border-2 border-brand-600/80 bg-white shadow-md transition-shadow focus-within:border-brand-700 focus-within:shadow-lg sm:flex-row sm:items-stretch ${
          isHero ? 'p-1.5' : 'p-1'
        }`}
      >
        {/* Field 1: City / Locality + Near Me trigger */}
        <div className="relative border-b border-gray-200 sm:w-64 sm:shrink-0 sm:border-b-0 sm:border-r">
          <button
            type="button"
            onClick={() => {
              setLocationMenuOpen((prev) => !prev);
              setSuggestionsOpen(false);
            }}
            aria-expanded={locationMenuOpen}
            aria-haspopup="dialog"
            className={`flex w-full items-center justify-between gap-2 rounded-lg px-3 text-left transition-colors hover:bg-gray-50 ${
              isHero ? 'h-12' : 'h-10'
            }`}
          >
            <span className="flex min-w-0 items-center gap-2">
              <MapPin className="h-4 w-4 shrink-0 text-signal-600" aria-hidden="true" />
              <span className="truncate text-sm font-semibold text-ink">
                {locationDisplayLabel}
              </span>
            </span>
            <ChevronDown className="h-4 w-4 shrink-0 text-ink-subtle" aria-hidden="true" />
          </button>

          {locationMenuOpen && (
            <div
              role="dialog"
              aria-label="Choose city or locality"
              className="absolute left-0 top-full z-50 mt-2 w-full min-w-[280px] rounded-xl border border-gray-200 bg-white p-3 shadow-xl sm:w-80"
            >
              {/* Explicit Near Me trigger (user-initiated only) */}
              <button
                type="button"
                onClick={handleNearMeClick}
                disabled={locatingNearMe}
                className="flex w-full items-center justify-center gap-2 rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 text-xs font-semibold text-brand-700 transition-colors hover:bg-brand-100 disabled:opacity-60"
              >
                {locatingNearMe ? (
                  <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                ) : (
                  <Crosshair className="h-3.5 w-3.5" aria-hidden="true" />
                )}
                <span>
                  {locatingNearMe
                    ? locale === 'ta'
                      ? 'இருப்பிடம் கண்டறியப்படுகிறது...'
                      : 'Detecting location...'
                    : locale === 'ta'
                      ? 'எனக்கு அருகிலுள்ளவற்றைக் கண்டறி (Near Me)'
                      : 'Detect Near Me (Use GPS)'}
                </span>
              </button>

              {geoError && (
                <p role="alert" className="mt-2 rounded-md bg-rose-50 px-2.5 py-1.5 text-xs text-rose-700">
                  {geoError}
                </p>
              )}

              <div className="mt-2.5">
                <label htmlFor="location-filter-input" className="sr-only">
                  Filter cities or localities
                </label>
                <input
                  id="location-filter-input"
                  type="text"
                  value={locationFilter}
                  onChange={(e) => setLocationFilter(e.target.value)}
                  placeholder={locale === 'ta' ? 'நகரம் அல்லது பகுதியைத் தேடுங்கள்...' : 'Filter city or locality...'}
                  className="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-xs text-ink placeholder:text-gray-400"
                />
              </div>

              <div className="mt-3 max-h-60 space-y-3 overflow-y-auto pr-1">
                <div>
                  <p className="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-ink-subtle">
                    {locale === 'ta' ? 'நகரங்கள்' : 'Cities'}
                  </p>
                  <div className="flex flex-wrap gap-1.5">
                    {filteredCities.map((c) => {
                      const selected = c.slug === citySlug;
                      return (
                        <button
                          key={c.slug}
                          type="button"
                          onClick={() => handleSelectCity(c.slug)}
                          className={`rounded-md px-2.5 py-1 text-xs font-medium transition-colors ${
                            selected
                              ? 'bg-brand-600 text-white'
                              : 'border border-gray-200 bg-gray-50 text-ink hover:border-brand-300 hover:bg-brand-50'
                          }`}
                        >
                          {c.name}
                        </button>
                      );
                    })}
                    {filteredCities.length === 0 && (
                      <span className="text-xs text-ink-subtle">No matching cities</span>
                    )}
                  </div>
                </div>

                <div className="border-t border-gray-100 pt-2.5">
                  <div className="mb-1.5 flex items-center justify-between">
                    <p className="text-[11px] font-bold uppercase tracking-wider text-ink-subtle">
                      {locale === 'ta'
                        ? `${activeCity?.name ?? ''} பகுதிகள்`
                        : `Localities in ${activeCity?.name ?? 'City'}`}
                    </p>
                    {localitySlug && (
                      <button
                        type="button"
                        onClick={() => setLocalitySlug('')}
                        className="text-[11px] font-medium text-brand-600 hover:underline"
                      >
                        All areas
                      </button>
                    )}
                  </div>

                  {loadingLocalities ? (
                    <p className="text-xs text-ink-subtle">Loading localities...</p>
                  ) : filteredLocalities.length === 0 ? (
                    <p className="text-xs text-ink-subtle">No localities listed.</p>
                  ) : (
                    <ul className="space-y-0.5">
                      {filteredLocalities.slice(0, 12).map((loc) => (
                        <li key={loc.slug}>
                          <button
                            type="button"
                            onClick={() => {
                              setLocalitySlug(loc.slug);
                              setLocationMenuOpen(false);
                            }}
                            className={`flex w-full items-center justify-between rounded-md px-2.5 py-1.5 text-left text-xs transition-colors ${
                              localitySlug === loc.slug
                                ? 'bg-brand-50 font-semibold text-brand-700'
                                : 'text-ink hover:bg-gray-50'
                            }`}
                          >
                            <span>{loc.name}</span>
                            {loc.pincode && (
                              <span className="text-[11px] text-ink-subtle">{loc.pincode}</span>
                            )}
                          </button>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              </div>
            </div>
          )}
        </div>

        {/* Field 2: Keyword / Category / Service / Business with Live Autocomplete */}
        <div className="relative flex flex-1 items-center">
          <Search className="ml-3 h-4 w-4 shrink-0 text-ink-subtle" aria-hidden="true" />
          <label htmlFor={`search-input-${listboxId}`} className="sr-only">
            Search businesses, categories, or services
          </label>
          <input
            id={`search-input-${listboxId}`}
            type="text"
            role="combobox"
            aria-autocomplete="list"
            aria-expanded={suggestionsOpen}
            aria-controls={listboxId}
            aria-activedescendant={
              activeIndex >= 0 && flatSuggestions[activeIndex]
                ? flatSuggestions[activeIndex].id
                : undefined
            }
            autoComplete="off"
            value={query}
            onFocus={() => {
              setLocationMenuOpen(false);
              if (sanitizeSearchText(query).length >= MIN_SUGGESTION_CHARS) {
                setSuggestionsOpen(true);
              }
            }}
            onChange={(e) => {
              setQuery(e.target.value);
              setSuggestionsOpen(sanitizeSearchText(e.target.value).length >= MIN_SUGGESTION_CHARS);
            }}
            onKeyDown={handleKeyDown}
            placeholder={
              locale === 'ta'
                ? 'பிளம்பர், மருத்துவமனை, உணவகம், பல் மருத்துவர்...'
                : 'Search Plumbers, Hospitals, உணவகம், Dentists, AC Repair...'
            }
            className={`w-full bg-transparent px-3 text-sm text-ink placeholder:text-gray-400 focus:outline-none ${
              isHero ? 'h-12' : 'h-10'
            }`}
          />

          {loadingSuggestions && (
            <Loader2
              className="mr-2 h-4 w-4 shrink-0 animate-spin text-brand-600"
              aria-hidden="true"
            />
          )}

          {query && (
            <button
              type="button"
              onClick={() => {
                setQuery('');
                setSuggestions(null);
                setSuggestionsOpen(false);
              }}
              aria-label="Clear search query"
              className="mr-1 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-ink"
            >
              <X className="h-4 w-4" aria-hidden="true" />
            </button>
          )}

          {/* Live Autocomplete Dropdown */}
          {suggestionsOpen && sanitizeSearchText(query).length >= MIN_SUGGESTION_CHARS && (
            <div
              id={listboxId}
              role="listbox"
              aria-label="Search suggestions"
              className="absolute left-0 right-0 top-full z-50 mt-2 max-h-96 overflow-y-auto rounded-xl border border-gray-200 bg-white py-2 shadow-xl"
            >
              {loadingSuggestions && flatSuggestions.length === 0 && (
                <div className="flex items-center gap-2 px-4 py-3 text-xs text-ink-subtle">
                  <Loader2 className="h-3.5 w-3.5 animate-spin text-brand-600" aria-hidden="true" />
                  Searching NearGrid directory...
                </div>
              )}

              {suggestionsError && (
                <p className="px-4 py-2.5 text-xs text-amber-800">{suggestionsError}</p>
              )}

              {!loadingSuggestions && !suggestionsError && flatSuggestions.length === 0 && (
                <div className="px-4 py-3 text-xs text-ink-subtle">
                  No instant matches for &ldquo;{query.trim()}&rdquo;. Press <strong>Search</strong>{' '}
                  to run a full directory search.
                </div>
              )}

              {flatSuggestions.map((item, idx) => {
                const isActive = idx === activeIndex;
                const Icon =
                  item.group === 'category'
                    ? FolderSearch
                    : item.group === 'service'
                      ? Sparkles
                      : item.group === 'business'
                        ? Building2
                        : MapPin;

                return (
                  <button
                    key={item.id}
                    id={item.id}
                    type="button"
                    role="option"
                    aria-selected={isActive}
                    onMouseEnter={() => setActiveIndex(idx)}
                    onClick={() => {
                      setSuggestionsOpen(false);
                      router.push(item.href);
                    }}
                    className={`flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left transition-colors ${
                      isActive ? 'bg-brand-50 text-brand-800' : 'text-ink hover:bg-gray-50'
                    }`}
                  >
                    <span className="flex min-w-0 items-center gap-2.5">
                      <Icon className="h-4 w-4 shrink-0 text-brand-600" aria-hidden="true" />
                      <span className="min-w-0">
                        <span className="flex items-center gap-1.5 truncate text-sm font-medium">
                          {item.label}
                          {item.verified && (
                            <CheckCircle2
                              className="h-3.5 w-3.5 shrink-0 text-sky-600"
                              aria-label="Verified"
                            />
                          )}
                        </span>
                        {item.sublabel && (
                          <span className="block truncate text-xs text-ink-subtle">
                            {item.sublabel}
                          </span>
                        )}
                      </span>
                    </span>
                    <span className="shrink-0 rounded bg-gray-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-ink-subtle">
                      {item.group}
                    </span>
                  </button>
                );
              })}
            </div>
          )}
        </div>

        {/* Submit Button (Signal Orange directory action) */}
        <button
          type="submit"
          className={`mt-1.5 inline-flex items-center justify-center gap-2 rounded-lg bg-signal-600 px-6 text-sm font-bold text-white shadow-sm transition-colors hover:bg-signal-700 sm:ml-1.5 sm:mt-0 ${
            isHero ? 'h-12' : 'h-10 px-4'
          }`}
        >
          <Search className="h-4 w-4" aria-hidden="true" />
          <span>{locale === 'ta' ? 'தேடு' : 'Search'}</span>
        </button>
      </form>
    </div>
  );
}
