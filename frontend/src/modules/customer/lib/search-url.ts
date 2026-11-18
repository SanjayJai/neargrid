import type { SearchQueryParams, SearchSortOptionDto } from '@/shared/api/directory-types';

const SLUG_REGEX = /^[a-z0-9-]+$/;

export type SupportedLocale = 'en' | 'ta';

export function normalizeLocale(raw?: string | null): SupportedLocale {
  return raw === 'ta' ? 'ta' : 'en';
}

export function sanitizeSlug(raw?: string | null): string | null {
  if (!raw) return null;
  const trimmed = raw.trim().toLowerCase();
  if (trimmed.length === 0 || trimmed.length > 140 || !SLUG_REGEX.test(trimmed)) {
    return null;
  }
  return trimmed;
}

export function sanitizeSearchText(raw?: string | null): string {
  if (!raw) return '';
  return raw
    .replace(/[\x00-\x1F\x7F]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 120);
}

export function isValidCoordinatePair(lat?: number | null, lng?: number | null): boolean {
  if (typeof lat !== 'number' || typeof lng !== 'number') return false;
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) return false;
  return lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180;
}

const VALID_SORTS: ReadonlyArray<SearchSortOptionDto> = [
  'relevance',
  'rating',
  'distance',
  'popularity',
  'newest',
];

export function sanitizeSort(raw?: string | null): SearchSortOptionDto | undefined {
  if (!raw) return undefined;
  const candidate = raw.trim().toLowerCase() as SearchSortOptionDto;
  return VALID_SORTS.includes(candidate) ? candidate : undefined;
}

export interface ShareableSearchUrlInput {
  city?: string | null;
  locality?: string | null;
  localities?: string[] | null;
  category?: string | null;
  q?: string | null;
  lat?: number | null;
  lng?: number | null;
  radiusKm?: number | null;
  radius_km?: number | null;
  minRating?: number | null;
  min_rating?: number | null;
  verifiedOnly?: boolean | null;
  verified_only?: boolean | null;
  openNow?: boolean | null;
  open_now?: boolean | null;
  amenities?: string[] | null;
  paymentMethods?: string[] | null;
  payment_methods?: string[] | null;
  sort?: string | null;
  page?: number | null;
  perPage?: number | null;
  per_page?: number | null;
  locale?: SupportedLocale;
}

/**
 * Builds a clean, shareable, strictly validated `/search?...` URL.
 */
export function buildShareableSearchUrl(input: ShareableSearchUrlInput): string {
  const params = new URLSearchParams();

  const city = sanitizeSlug(input.city);
  const locality = sanitizeSlug(input.locality);
  const category = sanitizeSlug(input.category);
  const q = sanitizeSearchText(input.q);

  if (city) params.set('city', city);
  if (locality) params.set('locality', locality);
  if (category) params.set('category', category);
  if (q) params.set('q', q);

  if (input.localities && Array.isArray(input.localities)) {
    for (const loc of input.localities) {
      const sanitized = sanitizeSlug(loc);
      if (sanitized && sanitized !== locality) {
        params.append('localities[]', sanitized);
      }
    }
  }

  const lat = typeof input.lat === 'number' ? input.lat : undefined;
  const lng = typeof input.lng === 'number' ? input.lng : undefined;
  if (isValidCoordinatePair(lat, lng)) {
    params.set('lat', Number(lat).toFixed(6));
    params.set('lng', Number(lng).toFixed(6));
    const r = input.radiusKm ?? input.radius_km;
    if (typeof r === 'number' && r >= 0.5 && r <= 50) {
      params.set('radius_km', String(r));
    }
  }

  const minRating = input.minRating ?? input.min_rating;
  if (typeof minRating === 'number' && minRating >= 1 && minRating <= 5) {
    params.set('min_rating', String(minRating));
  }

  const verified = input.verifiedOnly ?? input.verified_only;
  if (verified) {
    params.set('verified_only', '1');
  }

  const openNow = input.openNow ?? input.open_now;
  if (openNow) {
    params.set('open_now', '1');
  }

  const amenities = input.amenities;
  if (amenities && Array.isArray(amenities)) {
    for (const a of amenities) {
      const s = sanitizeSlug(a);
      if (s) params.append('amenities[]', s);
    }
  }

  const paymentMethods = input.paymentMethods ?? input.payment_methods;
  if (paymentMethods && Array.isArray(paymentMethods)) {
    for (const p of paymentMethods) {
      const s = sanitizeSlug(p);
      if (s) params.append('payment_methods[]', s);
    }
  }

  const sort = sanitizeSort(input.sort);
  if (sort && sort !== 'relevance') {
    params.set('sort', sort);
  }

  if (typeof input.page === 'number' && input.page > 1) {
    params.set('page', String(input.page));
  }

  const perPage = input.perPage ?? input.per_page;
  if (typeof perPage === 'number' && perPage >= 1 && perPage <= 40 && perPage !== 20) {
    params.set('per_page', String(perPage));
  }

  if (input.locale && input.locale !== 'en') {
    params.set('locale', input.locale);
  }

  const query = params.toString();
  return query ? `/search?${query}` : '/search';
}

/**
 * Parses raw Next.js URL query params into typed SearchQueryParams.
 */
