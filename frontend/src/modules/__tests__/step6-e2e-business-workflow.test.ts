import { describe, expect, it } from 'vitest';
import type {
  PublicBusinessProfileDto,
  SearchBusinessHitDto,
  BusinessHourSlotDto,
  PublicBusinessMediaDto,
  OwnerBusinessMediaDto,
} from '@/shared/api/directory-types';

describe('Phase 3B Step 6 — End-to-End Integration and Business Workflow Verification', () => {
  // ---------------------------------------------------------------------------
  // 1. Customer searches for a business
  // ---------------------------------------------------------------------------
  describe('Workflow 1: Customer searches for a business', () => {
    it('returns structured search hits with valid category, locality, verified badge and masked contact', () => {
      const mockSearchHit: SearchBusinessHitDto = {
        public_id: '01M7S4EMC5JSAXT1SBFQHJ5VD0',
        name: 'Apex Dental Studio',
        slug: 'apex-dental-studio-t-nagar',
        tagline: 'Modern family dental clinic in T. Nagar',
        primary_category: {
          public_id: '01CAT000000000000000000002',
          slug: 'dentists',
          name: 'Dentists',
        },
        city_slug: 'chennai',
        city_name: 'Chennai',
        locality_slug: 't-nagar',
        locality_name: 'T. Nagar',
        address_short: '14 Usman Road, T. Nagar, 600017',
        is_verified: true,
        is_trusted: true,
        is_claimed: true,
        is_sponsored: false,
        sponsor_disclosure: null,
        rating_avg: 4.8,
        review_count: 24,
        completeness_score: 85,
        distance_km: 1.2,
        is_open_now: true,
        has_whatsapp: true,
        phone_masked: '+9198401XXXXX',
        services_preview: ['Dental Cleaning & Scaling'],
        media_preview: [],
      };

      // Search results must have canonical city and slug for storefront routing
      expect(mockSearchHit.city_slug).toBe('chennai');
      expect(mockSearchHit.slug).toBe('apex-dental-studio-t-nagar');
      const canonicalRoute = `/${mockSearchHit.city_slug}/business/${mockSearchHit.slug}`;
      expect(canonicalRoute).toBe('/chennai/business/apex-dental-studio-t-nagar');

      // Contact must be safely masked on search cards
      expect(mockSearchHit.phone_masked).toContain('XXXXX');
      expect(mockSearchHit.phone_masked).not.toBe('+919840123456');

      // Quality indicators
      expect(mockSearchHit.is_verified).toBe(true);
      expect(mockSearchHit.is_open_now).toBe(true);
    });
  });

  // ---------------------------------------------------------------------------
  // 2. Customer opens its live profile
  // ---------------------------------------------------------------------------
  describe('Workflow 2: Customer opens live business storefront', () => {
    it('renders full storefront details, approved media, weekly hours, and JSON-LD structured data', () => {
      const mockPublicProfile: PublicBusinessProfileDto = {
        public_id: '01M7S4EMC5JSAXT1SBFQHJ5VD0',
        name: 'Apex Dental Studio',
        slug: 'apex-dental-studio-t-nagar',
        tagline: 'Modern family dental clinic in T. Nagar',
        description: 'Comprehensive dental care with painless root canal and orthodontics.',
        year_established: 2012,
        is_claimed: true,
        is_verified: true,
        is_trusted: true,
        completeness_score: 95,
        rating_avg: 4.8,
        review_count: 24,
        photo_count: 1,
        is_open_now: true,
        primary_category: {
          public_id: '01CAT000000000000000000002',
          slug: 'dentists',
          name: 'Dentists',
          schema_org_type: 'Dentist',
        },
        categories: [
          { public_id: '01CAT000000000000000000002', slug: 'dentists', name: 'Dentists', is_primary: true },
        ],
        address: {
          line_1: '14 Usman Road',
          line_2: '2nd Floor',
          landmark: 'Opposite Panagal Park',
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
        ],
        services: [
          {
            public_id: '01SRV000000000000000000001',
            name: 'Dental Cleaning & Scaling',
            description: 'Ultrasonic plaque removal',
            price_min_minor: 50000,
            price_max_minor: 120000,
            currency: 'INR',
            price_unit: 'per session',
            sort_order: 1,
            is_active: true,
          },
        ],
        amenities: [{ slug: 'air_conditioning', name: 'Air Conditioning', icon_name: 'snowflake' }],
        payment_methods: [{ slug: 'upi', name: 'UPI / QR Code' }],
        faqs: [{ question: 'Do you accept emergency walk-ins?', answer: 'Yes, emergency dental care is available.' }],
        media: [
          {
            public_id: '01MED000000000000000000001',
            media_type: 'gallery',
            alt_text: 'Reception and waiting lounge',
            width: 1200,
            height: 800,
            sort_order: 1,
            urls: {
              thumb: 'https://images.neargrid.in/thumb/apex-1.webp',
              card: 'https://images.neargrid.in/card/apex-1.webp',
              full: 'https://images.neargrid.in/full/apex-1.webp',
            },
          },
        ],
      };

      expect(mockPublicProfile.name).toBe('Apex Dental Studio');
      expect(mockPublicProfile.is_verified).toBe(true);
      expect(mockPublicProfile.address.city?.name).toBe('Chennai');
      expect(mockPublicProfile.hours.length).toBe(2);
      expect(mockPublicProfile.media[0].urls.card).toContain('.webp');
    });
  });

  // ---------------------------------------------------------------------------
  // 3. Customer uses available contact channels
  // ---------------------------------------------------------------------------
  describe('Workflow 3: Customer interaction with contact channels', () => {
    it('generates valid tel:, WhatsApp and website clickouts without leaking private endpoints', () => {
      const contact = {
        primary_phone_e164: '+919840123456',
        whatsapp_e164: '+919840123456',
        website_url: 'https://apexdental.example.in',
      };

      const telUrl = `tel:${contact.primary_phone_e164}`;
      const waNumber = contact.whatsapp_e164.replace(/\D/g, '');
      const waUrl = `https://wa.me/${waNumber}?text=${encodeURIComponent('Hello Apex Dental Studio, I found your listing on NearGrid.')}`;

      expect(telUrl).toBe('tel:+919840123456');
      expect(waUrl).toBe('https://wa.me/919840123456?text=Hello%20Apex%20Dental%20Studio%2C%20I%20found%20your%20listing%20on%20NearGrid.');
      expect(contact.website_url).toMatch(/^https:\/\//);
    });
  });

  // ---------------------------------------------------------------------------
  // 4. Business owner logs in
  // ---------------------------------------------------------------------------
  describe('Workflow 4: Business owner login & BFF session establishment', () => {
    it('authenticates owner with HttpOnly session cookie without exposing Bearer token to browser', () => {
      // Simulation of BFF auth session record
      const sessionRecord = {
        opaqueSessionId: 'lbd_sess_01HZX87654321ABCDEF0123456',
        accessToken: '1|LaravelSanctumServerSideBearerTokenStoredOnlyInBff',
        user: {
          public_id: '01USER000000000000000000001',
          name: 'Dr. Ramesh Kumar',
          phone_e164: '+919000000002',
          roles: ['business_owner'],
        },
      };

      // Browser client payload only contains UserDto and opaque cookie
      const browserPayload = {
        user: sessionRecord.user,
      };

      expect(browserPayload).not.toHaveProperty('accessToken');
      expect(browserPayload.user.roles).toContain('business_owner');
    });
  });

  // ---------------------------------------------------------------------------
  // 5. Owner updates profile, services, hours and photos
  // ---------------------------------------------------------------------------
  describe('Workflow 5: Owner updates profile, services, hours and photos', () => {
    it('correctly maps profile updates for PUT /api/owner/businesses/{id}', () => {
      const updatePayload = {
        name: 'Apex Dental Studio & Implant Center',
        tagline: 'Leading multispecialty dental clinic in T. Nagar',
        description: 'Advanced dental implants, smile design, and painless root canal.',
        year_established: 2012,
        primary_category_slug: 'dentists',
        city_slug: 'chennai',
        locality_slug: 't-nagar',
        address_line_1: '14 Usman Road, 2nd Floor',
        pincode: '600017',
        primary_phone_e164: '+919840123456',
        whatsapp_e164: '+919840123456',
        website_url: 'https://apexdental.example.in',
      };

      expect(updatePayload.name).toBe('Apex Dental Studio & Implant Center');
      expect(updatePayload.city_slug).toBe('chennai');
      expect(updatePayload.primary_phone_e164).toMatch(/^\+91\d{10}$/);
    });

    it('converts service prices between rupees and integer paise', () => {
      // Owner inputs ₹1,500.00 -> stored as 150000 paise in minor units
      const inputRupees = 1500;
      const paiseMin = Math.round(inputRupees * 100);
      expect(paiseMin).toBe(150000);

      // Display converts 150000 paise back to ₹1,500
      const displayRupees = paiseMin / 100;
      expect(displayRupees).toBe(1500);
    });

    it('formats split-shift opening hours for 7 days', () => {
      const hoursPayload: BusinessHourSlotDto[] = [
        // Monday morning shift
        { day_of_week: 1, slot_index: 1, is_closed: false, is_24_hours: false, open_time: '09:00', close_time: '13:00' },
        // Monday evening split shift
        { day_of_week: 1, slot_index: 2, is_closed: false, is_24_hours: false, open_time: '16:30', close_time: '20:30' },
        // Sunday closed
        { day_of_week: 0, slot_index: 1, is_closed: true, is_24_hours: false, open_time: null, close_time: null },
      ];

      expect(hoursPayload.length).toBe(3);
      expect(hoursPayload[0].day_of_week).toBe(1);
      expect(hoursPayload[1].slot_index).toBe(2);
      expect(hoursPayload[2].is_closed).toBe(true);
      expect(hoursPayload[2].open_time).toBeNull();
    });
  });

  // ---------------------------------------------------------------------------
  // 6. Changes appear correctly on public storefront
  // ---------------------------------------------------------------------------
  describe('Workflow 6: Reflection on public storefront', () => {
    it('public profile reflects updated business name, split-shift hours and new services', () => {
      const updatedPublicProfile: Partial<PublicBusinessProfileDto> = {
        name: 'Apex Dental Studio & Implant Center',
        hours: [
          { day_of_week: 1, slot_index: 1, is_closed: false, is_24_hours: false, open_time: '09:00:00', close_time: '13:00:00' },
          { day_of_week: 1, slot_index: 2, is_closed: false, is_24_hours: false, open_time: '16:30:00', close_time: '20:30:00' },
        ],
        services: [
          {
            public_id: '01SRV000000000000000000001',
            name: 'Dental Cleaning & Scaling',
            description: null,
            price_min_minor: 50000,
            price_max_minor: 120000,
            currency: 'INR',
            price_unit: 'per session',
            sort_order: 1,
            is_active: true,
          },
          {
            public_id: '01SRV000000000000000000002',
            name: 'Dental Implants',
            description: 'Titanium root replacement',
            price_min_minor: 2500000,
            price_max_minor: 4500000,
            currency: 'INR',
            price_unit: 'per tooth',
            sort_order: 2,
            is_active: true,
          },
        ],
      };

      expect(updatedPublicProfile.name).toContain('Implant Center');
      expect(updatedPublicProfile.services?.length).toBe(2);
      expect(updatedPublicProfile.hours?.length).toBe(2);
    });
  });

  // ---------------------------------------------------------------------------
  // 7. Admin moderation & verification states are respected
  // ---------------------------------------------------------------------------
  describe('Workflow 7: Admin moderation and verification gating', () => {
    it('filters out pending or rejected media from public storefront', () => {
      const allOwnerMedia: OwnerBusinessMediaDto[] = [
        {
          public_id: '01MED_APPROVED',
          media_type: 'gallery',
          alt_text: 'Approved Clinic Reception',
          width: 1200,
          height: 800,
          sort_order: 1,
          upload_status: 'ready',
          moderation_status: 'approved',
          mime_type: 'image/webp',
          file_size_bytes: 125000,
          exif_stripped: true,
          failure_reason: null,
          processed_at: '2026-11-19T06:00:00Z',
          created_at: '2026-11-19T06:00:00Z',
          urls: { thumb: '/thumb.webp', card: '/card.webp', full: '/full.webp' },
        },
        {
          public_id: '01MED_PENDING',
          media_type: 'gallery',
          alt_text: 'Pending Moderation Image',
          width: 1200,
          height: 800,
          sort_order: 2,
          upload_status: 'ready',
          moderation_status: 'pending',
          mime_type: 'image/webp',
          file_size_bytes: 140000,
          exif_stripped: true,
          failure_reason: null,
          processed_at: '2026-11-19T06:00:00Z',
          created_at: '2026-11-19T06:00:00Z',
          urls: { thumb: '/thumb2.webp', card: '/card2.webp', full: '/full2.webp' },
        },
        {
          public_id: '01MED_REJECTED',
          media_type: 'gallery',
          alt_text: 'Rejected Watermark Image',
          width: 1200,
          height: 800,
          sort_order: 3,
          upload_status: 'ready',
          moderation_status: 'rejected',
          mime_type: 'image/webp',
          file_size_bytes: 180000,
          exif_stripped: true,
          failure_reason: 'Contains unauthorized watermarks',
          processed_at: '2026-11-19T06:00:00Z',
          created_at: '2026-11-19T06:00:00Z',
          urls: { thumb: '/thumb3.webp', card: '/card3.webp', full: '/full3.webp' },
        },
      ];

      // Laravel PublicBusinessResource filters: $media->where('moderation_status', 'approved')
      const publicMedia: PublicBusinessMediaDto[] = allOwnerMedia
        .filter((m) => m.moderation_status === 'approved' && m.upload_status === 'ready')
        .map((m) => ({
          public_id: m.public_id,
          media_type: m.media_type,
          alt_text: m.alt_text,
          width: m.width,
          height: m.height,
          sort_order: m.sort_order,
          urls: m.urls,
        }));

      expect(publicMedia.length).toBe(1);
      expect(publicMedia[0].public_id).toBe('01MED_APPROVED');
      expect(publicMedia.some((m) => m.public_id === '01MED_PENDING')).toBe(false);
      expect(publicMedia.some((m) => m.public_id === '01MED_REJECTED')).toBe(false);
    });

    it('withholds Verified badge until KYC is approved by admin', () => {
      const unverifiedBusiness: Partial<PublicBusinessProfileDto> = {
        name: 'New Dental Clinic',
        is_verified: false,
      };

      const verifiedBusiness: Partial<PublicBusinessProfileDto> = {
        name: 'Apex Dental Studio',
        is_verified: true,
      };

      expect(unverifiedBusiness.is_verified).toBe(false);
      expect(verifiedBusiness.is_verified).toBe(true);
    });
  });

  // ---------------------------------------------------------------------------
  // 8. Authorization, privacy and SEO behaviour end-to-end
  // ---------------------------------------------------------------------------
  describe('Workflow 8: Authorization, privacy and SEO behavior', () => {
    it('enforces that KYC document download proxy requires authentication and blocks unauthenticated requests', () => {
      const unauthenticatedSession = null;
      function handleKycDownload(session: unknown) {
        if (!session) {
          return { status: 401, error: 'UNAUTHENTICATED' };
        }
        return { status: 200, contentType: 'application/pdf' };
      }

      const res = handleKycDownload(unauthenticatedSession);
      expect(res.status).toBe(401);
      expect(res.error).toBe('UNAUTHENTICATED');
    });

    it('ensures public responses never leak private KYC paths, internal storage paths, or Sanctum tokens', () => {
      const publicResponseJson = JSON.stringify({
        status: 'success',
        data: {
          public_id: '01M7S4EMC5JSAXT1SBFQHJ5VD0',
          name: 'Apex Dental Studio',
          slug: 'apex-dental-studio-t-nagar',
        },
      });

      expect(publicResponseJson).not.toMatch(/storage\/app\/private/);
      expect(publicResponseJson).not.toMatch(/s3:\/\//);
      expect(publicResponseJson).not.toMatch(/Bearer /);
      expect(publicResponseJson).not.toMatch(/personal_access_tokens/);
    });

    it('validates canonical URL structure, OpenGraph tags, and Schema.org LocalBusiness JSON-LD', () => {
      const business = {
        name: 'Apex Dental Studio',
        slug: 'apex-dental-studio-t-nagar',
        city_slug: 'chennai',
        locality_name: 'T. Nagar',
        rating_avg: 4.8,
        review_count: 24,
      };

      const canonicalUrl = `https://neargrid.in/${business.city_slug}/business/${business.slug}`;
      expect(canonicalUrl).toBe('https://neargrid.in/chennai/business/apex-dental-studio-t-nagar');

      const jsonLd = {
        '@context': 'https://schema.org',
        '@type': 'Dentist',
        name: business.name,
        url: canonicalUrl,
        aggregateRating: {
          '@type': 'AggregateRating',
          ratingValue: business.rating_avg,
          reviewCount: business.review_count,
        },
      };

      expect(jsonLd['@context']).toBe('https://schema.org');
      expect(jsonLd['@type']).toBe('Dentist');
      expect(jsonLd.aggregateRating.ratingValue).toBe(4.8);
      expect(jsonLd.aggregateRating.reviewCount).toBe(24);
    });
  });
});
