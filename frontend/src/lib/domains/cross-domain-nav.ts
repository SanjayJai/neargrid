import { normalizeRequestHostname } from '@/lib/domains/host-resolver';
import type { PublicResolvedDomainDto } from '@/shared/api/directory-types';

/**
 * Validates that a canonical origin URL is syntactically valid, uses HTTPS
 * (or HTTP in non-production), carries no userinfo/path/query/fragment,
 * and passes strict hostname normalization.
 */
export function isSafeCanonicalOrigin(
  rawOrigin: string | null | undefined,
  nodeEnv: string | undefined = process.env.NODE_ENV
): boolean {
  if (!rawOrigin) return false;

  try {
    const parsed = new URL(rawOrigin);
    if (parsed.username || parsed.password) return false;
    if (parsed.pathname !== '/' && parsed.pathname !== '') return false;
    if (parsed.search || parsed.hash) return false;

    const allowedProtocol =
      nodeEnv === 'production'
        ? parsed.protocol === 'https:'
        : parsed.protocol === 'https:' || parsed.protocol === 'http:';
    if (!allowedProtocol) return false;

    return normalizeRequestHostname(parsed.host) !== null;
  } catch {
    return false;
  }
}

/**
 * Resolves a business profile link using the backend's authoritative canonical_url
 * when safe and cross-domain, or a relative path on the current domain.
 */
export function resolveBusinessProfileHref(params: {
  citySlug: string;
  businessSlug: string;
  canonicalUrl?: string | null;
  currentHost?: string | null;
  locale?: 'en' | 'ta';
  nodeEnv?: string;
}): string {
  const relativePath = `/${encodeURIComponent(params.citySlug)}/business/${encodeURIComponent(params.businessSlug)}`;
  const suffix = params.locale === 'ta' ? '?locale=ta' : '';

  if (params.canonicalUrl) {
    try {
      const parsed = new URL(params.canonicalUrl);
      if (isSafeCanonicalOrigin(parsed.origin, params.nodeEnv)) {
        const canonicalHost = normalizeRequestHostname(parsed.host);
        const activeHost = normalizeRequestHostname(params.currentHost);
        if (canonicalHost && activeHost && canonicalHost !== activeHost) {
          return `${parsed.origin}${relativePath}${suffix}`;
        }
      }
    } catch {
      // Fall back to safe relative path on malformed canonicalUrl
    }
  }

  return `${relativePath}${suffix}`;
}

/**
 * Builds a city-to-city navigation URL preserving search/category context
 * without trusting browser-supplied destination hostnames.
 */
export function resolveCityPortalHref(params: {
  targetCitySlug: string;
  currentDomain: PublicResolvedDomainDto | null;
  currentPath?: string;
  queryString?: string;
  nodeEnv?: string;
}): string {
  const qs = new URLSearchParams(params.queryString ?? '');
  qs.set('city', params.targetCitySlug);

  const basePath =
    params.currentPath && params.currentPath.startsWith('/') && !params.currentPath.startsWith('//')
      ? params.currentPath
      : '/search';
  const relativeTarget = `${basePath}?${qs.toString()}`;

  // If currently on a location-scoped city portal for a DIFFERENT city,
  // navigate via primary origin when safe & available, else stay relative (?city=).
  if (
    params.currentDomain &&
    params.currentDomain.domain_type === 'city_portal' &&
    params.currentDomain.default_city &&
    params.currentDomain.default_city.slug !== params.targetCitySlug &&
    isSafeCanonicalOrigin(params.currentDomain.canonical_origin, params.nodeEnv)
  ) {
    const targetOrigin = params.currentDomain.canonical_origin.replace(/\/+$/, '');
    const currentHost = params.currentDomain.hostname;
    try {
      const originHost = normalizeRequestHostname(new URL(targetOrigin).host);
      if (originHost && originHost !== currentHost) {
        return `${targetOrigin}${relativeTarget}`;
      }
    } catch {
      // Fallback to relative path
    }
  }

  return relativeTarget;
}

/**
 * Guards against open redirects and unregistered/suspended destination hosts.
 * Permits only relative internal paths (not //) or origins explicitly present
 * in the server-validated allowedOrigins set.
 */
export function sanitizeCrossDomainDestination(
  rawDestination: string | null | undefined,
  allowedOrigins: ReadonlySet<string>,
  nodeEnv: string | undefined = process.env.NODE_ENV
): string | null {
  if (!rawDestination) return null;
  const trimmed = rawDestination.trim();
  if (!trimmed || /[\u0000-\u001f\u007f\\]/.test(trimmed)) {
    return null;
  }

  if (trimmed.startsWith('/')) {
    if (trimmed.startsWith('//')) {
      return null;
    }
    return trimmed;
  }

  try {
    const parsed = new URL(trimmed);
    if (!isSafeCanonicalOrigin(parsed.origin, nodeEnv)) {
      return null;
    }
    const normalizedOrigin = parsed.origin.toLowerCase();
    if (!allowedOrigins.has(normalizedOrigin)) {
      return null;
    }
    return `${normalizedOrigin}${parsed.pathname}${parsed.search}${parsed.hash}`;
  } catch {
    return null;
  }
}