export function parseRawSearchParams(
  rawParams: Record<string, string | string[] | undefined>
): SearchQueryParams {
  const getSingle = (key: string): string | undefined => {
    const val = rawParams[key];
    if (Array.isArray(val)) return val[0];
    return val;
  };

  const getArray = (key: string): string[] => {
    const val = rawParams[key];
    if (!val) return [];
    if (Array.isArray(val)) return val.filter(Boolean);
    return [val].filter(Boolean);
  };

  const city = sanitizeSlug(getSingle('city')) ?? undefined;
  const category = sanitizeSlug(getSingle('category')) ?? undefined;
  const locality = sanitizeSlug(getSingle('locality')) ?? undefined;
  const q = sanitizeSearchText(getSingle('q')) || undefined;

  const rawLocalities = [...getArray('localities[]'), ...getArray('localities')];
  const localities = rawLocalities.map(sanitizeSlug).filter((x): x is string => Boolean(x));

  const latRaw = parseFloat(getSingle('lat') ?? '');
  const lngRaw = parseFloat(getSingle('lng') ?? '');
  const hasCoords = isValidCoordinatePair(latRaw, lngRaw);
  const lat = hasCoords ? latRaw : undefined;
  const lng = hasCoords ? lngRaw : undefined;

  const radiusRaw = parseFloat(getSingle('radius_km') ?? '');
  const radius_km = Number.isFinite(radiusRaw) && radiusRaw >= 0.5 && radiusRaw <= 50 ? radiusRaw : undefined;

  const minRatingRaw = parseFloat(getSingle('min_rating') ?? '');
  const min_rating =
    Number.isFinite(minRatingRaw) && minRatingRaw >= 1 && minRatingRaw <= 5 ? minRatingRaw : undefined;

  const verifiedRaw = getSingle('verified_only');
  const verified_only = verifiedRaw === '1' || verifiedRaw === 'true';

  const openNowRaw = getSingle('open_now');
  const open_now = openNowRaw === '1' || openNowRaw === 'true';

  const rawAmenities = [...getArray('amenities[]'), ...getArray('amenities')];
  const amenities = rawAmenities.map(sanitizeSlug).filter((x): x is string => Boolean(x));

  const rawPayments = [...getArray('payment_methods[]'), ...getArray('payment_methods')];
  const payment_methods = rawPayments.map(sanitizeSlug).filter((x): x is string => Boolean(x));

  const sort = sanitizeSort(getSingle('sort'));

  const pageRaw = parseInt(getSingle('page') ?? '1', 10);
  const page = Number.isFinite(pageRaw) && pageRaw >= 1 ? pageRaw : 1;

  const perPageRaw = parseInt(getSingle('per_page') ?? '20', 10);
  const per_page = Number.isFinite(perPageRaw) && perPageRaw >= 1 && perPageRaw <= 40 ? perPageRaw : 20;

  const locale = normalizeLocale(getSingle('locale'));

  return {
    city,
    category,
    locality,
    localities: localities.length > 0 ? localities : undefined,
    q,
    lat,
    lng,
    radius_km,
    min_rating,
    verified_only: verified_only || undefined,
    open_now: open_now || undefined,
    amenities: amenities.length > 0 ? amenities : undefined,
    payment_methods: payment_methods.length > 0 ? payment_methods : undefined,
    sort,
    page,
    per_page,
    locale,
  };
}

/**
 * Builds canonical category path per docs/12-seo-architecture.md.
 * Page 1 is always the bare path.
 */
export function buildCanonicalCategoryPath(
  citySlug: string,
  categorySlug: string,
  page = 1
): string {
  const cleanCity = sanitizeSlug(citySlug) ?? 'chennai';
  const cleanCat = sanitizeSlug(categorySlug) ?? 'all';
  if (page <= 1) {
    return `/${cleanCity}/${cleanCat}`;
  }
  return `/${cleanCity}/${cleanCat}?page=${page}`;
}

/**
 * Builds canonical locality + category path per docs/12-seo-architecture.md.
 * Page 1 is always the bare path.
 */
export function buildCanonicalLocalityCategoryPath(
  citySlug: string,
  localitySlug: string,
  categorySlug: string,
  page = 1
): string {
  const cleanCity = sanitizeSlug(citySlug) ?? 'chennai';
  const cleanLoc = sanitizeSlug(localitySlug) ?? 'all';
  const cleanCat = sanitizeSlug(categorySlug) ?? 'all';
  if (page <= 1) {
    return `/${cleanCity}/${cleanLoc}/${cleanCat}`;
  }
  return `/${cleanCity}/${cleanLoc}/${cleanCat}?page=${page}`;
}

/**
 * Converts URLSearchParams (preserving repeated array keys like localities[] / amenities[])
 * into the Record shape accepted by parseRawSearchParams.
 */
export function urlSearchParamsToRecord(
  params: Pick<URLSearchParams, 'forEach'>
): Record<string, string | string[]> {
  const record: Record<string, string | string[]> = {};
  params.forEach((value, key) => {
    const existing = record[key];
    if (existing === undefined) {
      record[key] = value;
    } else if (Array.isArray(existing)) {
      existing.push(value);
    } else {
      record[key] = [existing, value];
    }
  });
  return record;
}
