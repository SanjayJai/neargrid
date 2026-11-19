import fs from 'node:fs';
import path from 'node:path';
import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

(globalThis as unknown as { React: typeof React }).React = React;
import type { PublicBusinessProfileDto } from '@/shared/api/directory-types';
import { buildBreadcrumbListJsonLd } from '@/shared/ui/Breadcrumbs';
import {
  buildFaqPageJsonLd,
  buildLocalBusinessJsonLd,
  buildOpeningHoursSpecification,
  evaluateBusinessProfileSeo,
  formatServicePrice,
  formatWeeklyHours,
  getDeliverablePublicMedia,
  getGoogleMapsDirectionsUrl,
  getSafeWebsiteUrl,
  getTodayHoursSummary,
  isSafeInternalRedirectPath,
  pickBusinessLogoMedia,
  serializeSafeJsonLd,
} from '@/modules/customer/lib/business-profile';
import { sanitizeInternalRedirectPath } from '@/modules/customer/components/OtpLoginForm';

const mockFetchPublicBusinessProfile = vi.fn();
const mockGetServerAuthenticatedUser = vi.fn();
const mockNotFound = vi.fn(() => {
  throw new Error('NEXT_NOT_FOUND');
});
const mockPermanentRedirect = vi.fn((target: string) => {
  throw new Error(`NEXT_REDIRECT:${target}`);
});

vi.mock('@/lib/api/public-directory', () => ({
  fetchPublicBusinessProfile: (...args: unknown[]) =>
    mockFetchPublicBusinessProfile(...args),
}));

vi.mock('@/lib/bff/session-cookie', () => ({
  getServerAuthenticatedUser: () => mockGetServerAuthenticatedUser(),
}));

