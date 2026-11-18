import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import type { SearchBusinessHitDto } from '@/shared/api/directory-types';
import { buildBreadcrumbListJsonLd } from '@/shared/ui/Breadcrumbs';
import { SUPPORTED_SORT_OPTIONS } from '@/modules/customer/components/QuickFilterBar';
import {
  buildOrganicItemListJsonLd,
  evaluateCanonicalDirectorySeo,
  resolveCategoryDisplayName,
} from '@/modules/customer/lib/directory-seo';
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
  urlSearchParamsToRecord,
} from '@/modules/customer/lib/search-url';

const FRONTEND_SRC = path.resolve(__dirname, '../../..');

describe('Phase 3B Milestone 8 Step 3: Search Results & Canonical Directory Routes', () => {
  describe('search-url helpers & sorting contract', () => {
    it('maps all five frontend sort options to supported Laravel SearchSortOption values', () => {
      const laravelSortValues = ['relevance', 'rating', 'distance', 'popularity', 'newest'];
      expect(SUPPORTED_SORT_OPTIONS.map((o) => o.value)).toEqual(laravelSortValues);

      for (const val of laravelSortValues) {
        expect(sanitizeSort(val)).toBe(val);
      }
      expect(sanitizeSort('sql_injection')).toBeUndefined();
      expect(sanitizeSort('price_asc')).toBeUndefined();
      expect(sanitizeSort('')).toBeUndefined();
    });

    it('sanitizes slugs, queries, locales, and coordinates strictly', () => {
      expect(sanitizeSlug('Chennai')).toBe('chennai');
      expect(sanitizeSlug('t-nagar')).toBe('t-nagar');
      expect(sanitizeSlug('INVALID/SLASH')).toBeNull();
      expect(sanitizeSlug('   ')).toBeNull();

      expect(sanitizeSearchText('   Dentist   in   Chennai   ')).toBe('Dentist in Chennai');
      expect(sanitizeSearchText('')).toBe('');

      expect(normalizeLocale('en')).toBe('en');
      expect(normalizeLocale('ta')).toBe('ta');
      expect(normalizeLocale('fr')).toBe('en');

      expect(isValidCoordinatePair(13.0827, 80.2707)).toBe(true);
      expect(isValidCoordinatePair(-90, 180)).toBe(true);
      expect(isValidCoordinatePair(91, 80)).toBe(false);
      expect(isValidCoordinatePair(13, 181)).toBe(false);
      expect(isValidCoordinatePair(NaN, 80)).toBe(false);
      expect(isValidCoordinatePair(null, null)).toBe(false);
    });

    it('builds shareable search URLs with query parameter synchronization and clamps radius_km to 50km', () => {
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
      expect(buildShareableSearchUrl({ city: 'chennai', page: 1 })).toBe('/search?city=chennai');

      // Radius > 50 km rejected to match Laravel SearchBusinessesRequest max:50
      const urlOverRadius = buildShareableSearchUrl({
        city: 'chennai',
        lat: 13.0827,
        lng: 80.2707,
        radius_km: 80,
      });
      expect(urlOverRadius).not.toContain('radius_km=');
    });

    it('parses raw Next.js searchParams and preserves repeated array keys via urlSearchParamsToRecord', () => {
      const usp = new URLSearchParams(
        'city=chennai&localities[]=t-nagar&localities[]=adyar&amenities[]=parking&amenities[]=wifi&min_rating=4.5&verified_only=1&open_now=1&sort=rating&page=3&lat=13.0418&lng=80.2341&radius_km=10'
      );
      const parsed = parseRawSearchParams(urlSearchParamsToRecord(usp));

      expect(parsed.city).toBe('chennai');
      expect(parsed.localities).toEqual(['t-nagar', 'adyar']);
      expect(parsed.amenities).toEqual(['parking', 'wifi']);
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
      expect(buildCanonicalCategoryPath('chennai', 'dentists')).toBe('/chennai/dentists');
      expect(buildCanonicalCategoryPath('chennai', 'dentists', 1)).toBe('/chennai/dentists');
      expect(buildCanonicalCategoryPath('chennai', 'dentists', 2)).toBe('/chennai/dentists?page=2');

      expect(buildCanonicalLocalityCategoryPath('chennai', 't-nagar', 'dentists')).toBe(
        '/chennai/t-nagar/dentists'
      );
      expect(buildCanonicalLocalityCategoryPath('chennai', 't-nagar', 'dentists', 1)).toBe(
        '/chennai/t-nagar/dentists'
      );
      expect(buildCanonicalLocalityCategoryPath('chennai', 't-nagar', 'dentists', 3)).toBe(
        '/chennai/t-nagar/dentists?page=3'
      );
    });
  });

  describe('evaluateCanonicalDirectorySeo (docs/12-seo-architecture.md)', () => {
    const siteUrl = 'https://neargrid.example.com';

    it('indexes clean city+category pages with >=1 organic listing and normalizes Page 1 vs Page N canonicals', () => {
      const p1 = evaluateCanonicalDirectorySeo({
        pageKind: 'city_category',
        citySlug: 'chennai',
        categorySlug: 'dentists',
        params: { page: 1 },
        apiOk: true,
        totalOrganic: 5,
        siteUrl,
      });
      expect(p1.isIndexable).toBe(true);
      expect(p1.robots).toEqual({ index: true, follow: true });
      expect(p1.canonicalUrl).toBe('https://neargrid.example.com/chennai/dentists');

      const p2 = evaluateCanonicalDirectorySeo({
        pageKind: 'city_category',
        citySlug: 'chennai',
        categorySlug: 'dentists',
        params: { page: 2 },
        apiOk: true,
        totalOrganic: 25,
        siteUrl,
      });
      expect(p2.isIndexable).toBe(true);
      expect(p2.canonicalUrl).toBe('https://neargrid.example.com/chennai/dentists?page=2');

      const p51 = evaluateCanonicalDirectorySeo({
        pageKind: 'city_category',
        citySlug: 'chennai',
        categorySlug: 'dentists',
        params: { page: 51 },
        apiOk: true,
        totalOrganic: 1200,
        siteUrl,
      });
      expect(p51.isIndexable).toBe(false);
      expect(p51.noindexReason).toBe('pagination_exceeds_index_limit');
      expect(p51.canonicalUrl).toBe('https://neargrid.example.com/chennai/dentists');
    });

    it('enforces noindex on thin (<3 listings) locality+category pages and indexes >=3 listings', () => {
      for (const count of [0, 1, 2]) {
        const res = evaluateCanonicalDirectorySeo({
          pageKind: 'city_locality_category',
          citySlug: 'chennai',
          localitySlug: 't-nagar',
          categorySlug: 'dentists',
          params: { page: 1 },
          apiOk: true,
          totalOrganic: count,
          siteUrl,
        });
        expect(res.isIndexable).toBe(false);
        expect(res.robots).toEqual({ index: false, follow: true });
        expect(res.noindexReason).toBe('thin_locality_category_page');
        expect(res.canonicalUrl).toBe('https://neargrid.example.com/chennai/t-nagar/dentists');
      }

      const eligible = evaluateCanonicalDirectorySeo({
        pageKind: 'city_locality_category',
        citySlug: 'chennai',
        localitySlug: 't-nagar',
        categorySlug: 'dentists',
        params: { page: 1 },
        apiOk: true,
        totalOrganic: 3,
        siteUrl,
      });
      expect(eligible.isIndexable).toBe(true);
      expect(eligible.robots).toEqual({ index: true, follow: true });
    });

    it('forces noindex with bare canonical URL on filtered, non-default sorted, or failed API responses', () => {
      const filtered = evaluateCanonicalDirectorySeo({
        pageKind: 'city_category',
        citySlug: 'chennai',
        categorySlug: 'dentists',
        params: { page: 2, min_rating: 4, verified_only: true },
        apiOk: true,
        totalOrganic: 20,
        siteUrl,
      });
      expect(filtered.isIndexable).toBe(false);
      expect(filtered.canonicalUrl).toBe('https://neargrid.example.com/chennai/dentists');

      const sorted = evaluateCanonicalDirectorySeo({
        pageKind: 'city_category',
        citySlug: 'chennai',
        categorySlug: 'dentists',
        params: { page: 1, sort: 'rating' },
        apiOk: true,
        totalOrganic: 20,
        siteUrl,
      });
      expect(sorted.isIndexable).toBe(false);
      expect(sorted.noindexReason).toBe('non_default_sort_parameter');

      const apiDown = evaluateCanonicalDirectorySeo({
        pageKind: 'city_category',
        citySlug: 'chennai',
        categorySlug: 'dentists',
        params: { page: 1 },
        apiOk: false,
        totalOrganic: 0,
        siteUrl,
      });
      expect(apiDown.isIndexable).toBe(false);
      expect(apiDown.noindexReason).toBe('upstream_unavailable');
    });

    it('resolves both parent and nested child subcategory names cleanly', () => {
      const tree = [
        {
          public_id: '01CAT_HEALTH',
          slug: 'healthcare',
          name: 'Healthcare',
          schema_org_type: 'MedicalOrganization',
          icon_name: 'stethoscope',
          is_featured: true,
          children: [
            {
              public_id: '01CAT_DENTIST',
              slug: 'dentists',
              name: 'Dentists',
              schema_org_type: 'Dentist',
              icon_name: null,
              is_featured: true,
            },
          ],
        },
      ];
      expect(resolveCategoryDisplayName(tree, 'healthcare')).toBe('Healthcare');
      expect(resolveCategoryDisplayName(tree, 'dentists')).toBe('Dentists');
      expect(resolveCategoryDisplayName(tree, 'ac-repair')).toBe('Ac Repair');
    });
  });

  describe('Structured Data JSON-LD (BreadcrumbList & ItemList)', () => {
    it('builds BreadcrumbList JSON-LD with accurate entity URLs and never falls back to homepage on last item', () => {
      const siteUrl = 'https://neargrid.example.com';

      const withCanonicalLast = buildBreadcrumbListJsonLd(
        [
          { label: 'Home', href: '/' },
          { label: 'Chennai', href: '/search?city=chennai' },
          { label: 'T. Nagar', href: '/search?city=chennai&locality=t-nagar' },
          { label: 'Dentists', href: '/chennai/t-nagar/dentists' },
        ],
        siteUrl
      ) as { itemListElement: Array<{ position: number; name: string; item?: string }> };

      expect(withCanonicalLast.itemListElement).toHaveLength(4);
      expect(withCanonicalLast.itemListElement[0]).toEqual({
        '@type': 'ListItem',
        position: 1,
        name: 'Home',
        item: 'https://neargrid.example.com/',
      });
      expect(withCanonicalLast.itemListElement[2].name).toBe('T. Nagar');
      expect(withCanonicalLast.itemListElement[3]).toEqual({
        '@type': 'ListItem',
        position: 4,
        name: 'Dentists',
        item: 'https://neargrid.example.com/chennai/t-nagar/dentists',
      });

      const withoutHrefOnLast = buildBreadcrumbListJsonLd(
        [
          { label: 'Home', href: '/' },
          { label: 'Search Results' },
        ],
        siteUrl
      ) as { itemListElement: Array<{ position: number; name: string; item?: string }> };

      expect(withoutHrefOnLast.itemListElement[1].item).toBeUndefined();
    });

    it('builds ItemList JSON-LD on Page 1 only and excludes sponsored listings', () => {
      const siteUrl = 'https://neargrid.example.com';
      const makeHit = (overrides: Partial<SearchBusinessHitDto>): SearchBusinessHitDto => ({
        public_id: '01HP01',
        name: 'Sample Biz',
        slug: 'sample-biz',
        tagline: null,
        city_slug: 'chennai',
        city_name: 'Chennai',
        locality_slug: 't-nagar',
        locality_name: 'T. Nagar',
        primary_category: { public_id: '01CAT1', slug: 'dentists', name: 'Dentists' },
        is_sponsored: false,
        sponsor_disclosure: null,
        is_verified: true,
        is_trusted: false,
        is_claimed: true,
        rating_avg: 4.5,
        review_count: 20,
        completeness_score: 80,
        distance_km: null,
        is_open_now: true,
        address_short: '14 Usman Road, T. Nagar',
        phone_masked: '+91 98400 •••••',
        has_whatsapp: true,
        services_preview: ['Root Canal'],
        media_preview: [],
        ...overrides,
      });

      const hits = [
        makeHit({ public_id: '01HP01', name: 'Sponsored Smile', slug: 'sponsored-smile', is_sponsored: true }),
        makeHit({ public_id: '01HP02', name: 'Dr. Kumar Dental', slug: 'dr-kumar-dental', is_sponsored: false }),
        makeHit({ public_id: '01HP03', name: 'Apex Dental', slug: 'apex-dental', is_sponsored: false }),
      ];

      const page1List = buildOrganicItemListJsonLd(hits, 'chennai', 1, siteUrl) as {
        '@type': string;
        itemListElement: Array<{ position: number; name: string; url: string }>;
      };
      expect(page1List).not.toBeNull();
      expect(page1List['@type']).toBe('ItemList');
      expect(page1List.itemListElement).toHaveLength(2);
      expect(page1List.itemListElement[0]).toEqual({
        '@type': 'ListItem',
        position: 1,
        name: 'Dr. Kumar Dental',
        url: 'https://neargrid.example.com/chennai/business/dr-kumar-dental',
      });
      expect(page1List.itemListElement.some((e) => e.name === 'Sponsored Smile')).toBe(false);

      // Page 2 must NOT emit ItemList JSON-LD per docs/12-seo-architecture.md Section 4.2
      expect(buildOrganicItemListJsonLd(hits, 'chennai', 2, siteUrl)).toBeNull();
    });
  });

  describe('Privacy, honesty, and zero-demo-data safeguards', () => {
    it('ensures EnquiryModal does not collect PII or claim to submit enquiries while Phase 3C is pending', () => {
      const source = fs.readFileSync(
        path.join(FRONTEND_SRC, 'modules/customer/components/EnquiryModal.tsx'),
        'utf-8'
      );
      expect(source).not.toContain('<form');
      expect(source).not.toContain('Submit Enquiry');
      expect(source).not.toContain('Enquiry Preview Recorded');
      expect(source).toContain('Phase 3C');
      expect(source).toContain('not active yet');
    });

    it('ensures DirectoryListingCard keeps phone and WhatsApp privacy-safe without fake reveal or raw links', () => {
      const source = fs.readFileSync(
        path.join(FRONTEND_SRC, 'modules/customer/components/DirectoryListingCard.tsx'),
        'utf-8'
      );
      expect(source).not.toContain('phoneRevealed');
      expect(source).not.toContain('Click to view contact number');
      expect(source).not.toContain('https://wa.me/');
      expect(source).not.toContain('href={`tel:');
      expect(source).toContain('hit.phone_masked');
      expect(source).toContain('WhatsApp Available');
    });

    it('ensures search and canonical directory routes never import demo-directory fixtures', () => {
      const files = [
        'app/(customer)/search/page.tsx',
        'app/(customer)/[city]/[segment]/page.tsx',
        'app/(customer)/[city]/[segment]/[category]/page.tsx',
        'modules/customer/components/SearchResultsClient.tsx',
        'modules/customer/components/CanonicalDirectoryView.tsx',
        'modules/customer/components/DirectoryListingCard.tsx',
      ];
      for (const rel of files) {
        const content = fs.readFileSync(path.join(FRONTEND_SRC, rel), 'utf-8');
        expect(content, `${rel} must not import demo-directory`).not.toContain('demo-directory');
      }
    });
  });
});
