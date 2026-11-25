import fs from 'node:fs';
import path from 'node:path';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { PublicBusinessProfileDto, SearchBusinessHitDto } from '@/shared/api/directory-types';
import type { UserDto } from '@/lib/bff/types';

(globalThis as unknown as { React: typeof React }).React = React;

const mockGetServerAuthenticatedUser = vi.fn();
const mockRedirect = vi.fn((target: string) => {
  throw new Error(`NEXT_REDIRECT:${target}`);
});

vi.mock('@/lib/bff/session-cookie', () => ({
  getServerAuthenticatedUser: () => mockGetServerAuthenticatedUser(),
}));

vi.mock('next/navigation', () => ({
  redirect: (target: string) => mockRedirect(target),
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
  usePathname: () => '/',
  useSearchParams: () => new URLSearchParams(),
}));

import SavedBusinessesPage, {
  dynamic as savedPageDynamic,
  metadata as savedPageMetadata,
} from '@/app/(customer)/saved/page';
import { SavedBusinessesProvider } from '@/modules/customer/context/SavedBusinessesContext';
import {
  SaveBusinessButton,
  buildSafeLoginRedirectUrl,
} from '@/modules/customer/components/SaveBusinessButton';
import { BusinessProfileActions } from '@/modules/customer/components/BusinessProfileActions';
import { DirectoryListingCard } from '@/modules/customer/components/DirectoryListingCard';
import { HomeDirectoryCard } from '@/modules/customer/components/HomeDirectoryCard';
import { CustomerHeader } from '@/modules/customer/components/CustomerHeader';
import { mapPublicBusinessToSearchHit } from '@/modules/customer/lib/saved-businesses-mapper';

const FRONTEND_SRC = path.resolve(__dirname, '../../..');
const VALID_ULID = '01HQENQ001ABCDEFGH12345678';
const OTHER_ULID = '01HQENQ002ABCDEFGH12345679';

const SAMPLE_USER: UserDto = {
  public_id: '01JUSR12345678901234567890',
  name: 'Karthik',
  phone_e164: '+919876543210',
  phone_verified_at: '2026-11-20T00:00:00Z',
  email: 'karthik@example.com',
  avatar_url: null,
  roles: ['customer'],
  permissions: [],
  created_at: '2026-11-20T00:00:00Z',
};

function makeSampleProfile(overrides: Partial<PublicBusinessProfileDto> = {}): PublicBusinessProfileDto {
  return {
    public_id: VALID_ULID,
    name: 'Apex Electricals',
    slug: 'apex-electricals',
    tagline: 'Trusted electrical contractors in Katpadi',
    description: 'Complete residential and commercial electrical solutions.',
    year_established: 2015,
    is_claimed: true,
    is_verified: true,
    is_trusted: false,
    rating_avg: 4.7,
    review_count: 34,
    photo_count: 2,
    completeness_score: 90,
    is_open_now: true,
    primary_category: {
      public_id: '01HQCAT001ABCDEFGH12345678',
      slug: 'electricians',
      name: 'Electricians',
      schema_org_type: 'Electrician',
    },
    categories: [],
    address: {
      line_1: '12 Railway Road',
      line_2: null,
      landmark: 'Near Bus Stand',
      locality: { slug: 'katpadi', name: 'Katpadi' },
      city: { slug: 'vellore', name: 'Vellore' },
      pincode: '632007',
      coordinates: { lat: 12.9698, lng: 79.1383 },
    },
    contact: {
      phone_masked: '+9198400XXXXX',
      has_whatsapp: true,
      website_url: 'https://apexelectricals.example',
    },
    hours: [],
    services: [
      {
        public_id: '01HQSVC001ABCDEFGH12345678',
        name: 'House Wiring',
        description: null,
        price_min_minor: 50000,
        price_max_minor: 150000,
        currency: 'INR',
        price_unit: 'per room',
        sort_order: 1,
        is_active: true,
      },
    ],
    amenities: [],
    payment_methods: [],
    faqs: [],
    media: [],
    ...overrides,
  };
}

function makeSampleHit(overrides: Partial<SearchBusinessHitDto> = {}): SearchBusinessHitDto {
  return {
    ...mapPublicBusinessToSearchHit(makeSampleProfile()),
    ...overrides,
  };
}