vi.mock('next/navigation', () => ({
  notFound: () => mockNotFound(),
  permanentRedirect: (target: string) => mockPermanentRedirect(target),
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));

const FRONTEND_SRC = path.resolve(__dirname, '../../..');

function makeSampleProfile(
  overrides: Partial<PublicBusinessProfileDto> = {}
): PublicBusinessProfileDto {
  return {
    public_id: '01HP1234567890ABCDEFGHJKMN',
    name: 'Apex Dental Studio',
    slug: 'apex-dental-studio',
    tagline: 'Modern family dental clinic in T. Nagar',
    description: 'Comprehensive dental care with painless root canal and orthodontics.',
    year_established: 2016,
    is_claimed: false,
    is_verified: true,
    is_trusted: true,
    rating_avg: 4.7,
    review_count: 34,
    photo_count: 2,
    completeness_score: 85,
    is_open_now: true,
    primary_category: {
      public_id: '01CAT_DENTISTS',
      slug: 'dentists',
      name: 'Dentists',
      schema_org_type: 'Dentist',
    },
    categories: [
      {
        public_id: '01CAT_DENTISTS',
        slug: 'dentists',
        name: 'Dentists',
        is_primary: true,
      },
    ],
    address: {
      line_1: '14 Usman Road',
      line_2: '2nd Floor',
      landmark: 'Near Panagal Park',
      locality: { slug: 't-nagar', name: 'T. Nagar' },
      city: { slug: 'chennai', name: 'Chennai' },
      pincode: '600017',
      coordinates: { lat: 13.0418, lng: 80.2341 },
    },
    contact: {
      phone_masked: '+9198401XXXXX',
      has_whatsapp: true,
      website_url: 'https://apexdental.example.in',
    },
    hours: [
      {
        day_of_week: 1,
        slot_index: 1,
        open_time: '09:00:00',
        close_time: '13:00:00',
        is_24_hours: false,
        is_closed: false,
      },
      {
        day_of_week: 1,
        slot_index: 2,
        open_time: '16:30:00',
        close_time: '20:30:00',
        is_24_hours: false,
        is_closed: false,
      },
      {
        day_of_week: 6,
        slot_index: 1,
        open_time: null,
        close_time: null,
        is_24_hours: true,
        is_closed: false,
      },
      {
        day_of_week: 0,
        slot_index: 1,
        open_time: null,
        close_time: null,
        is_24_hours: false,
        is_closed: true,
      },
    ],
    services: [
      {
        public_id: '01SVC01',
        name: 'Root Canal Therapy',
        description: 'Single-sitting painless procedure',
        price_min_minor: 350000,
        price_max_minor: 550000,
        currency: 'INR',
        price_unit: 'tooth',
        sort_order: 1,
        is_active: true,
      },
      {
        public_id: '01SVC02',
        name: 'Dental Consultation',
        description: null,
        price_min_minor: null,
        price_max_minor: null,
        currency: 'INR',
        price_unit: null,
        sort_order: 2,
        is_active: true,
      },
    ],
    amenities: [{ slug: 'parking', name: 'Parking', icon_name: 'car' }],
    payment_methods: [{ slug: 'upi', name: 'UPI' }],
    faqs: [
      {
        question: 'Do you accept walk-in patients?',
        answer: 'Yes, walk-ins are welcome during morning and evening clinic hours.',
      },
    ],
    media: [
      {
        public_id: '01MED_LOGO',
        media_type: 'logo',
        alt_text: 'Apex Dental logo',
        width: 400,
        height: 400,
        sort_order: 1,
        urls: {
          thumb: 'https://cdn.example.in/logo-thumb.webp',
          card: 'https://cdn.example.in/logo-card.webp',
          full: 'https://cdn.example.in/logo-full.webp',
        },
      },
      {
        public_id: '01MED_COVER',
        media_type: 'cover',
        alt_text: 'Apex Dental storefront',
        width: 1200,
        height: 800,
        sort_order: 2,
        urls: {
          thumb: 'https://cdn.example.in/cover-thumb.webp',
          card: 'https://cdn.example.in/cover-card.webp',
          full: 'https://cdn.example.in/cover-full.webp',
        },
      },
    ],
    ...overrides,
  };
}

describe('Phase 3B Milestone 8 Step 4: Live Business Profile', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockGetServerAuthenticatedUser.mockResolvedValue(null);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('SSR page, redirects, 404, API failure, and generateMetadata', () => {
    it('renders live business profile SSR without errors and passes live data', async () => {
      const profile = makeSampleProfile();
      mockFetchPublicBusinessProfile.mockResolvedValue({
        ok: true,
        status: 200,
        data: profile,
        meta: null,
        error: null,
      });
      mockGetServerAuthenticatedUser.mockResolvedValue({
        public_id: '01USER123',
        name: 'Karthik',
      });

      const { default: BusinessProfilePage, generateMetadata } = await import(
        '@/app/(customer)/[city]/business/[slug]/page'
      );

      const metadata = await generateMetadata({
        params: { city: 'chennai', slug: 'apex-dental-studio' },
      });
      expect(metadata.title).toBe('Apex Dental Studio — T. Nagar, Chennai');
      expect(metadata.description).toContain('Comprehensive dental care');
      expect(metadata.robots).toEqual({ index: true, follow: true });
      expect(metadata.alternates?.canonical).toBe(
        'http://localhost:3000/chennai/business/apex-dental-studio'
      );

      const rendered = await BusinessProfilePage({
        params: { city: 'chennai', slug: 'apex-dental-studio' },
        searchParams: { claim: '1' },
      });
      expect(rendered).toBeTruthy();
      expect(mockNotFound).not.toHaveBeenCalled();
      expect(mockPermanentRedirect).not.toHaveBeenCalled();
    });

    it('emits noindex, follow in metadata when completeness_score < 40', async () => {
      mockFetchPublicBusinessProfile.mockResolvedValue({
        ok: true,
        status: 200,
        data: makeSampleProfile({ completeness_score: 35 }),
        meta: null,
        error: null,
      });

      const { generateMetadata } = await import(
        '@/app/(customer)/[city]/business/[slug]/page'
      );

      const metadata = await generateMetadata({
        params: { city: 'chennai', slug: 'apex-dental-studio' },
      });
      expect(metadata.robots).toEqual({ index: false, follow: true });
      expect(metadata.alternates?.canonical).toBe(
        'http://localhost:3000/chennai/business/apex-dental-studio'
      );
    });

    it('triggers permanentRedirect on confirmed 301 slug redirects from Laravel', async () => {
      mockFetchPublicBusinessProfile.mockResolvedValue({
        ok: false,
        status: 301,
        data: null,
        meta: null,
        error: { code: 'MOVED_PERMANENTLY', message: 'Moved' },
        redirectTargetPath: '/chennai/business/apex-dental-studio-new',
      });

      const { default: BusinessProfilePage } = await import(
        '@/app/(customer)/[city]/business/[slug]/page'
      );

      await expect(
        BusinessProfilePage({
          params: { city: 'chennai', slug: 'old-slug' },
        })
      ).rejects.toThrow('NEXT_REDIRECT:/chennai/business/apex-dental-studio-new');
      expect(mockPermanentRedirect).toHaveBeenCalledWith(
        '/chennai/business/apex-dental-studio-new'
      );
    });

    it('triggers notFound on 404 missing/unpublished businesses or malformed slugs', async () => {
      mockFetchPublicBusinessProfile.mockResolvedValue({
        ok: false,
        status: 404,
        data: null,
        meta: null,
        error: { code: 'NOT_FOUND', message: 'Business profile not found.' },
      });

      const { default: BusinessProfilePage } = await import(
        '@/app/(customer)/[city]/business/[slug]/page'
      );

      await expect(
        BusinessProfilePage({
          params: { city: 'chennai', slug: 'missing-biz' },
        })
      ).rejects.toThrow('NEXT_NOT_FOUND');

      await expect(
        BusinessProfilePage({
          params: { city: 'chennai', slug: 'INVALID/SLUG' },
        })
      ).rejects.toThrow('NEXT_NOT_FOUND');
    });

    it('renders honest fallback error UI on upstream 503 API failure without fabricating data', async () => {
      mockFetchPublicBusinessProfile.mockResolvedValue({
        ok: false,
        status: 503,
        data: null,
        meta: null,
        error: {
          code: 'UPSTREAM_UNREACHABLE',
          message: 'Could not reach the NearGrid API service.',
        },
      });

      const { default: BusinessProfilePage, generateMetadata } = await import(
        '@/app/(customer)/[city]/business/[slug]/page'
      );

      const meta = await generateMetadata({
        params: { city: 'chennai', slug: 'apex-dental-studio' },
      });
      expect(meta.robots).toEqual({ index: false, follow: true });

      const element = await BusinessProfilePage({
        params: { city: 'chennai', slug: 'apex-dental-studio' },
      });
      expect(element).toBeTruthy();
      expect(mockNotFound).not.toHaveBeenCalled();
    });
  });

  describe('SEO, JSON-LD, escaping, and redirect safety', () => {
    it('evaluates canonical URL and completeness threshold (<40 noindex)', () => {
      const low = evaluateBusinessProfileSeo(
        makeSampleProfile({ completeness_score: 39 }),
        'chennai',
        'https://neargrid.in'
      );
      expect(low.isIndexable).toBe(false);
      expect(low.robots).toEqual({ index: false, follow: true });
      expect(low.canonicalUrl).toBe('https://neargrid.in/chennai/business/apex-dental-studio');

      const high = evaluateBusinessProfileSeo(
        makeSampleProfile({ completeness_score: 40 }),
        'chennai',
        'https://neargrid.in'
      );
      expect(high.isIndexable).toBe(true);
      expect(high.robots).toEqual({ index: true, follow: true });
    });

    it('builds LocalBusiness/schema_org_type JSON-LD with AggregateRating ONLY when review_count >= 1', () => {
      const withReviews = buildLocalBusinessJsonLd(
        makeSampleProfile({ review_count: 34, rating_avg: 4.7 }),
        'https://neargrid.in/chennai/business/apex-dental-studio'
      );
      expect(withReviews['@type']).toBe('Dentist');
      expect(withReviews.aggregateRating).toEqual({
        '@type': 'AggregateRating',
        ratingValue: 4.7,
        reviewCount: 34,
        bestRating: 5,
        worstRating: 1,
      });
      expect(withReviews.geo).toEqual({
        '@type': 'GeoCoordinates',
        latitude: 13.0418,
        longitude: 80.2341,
      });
      // Must NEVER include unmasked telephone
      expect(withReviews.telephone).toBeUndefined();

      const zeroReviews = buildLocalBusinessJsonLd(
        makeSampleProfile({
          review_count: 0,
          rating_avg: 0,
          address: {
            ...makeSampleProfile().address,
            coordinates: { lat: 0, lng: 0 },
          },
        }),
        'https://neargrid.in/chennai/business/apex-dental-studio'
      );
      expect(zeroReviews.aggregateRating).toBeUndefined();
      expect(zeroReviews.geo).toBeUndefined();
    });

    it('builds FAQPage JSON-LD ONLY when real FAQs exist and escapes script breakout characters', () => {
      const withFaqs = buildFaqPageJsonLd(makeSampleProfile());
      expect(withFaqs).not.toBeNull();
      expect(withFaqs?.['@type']).toBe('FAQPage');

      const withoutFaqs = buildFaqPageJsonLd(makeSampleProfile({ faqs: [] }));
      expect(withoutFaqs).toBeNull();

      const serialized = serializeSafeJsonLd({
        name: 'Clinic </script><script>alert(1)</script>',
      });
      expect(serialized).not.toContain('</script>');
      expect(serialized).toContain('\\u003c/script\\u003e');
    });

    it('builds BreadcrumbList JSON-LD for Home -> City -> Category -> Business', () => {
      const crumb = buildBreadcrumbListJsonLd(
        [
          { label: 'Home', href: '/' },
          { label: 'Chennai', href: '/search?city=chennai' },
          { label: 'Dentists', href: '/chennai/dentists' },
          { label: 'Apex Dental Studio', href: '/chennai/business/apex-dental-studio' },
        ],
        'https://neargrid.in'
      ) as { itemListElement: Array<{ position: number; name: string; item?: string }> };

      expect(crumb.itemListElement).toHaveLength(4);
      expect(crumb.itemListElement[2].item).toBe('https://neargrid.in/chennai/dentists');
      expect(crumb.itemListElement[3].item).toBe(
        'https://neargrid.in/chennai/business/apex-dental-studio'
      );
    });

    it('validates safe internal redirect paths, website URLs, and Google Maps coordinates', () => {
      expect(isSafeInternalRedirectPath('/chennai/business/new-slug')).toBe(true);
      expect(isSafeInternalRedirectPath('//evil.com/phish')).toBe(false);
      expect(isSafeInternalRedirectPath('https://evil.com')).toBe(false);

      expect(sanitizeInternalRedirectPath('/chennai/business/apex?claim=1')).toBe(
        '/chennai/business/apex?claim=1'
      );
      expect(sanitizeInternalRedirectPath('//evil.com')).toBe('/dashboard/settings');
      expect(sanitizeInternalRedirectPath('javascript:alert(1)')).toBe('/dashboard/settings');

      expect(getSafeWebsiteUrl('https://apexdental.example.in')).toBe(
        'https://apexdental.example.in/'
      );
      expect(getSafeWebsiteUrl('javascript:alert(1)')).toBeNull();

      expect(getGoogleMapsDirectionsUrl({ lat: 13.0418, lng: 80.2341 })).toContain(
        'https://www.google.com/maps/dir/?api=1&destination=13.041800%2C80.234100'
      );
      expect(getGoogleMapsDirectionsUrl({ lat: 0, lng: 0 })).toBeNull();
    });
  });

  describe('Hours (split-shift & 24h), service pricing, and approved media filtering', () => {
    it('formats split-shift, 24-hour, and closed schedules cleanly in Mon–Sun order', () => {
      const profile = makeSampleProfile();
      const weekly = formatWeeklyHours(profile.hours);

      expect(weekly).toHaveLength(7);
      expect(weekly[0].dayName).toBe('Monday');
      expect(weekly[0].intervals).toHaveLength(2);
      expect(weekly[0].summaryText).toBe('9:00 AM – 1:00 PM, 4:30 PM – 8:30 PM');

      const saturday = weekly.find((d) => d.dayOfWeek === 6)!;
      expect(saturday.is24Hours).toBe(true);
      expect(saturday.summaryText).toBe('Open 24 Hours');

      const sunday = weekly.find((d) => d.dayOfWeek === 0)!;
      expect(sunday.isClosed).toBe(true);
      expect(sunday.summaryText).toBe('Closed');

      // Schema.org OpeningHoursSpecification includes split slots + 24h and omits closed Sunday
      const specs = buildOpeningHoursSpecification(profile.hours);
      expect(specs).toHaveLength(3);
      expect(specs.some((s) => s.opens === '00:00' && s.closes === '23:59')).toBe(true);

      // Today summary resolves cleanly in IST
      const mondayUtc = new Date('2026-11-16T06:00:00Z'); // Monday in Asia/Kolkata
      const today = getTodayHoursSummary(profile.hours, 'Asia/Kolkata', mondayUtc);
      expect(today.dayName).toBe('Monday');
      expect(today.summaryText).toContain('9:00 AM – 1:00 PM');
    });

    it('formats minor-unit service pricing accurately and falls back honestly when null', () => {
      const [priced, unpriced] = makeSampleProfile().services;
      const pricedLabel = formatServicePrice(priced);
      expect(pricedLabel).toContain('3,500');
      expect(pricedLabel).toContain('5,500');
      expect(pricedLabel).toContain('/ tooth');

      expect(formatServicePrice(unpriced)).toBe('Price on enquiry');
    });

    it('filters media strictly to safe deliverable public URLs and orders cover before logo', () => {
      const mixed = [
        ...makeSampleProfile().media,
        {
          public_id: '01MED_BAD',
          media_type: 'gallery' as const,
          alt_text: 'Invalid',
          width: null,
          height: null,
          sort_order: 0,
          urls: {
            thumb: 'javascript:alert(1)',
            card: null,
            full: '',
          },
        },
      ];

      const deliverable = getDeliverablePublicMedia(mixed);
      expect(deliverable).toHaveLength(2);
      expect(deliverable[0].media_type).toBe('cover');
      expect(deliverable[1].media_type).toBe('logo');
      expect(pickBusinessLogoMedia(mixed)?.public_id).toBe('01MED_LOGO');
    });
  });

  describe('Privacy, claim security, and zero fake data safeguards', () => {
    it('verifies customer profile files contain zero demo-directory imports, zero fake enquiries, and zero raw contact/KYC leakage', () => {
      const files = [
        'app/(customer)/[city]/business/[slug]/page.tsx',
        'modules/customer/components/BusinessProfileView.tsx',
        'modules/customer/components/BusinessMediaGallery.tsx',
        'modules/customer/components/BusinessClaimModal.tsx',
      ];

      for (const rel of files) {
        const content = fs.readFileSync(path.join(FRONTEND_SRC, rel), 'utf-8');
        expect(content, `${rel} must not import demo-directory`).not.toContain('demo-directory');
        expect(content, `${rel} must not import EnquiryConsentPreview`).not.toContain(
          'EnquiryConsentPreview'
        );
        expect(content, `${rel} must not leak raw wa.me link`).not.toContain('https://wa.me/');
        expect(content, `${rel} must not leak raw tel link`).not.toContain('href={`tel:');
        expect(content, `${rel} must not expose private KYC endpoints`).not.toContain(
          'kyc-documents'
        );
      }

      const claimSource = fs.readFileSync(
        path.join(FRONTEND_SRC, 'modules/customer/components/BusinessClaimModal.tsx'),
        'utf-8'
      );
      expect(claimSource).toContain('/api/businesses/');
      expect(claimSource).toContain('/claims');
      expect(claimSource).toContain('/login?next=');
      expect(claimSource).toContain('proof_file_path_private');
      expect(claimSource).toContain('does not immediately grant ownership');
      expect(claimSource).toContain('contact the platform administrator');
      expect(claimSource).not.toContain('support@neargrid.in');
    });
  });
});
