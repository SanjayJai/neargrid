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

export interface ShareableSearchUrlInput {
  city?: string | null;
  locality?: string | null;
  category?: string | null;
  q?: string | null;
  lat?: number | null;
  lng?: number | null;
  radiusKm?: number | null;
  sort?: string | null;
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

  if (isValidCoordinatePair(input.lat, input.lng)) {
    params.set('lat', Number(input.lat).toFixed(6));
    params.set('lng', Number(input.lng).toFixed(6));
    if (typeof input.radiusKm === 'number' && input.radiusKm >= 0.5 && input.radiusKm <= 50) {
      params.set('radius_km', String(input.radiusKm));
    }
  }

  if (input.sort && ['relevance', 'rating', 'distance', 'popularity', 'newest'].includes(input.sort)) {
    params.set('sort', input.sort);
  }

  if (input.locale && input.locale !== 'en') {
    params.set('locale', input.locale);
  }

  const query = params.toString();
  return query ? `/search?${query}` : '/search';
}
