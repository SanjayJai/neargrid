import { describe, expect, it } from 'vitest';
import {
  isSafeCanonicalOrigin,
  resolveBusinessProfileHref,
  resolveCityPortalHref,
  sanitizeCrossDomainDestination,
} from '@/lib/domains/cross-domain-nav';
import type { PublicResolvedDomainDto } from '@/shared/api/directory-types';

describe('M-A2 M3 Step 3: Cross-Domain Navigation & Security', () => {
  it('validates canonical origins and rejects insecure or malformed origins', () => {
    expect(isSafeCanonicalOrigin('https://vellore.neargrid.in', 'production')).toBe(true);
    expect(isSafeCanonicalOrigin('http://vellore.neargrid.in', 'production')).toBe(false);
    expect(isSafeCanonicalOrigin('http://localhost:3000', 'development')).toBe(true);
    expect(isSafeCanonicalOrigin('https://user:pass@vellore.neargrid.in', 'production')).toBe(false);
    expect(isSafeCanonicalOrigin('https://vellore.neargrid.in/path', 'production')).toBe(false);
    expect(isSafeCanonicalOrigin('javascript:alert(1)', 'production')).toBe(false);
  });

  it('generates canonical business URLs when cross-domain and safe relative URLs otherwise', () => {
    expect(
      resolveBusinessProfileHref({
        citySlug: 'vellore',
        businessSlug: 'apollo-clinic',
        canonicalUrl: 'https://apollo.example.com/vellore/business/apollo-clinic',
        currentHost: 'vellore.neargrid.in',
        nodeEnv: 'production',
      })
    ).toBe('https://apollo.example.com/vellore/business/apollo-clinic');

    // Same host -> relative URL
    expect(
      resolveBusinessProfileHref({
        citySlug: 'vellore',
        businessSlug: 'apollo-clinic',
        canonicalUrl: 'https://vellore.neargrid.in/vellore/business/apollo-clinic',
        currentHost: 'vellore.neargrid.in',
        locale: 'ta',
        nodeEnv: 'production',
      })
    ).toBe('/vellore/business/apollo-clinic?locale=ta');

    // Malformed / unsafe canonical URL -> safe relative fallback
    expect(
      resolveBusinessProfileHref({
        citySlug: 'vellore',
        businessSlug: 'apollo-clinic',
        canonicalUrl: 'javascript:alert(1)',
        currentHost: 'vellore.neargrid.in',
        nodeEnv: 'production',
      })
    ).toBe('/vellore/business/apollo-clinic');
  });

  it('supports city-to-city navigation preserving search/category context without forcing login or hardcoding domains', () => {
    const vellorePortal: PublicResolvedDomainDto = {
      public_id: '01DOM000000000000000000001',
      hostname: 'vellore.neargrid.in',
      domain_type: 'city_portal',
      is_primary: false,
      visibility_mode: 'location_scoped',
      default_locale: 'en',
      default_city: { public_id: '01CIT1', slug: 'vellore', name: 'Vellore' },
      default_state: null,
      canonical_origin: 'https://neargrid.in',
      is_canonical_for_location: false,
      is_indexable: true,
    };

    const href = resolveCityPortalHref({
      targetCitySlug: 'chennai',
      currentDomain: vellorePortal,
      currentPath: '/search',
      queryString: 'category=dentists&open_now=1',
      nodeEnv: 'production',
    });

    expect(href).toBe('https://neargrid.in/search?category=dentists&open_now=1&city=chennai');
    expect(href).not.toContain('token');
    expect(href).not.toContain('Bearer');
  });

  it('prevents open redirects and blocks unregistered or suspended destination hosts', () => {
    const allowed = new Set(['https://neargrid.in', 'https://vellore.neargrid.in']);

    expect(sanitizeCrossDomainDestination('/search?city=chennai', allowed, 'production')).toBe(
      '/search?city=chennai'
    );
    expect(
      sanitizeCrossDomainDestination('https://vellore.neargrid.in/c/healthcare', allowed, 'production')
    ).toBe('https://vellore.neargrid.in/c/healthcare');

    // Open redirect & unallowlisted/suspended domain attempts -> null
    expect(sanitizeCrossDomainDestination('//evil.example.com/phish', allowed, 'production')).toBeNull();
    expect(
      sanitizeCrossDomainDestination('https://suspended.neargrid.in/search', allowed, 'production')
    ).toBeNull();
    expect(sanitizeCrossDomainDestination('javascript:alert(1)', allowed, 'production')).toBeNull();
    expect(sanitizeCrossDomainDestination('/dashboard\r\nX-Inject: 1', allowed, 'production')).toBeNull();
  });
});