describe('Saved Businesses Milestone 3: Customer UI, Storefront Integration & Hub', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe('/saved Authenticated Route & Privacy Guarantees', () => {
    it('enforces force-dynamic and noindex metadata so customer data is never publicly cached', () => {
      expect(savedPageDynamic).toBe('force-dynamic');
      expect(savedPageMetadata.robots).toEqual({ index: false, follow: false });
    });

    it('redirects unauthenticated visitors to /login?next=/saved', async () => {
      mockGetServerAuthenticatedUser.mockResolvedValue(null);

      await expect(SavedBusinessesPage()).rejects.toThrow('NEXT_REDIRECT:/login?next=/saved');
      expect(mockRedirect).toHaveBeenCalledWith('/login?next=/saved');
    });

    it('renders SavedBusinessesHub for authenticated users', async () => {
      mockGetServerAuthenticatedUser.mockResolvedValue(SAMPLE_USER);

      const element = await SavedBusinessesPage();
      const html = renderToStaticMarkup(element);

      expect(html).toContain('Saved Businesses');
      expect(html).toContain('Karthik');
      expect(html).toContain('saved-businesses-loading');
    });
  });

  describe('SaveBusinessButton & 500-ID Cap State Strategy', () => {
    it('renders Saved state with aria-pressed="true" when business is in savedIds', () => {
      const html = renderToStaticMarkup(
        React.createElement(
          SavedBusinessesProvider,
          { isAuthenticated: true, initialIds: [VALID_ULID] },
          React.createElement(SaveBusinessButton, {
            businessPublicId: VALID_ULID,
            businessName: 'Apex Electricals',
          })
        )
      );

      expect(html).toContain('data-saved-state="saved"');
      expect(html).toContain('aria-pressed="true"');
      expect(html).toContain('aria-label="Remove Apex Electricals from saved"');
      expect(html).toContain('>Saved<');
    });

    it('renders Unsaved state with aria-pressed="false" when below 500-ID cap and omitted', () => {
      const html = renderToStaticMarkup(
        React.createElement(
          SavedBusinessesProvider,
          { isAuthenticated: true, initialIds: [OTHER_ULID] },
          React.createElement(SaveBusinessButton, {
            businessPublicId: VALID_ULID,
            businessName: 'Apex Electricals',
          })
        )
      );

      expect(html).toContain('data-saved-state="unsaved"');
      expect(html).toContain('aria-pressed="false"');
      expect(html).toContain('aria-label="Save Apex Electricals"');
      expect(html).toContain('>Save<');
    });

    it('does NOT assume omitted ID means unsaved when 500-ID cap is reached', () => {
      const cappedIds = Array.from({ length: 500 }, (_, i) =>
        `01HQBIZ${String(i).padStart(19, '0')}`
      );

      const html = renderToStaticMarkup(
        React.createElement(
          SavedBusinessesProvider,
          { isAuthenticated: true, initialIds: cappedIds },
          React.createElement(SaveBusinessButton, {
            businessPublicId: VALID_ULID,
            businessName: 'Apex Electricals',
          })
        )
      );

      expect(html).toContain('data-saved-state="unknown"');
      expect(html).not.toContain('aria-pressed="false"');
      expect(html).not.toContain('aria-pressed="true"');
    });

    it('respects initialSaved={true} even when 500-ID cap is reached', () => {
      const cappedIds = Array.from({ length: 500 }, (_, i) =>
        `01HQBIZ${String(i).padStart(19, '0')}`
      );

      const html = renderToStaticMarkup(
        React.createElement(
          SavedBusinessesProvider,
          { isAuthenticated: true, initialIds: cappedIds },
          React.createElement(SaveBusinessButton, {
            businessPublicId: VALID_ULID,
            businessName: 'Apex Electricals',
            initialSaved: true,
          })
        )
      );

      expect(html).toContain('data-saved-state="saved"');
      expect(html).toContain('aria-pressed="true"');
    });

    it('builds safe internal login redirect URLs and rejects unsafe paths', () => {
      Object.defineProperty(globalThis, 'window', {
        value: {
          location: {
            pathname: '/search',
            search: '?city=vellore&q=electrician',
          },
        },
        configurable: true,
        writable: true,
      });

      expect(buildSafeLoginRedirectUrl()).toBe(
        '/login?next=%2Fsearch%3Fcity%3Dvellore%26q%3Delectrician'
      );

      Object.defineProperty(globalThis, 'window', {
        value: {
          location: {
            pathname: '//evil.com',
            search: '',
          },
        },
        configurable: true,
        writable: true,
      });

      expect(buildSafeLoginRedirectUrl()).toBe('/login?next=%2F');
    });
  });

  describe('Storefront Card & Header Integrations', () => {
    it('integrates SaveBusinessButton into BusinessProfileActions', () => {
      const html = renderToStaticMarkup(
        React.createElement(BusinessProfileActions, {
          businessPublicId: VALID_ULID,
          businessName: 'Apex Electricals',
          canonicalUrl: 'https://neargrid.in/vellore/business/apex-electricals',
        })
      );

      expect(html).toContain(`data-testid="save-business-btn-${VALID_ULID}"`);
      expect(html).toContain('Share');
      expect(html).toContain('Copy Link');
    });

    it('integrates SaveBusinessButton into DirectoryListingCard', () => {
      const hit = makeSampleHit();
      const html = renderToStaticMarkup(
        React.createElement(DirectoryListingCard, { hit })
      );

      expect(html).toContain(`data-testid="save-business-btn-${VALID_ULID}"`);
      expect(html).toContain('Apex Electricals');
    });

    it('integrates SaveBusinessButton into HomeDirectoryCard', () => {
      const hit = makeSampleHit();
      const html = renderToStaticMarkup(
        React.createElement(HomeDirectoryCard, { hit })
      );

      expect(html).toContain(`data-testid="save-business-btn-${VALID_ULID}"`);
      expect(html).toContain('Apex Electricals');
    });

    it('renders Saved navigation link in CustomerHeader only for authenticated users', () => {
      const authedHtml = renderToStaticMarkup(
        React.createElement(CustomerHeader, { user: SAMPLE_USER, cities: [] })
      );
      expect(authedHtml).toContain('href="/saved"');
      expect(authedHtml).toContain('Saved');

      const guestHtml = renderToStaticMarkup(
        React.createElement(CustomerHeader, { user: null, cities: [] })
      );
      expect(guestHtml).not.toContain('href="/saved"');
    });
  });

  describe('Mapper Privacy & Optimistic Rollback Guarantees', () => {
    it('maps PublicBusinessProfileDto to SearchBusinessHitDto without private fields', () => {
      const profile = makeSampleProfile();
      const hit = mapPublicBusinessToSearchHit(profile);

      expect(hit.public_id).toBe(VALID_ULID);
      expect(hit.phone_masked).toBe('+9198400XXXXX');
      expect(hit.address_short).toBe('12 Railway Road, Katpadi');
      expect(hit.services_preview).toEqual(['House Wiring']);
      expect(JSON.stringify(hit)).not.toContain('9876543210');
    });

    it('implements optimistic rollback, unauthenticated guard, and 500-ID cap in SavedBusinessesContext', () => {
      const source = fs.readFileSync(
        path.join(FRONTEND_SRC, 'modules/customer/context/SavedBusinessesContext.tsx'),
        'utf8'
      );

      expect(source).toContain('SAVED_IDS_CAP = 500');
      expect(source).toContain('setSavedIds(prevSavedIds)');
      expect(source).toContain('setUnsavedOverrides(prevUnsavedOverrides)');
      expect(source).toContain("if (!isAuthenticated) {\n        return { ok: false, saved: false, error: 'UNAUTHENTICATED' };");
    });

    it('implements loading, error/retry, empty, and pagination states in SavedBusinessesHub', () => {
      const source = fs.readFileSync(
        path.join(FRONTEND_SRC, 'modules/customer/components/SavedBusinessesHub.tsx'),
        'utf8'
      );

      expect(source).toContain('saved-businesses-loading');
      expect(source).toContain('saved-businesses-error');
      expect(source).toContain('Retry');
      expect(source).toContain('saved-businesses-empty');
      expect(source).toContain('Saved businesses pagination');
    });
  });
});
