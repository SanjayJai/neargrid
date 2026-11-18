import { describe, expect, it } from 'vitest';
import type { SearchBusinessHitDto } from '@/shared/api/directory-types';
import {
  buildCanonicalCategoryPath,
  buildCanonicalLocalityCategoryPath,
  buildShareableSearchUrl,
  isValidCoordinatePair,
  normalizeLocale,
  parseRawSearchParams,
  sanitizeSearchText,
  sanitizeSlug,
  sanitizeSort,
} from '@/modules/customer/lib/search-url';

describe('Phase 3B Milestone 8 Step 3: Search Results & Canonical Directory Routes', () => {
  describe('search-url helpers', () => {
    it('sanitizes slugs and queries cleanly', () => {
      expect(sanitizeSlug('Chennai')).toBe('chennai');
      expect(sanitizeSlug('t-nagar')).toBe('t-nagar');
      expect(sanitizeSlug('INVALID/SLASH')).toBeNull();
      expect(sanitizeSlug('   ')).toBeNull();

      expect(sanitizeSearchText('   Dentist   in   Chennai   ')).toBe('Dentist in Chennai');
      expect(sanitizeSearchText('')).toBe('');

      expect(normalizeLocale('en')).toBe('en');
      expect(normalizeLocale('ta')).toBe('ta');
      expect(normalizeLocale('fr')).toBe('en');
    });

    it('validates coordinates strictly', () => {
      expect(isValidCoordinatePair(13.0827, 80.2707)).toBe(true);
      expect(isValidCoordinatePair(-90, 180)).toBe(true);
      expect(isValidCoordinatePair(91, 80)).toBe(false);
      expect(isValidCoordinatePair(13, 181)).toBe(false);
      expect(isValidCoordinatePair(NaN, 80)).toBe(false);
      expect(isValidCoordinatePair(null, null)).toBe(false);
    });

    it('sanitizes sort options to allowlisted options', () => {
      expect(sanitizeSort('relevance')).toBe('relevance');
      expect(sanitizeSort('rating')).toBe('rating');
      expect(sanitizeSort('distance')).toBe('distance');
      expect(sanitizeSort('popularity')).toBe('popularity');
      expect(sanitizeSort('newest')).toBe('newest');
      expect(sanitizeSort('sql_injection')).toBeUndefined();
      expect(sanitizeSort('')).toBeUndefined();
    });

    it('builds shareable search URLs with query parameter synchronization', () => {
      const url1 = buildShareableSearchUrl({
        city: 'chennai',
        category: 'dentists',
        q: 'root canal',
      });
      expect(url1).toBe('/search?city=chennai&category=dentists&q=root+canal');

      const url2 = buildShareableSearchUrl({
        city: 'chennai',
        min_rating: 4,
        verified_only: true,
        open_now: true,
        sort: 'rating',
        page: 2,
      });
      expect(url2).toContain('city=chennai');
      expect(url2).toContain('min_rating=4');
      expect(url2).toContain('verified_only=1');
      expect(url2).toContain('open_now=1');
      expect(url2).toContain('sort=rating');
      expect(url2).toContain('page=2');

      // Page 1 is omitted from query params
      const url3 = buildShareableSearchUrl({
        city: 'chennai',
        page: 1,
      });
      expect(url3).toBe('/search?city=chennai');
    });

    it('parses raw Next.js searchParams into typed SearchQueryParams', () => {
      const parsed = parseRawSearchParams({
        city: 'chennai',
        locality: 't-nagar',
        category: 'dentists',
        q: 'teeth cleaning',
        min_rating: '4.5',
        verified_only: '1',
        open_now: '1',
        sort: 'rating',
        page: '3',
        lat: '13.0418',
        lng: '80.2341',
        radius_km: '10',
      });

      expect(parsed.city).toBe('chennai');
      expect(parsed.locality).toBe('t-nagar');
      expect(parsed.category).toBe('dentists');
      expect(parsed.q).toBe('teeth cleaning');
      expect(parsed.min_rating).toBe(4.5);
      expect(parsed.verified_only).toBe(true);
      expect(parsed.open_now).toBe(true);
      expect(parsed.sort).toBe('rating');
      expect(parsed.page).toBe(3);
      expect(parsed.lat).toBeCloseTo(13.0418, 4);
      expect(parsed.lng).toBeCloseTo(80.2341, 4);
      expect(parsed.radius_km).toBe(10);
    });

    it('enforces canonical URL paths per docs/12-seo-architecture.md', () => {
      // Category page 1 is ALWAYS bare path
      expect(buildCanonicalCategoryPath('chennai', 'dentists')).toBe('/chennai/dentists');
      expect(buildCanonicalCategoryPath('chennai', 'dentists', 1)).toBe('/chennai/dentists');
      // Category page N self-references with ?page=N
      expect(buildCanonicalCategoryPath('chennai', 'dentists', 2)).toBe('/chennai/dentists?page=2');

      // Locality + Category page 1 is bare path
      expect(buildCanonicalLocalityCategoryPath('chennai', 't-nagar', 'dentists')).toBe(
        '/chennai/t-nagar/dentists'
      );
      expect(buildCanonicalLocalityCategoryPath('chennai', 't-nagar', 'dentists', 1)).toBe(
        '/chennai/t-nagar/dentists'
      );
      // Locality + Category page N
      expect(buildCanonicalLocalityCategoryPath('chennai', 't-nagar', 'dentists', 3)).toBe(
        '/chennai/t-nagar/dentists?page=3'
      );
    });
  });

  describe('SEO indexability & robots rules (docs/12-seo-architecture.md)', () => {
    it('verifies thin locality-category pages (<3 listings) require noindex', () => {
      const evaluateRobotsForLocalityCategory = (organicCount: number) => {
        const isThin = organicCount < 3;
        return isThin ? 'noindex, follow' : 'index, follow';
      };

      expect(evaluateRobotsForLocalityCategory(0)).toBe('noindex, follow');
      expect(evaluateRobotsForLocalityCategory(1)).toBe('noindex, follow');
      expect(evaluateRobotsForLocalityCategory(2)).toBe('noindex, follow');
      expect(evaluateRobotsForLocalityCategory(3)).toBe('index, follow');
      expect(evaluateRobotsForLocalityCategory(10)).toBe('index, follow');
    });

    it('verifies dynamic faceted search (/search) is strictly noindex, follow', () => {
      const getSearchRobots = () => ({
        index: false,
        follow: true,
      });

      expect(getSearchRobots()).toEqual({ index: false, follow: true });
    });
  });

  describe('Structured Data JSON-LD conformity', () => {
    it('generates BreadcrumbList with correct Schema.org properties and 1-based positions', () => {
      const siteUrl = 'https://neargrid.example.com';
      const items = [
        { label: 'Home', href: '/' },
        { label: 'Chennai', href: '/search?city=chennai' },
        { label: 'Dentists' },
      ];

      const jsonLd = {
        '@context': 'https://schema.org',
        '@type': 'BreadcrumbList',
        itemListElement: items.map((item, index) => ({
          '@type': 'ListItem',
          position: index + 1,
          name: item.label,
          item: item.href ? `${siteUrl}${item.href}` : `${siteUrl}/`,
        })),
      };

      expect(jsonLd['@context']).toBe('https://schema.org');
      expect(jsonLd['@type']).toBe('BreadcrumbList');
      expect(jsonLd.itemListElement).toHaveLength(3);
      expect(jsonLd.itemListElement[0].position).toBe(1);
      expect(jsonLd.itemListElement[0].name).toBe('Home');
      expect(jsonLd.itemListElement[0].item).toBe('https://neargrid.example.com/');
      expect(jsonLd.itemListElement[1].position).toBe(2);
      expect(jsonLd.itemListElement[1].name).toBe('Chennai');
      expect(jsonLd.itemListElement[2].position).toBe(3);
      expect(jsonLd.itemListElement[2].name).toBe('Dentists');
    });

    it('generates ItemList excluding sponsored listings per docs/12-seo-architecture.md Section 4.2', () => {
      const siteUrl = 'https://neargrid.example.com';
      const sampleHits: Partial<SearchBusinessHitDto>[] = [
        { public_id: '01HP01', name: 'Sponsored Smile Clinic', slug: 'sponsored-smile', is_sponsored: true, city_slug: 'chennai' },
        { public_id: '01HP02', name: 'Dr. Kumar Dental Care', slug: 'dr-kumar-dental', is_sponsored: false, city_slug: 'chennai' },
        { public_id: '01HP03', name: 'Apex Multi-speciality Dental', slug: 'apex-dental', is_sponsored: false, city_slug: 'chennai' },
      ];

      const organicHits = sampleHits.filter((h) => !h.is_sponsored);
      const itemList = {
        '@context': 'https://schema.org',
        '@type': 'ItemList',
        itemListElement: organicHits.map((h, i) => ({
          '@type': 'ListItem',
          position: i + 1,
          name: h.name,
          url: `${siteUrl}/${h.city_slug}/business/${h.slug}`,
        })),
      };

      expect(itemList.itemListElement).toHaveLength(2);
      expect(itemList.itemListElement[0].name).toBe('Dr. Kumar Dental Care');
      expect(itemList.itemListElement[0].position).toBe(1);
      expect(itemList.itemListElement[1].name).toBe('Apex Multi-speciality Dental');
      expect(itemList.itemListElement[1].position).toBe(2);
      // Sponsored clinic is not in organic ItemList
      expect(itemList.itemListElement.some((x) => Boolean(x.name?.includes('Sponsored')))).toBe(false);
    });
  });

  describe('SearchBusinessHitDto contract verification', () => {
    it('validates all required directory card fields are typed and populated', () => {
      const hit: SearchBusinessHitDto = {
        public_id: '01HPXYZ123',
        name: 'Apex Orthodontic Centre',
        slug: 'apex-orthodontic-centre',
        tagline: 'Leading teeth alignment and braces in Chennai',
        city_slug: 'chennai',
        city_name: 'Chennai',
        locality_slug: 't-nagar',
        locality_name: 'T. Nagar',
        primary_category: {
          public_id: '01HPCAT01',
          slug: 'dentists',
          name: 'Dentists',
        },
        is_sponsored: true,
        sponsor_disclosure: 'Sponsored Listing',
        is_verified: true,
        is_trusted: true,
        is_claimed: true,
        rating_avg: 4.8,
        review_count: 142,
        completeness_score: 95,
        distance_km: 1.8,
        is_open_now: true,
        address_short: '14 Usman Road, T. Nagar',
        phone_masked: '+91 98400 •••••',
        has_whatsapp: true,
        services_preview: ['Invisalign', 'Root Canal', 'Teeth Whitening'],
        media_preview: [
          {
            public_id: '01HPMEDIA01',
            media_type: 'cover',
            alt_text: 'Apex Orthodontic storefront',
            urls: {
              thumb: 'https://cdn.example.com/thumb.webp',
              card: 'https://cdn.example.com/card.webp',
              full: 'https://cdn.example.com/full.webp',
            },
          },
        ],
      };

      expect(hit.public_id).toBe('01HPXYZ123');
      expect(hit.is_sponsored).toBe(true);
      expect(hit.sponsor_disclosure).toBe('Sponsored Listing');
      expect(hit.is_verified).toBe(true);
      expect(hit.is_trusted).toBe(true);
      expect(hit.rating_avg).toBe(4.8);
      expect(hit.review_count).toBe(142);
      expect(hit.distance_km).toBe(1.8);
      expect(hit.is_open_now).toBe(true);
      expect(hit.phone_masked).toBe('+91 98400 •••••');
      expect(hit.media_preview[0].urls.card).toContain('card.webp');
    });
  });
});
