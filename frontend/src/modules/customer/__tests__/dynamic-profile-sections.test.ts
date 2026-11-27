import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

(globalThis as unknown as { React: typeof React }).React = React;
import { BusinessProfileView } from '@/modules/customer/components/BusinessProfileView';
import type { PublicBusinessProfileDto } from '@/shared/api/directory-types';

vi.mock('next/link', () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) =>
    React.createElement('a', { href, ...props }, children),
}));

function makeBaseProfile(overrides: Partial<PublicBusinessProfileDto> = {}): PublicBusinessProfileDto {
  return {
    public_id: '01JTESTBUSINESS00000000001',
    name: 'Apollo Care Clinic',
    slug: 'apollo-care-clinic',
    tagline: 'Trusted care in Chennai',
    description: 'Full-service family clinic.',
    year_established: 2015,
    is_claimed: true,
    is_verified: true,
    is_trusted: false,
    rating_avg: 4.6,
    review_count: 12,
    photo_count: 0,
    completeness_score: 85,
    is_open_now: true,
    primary_category: {
      public_id: '01JTESTCAT0000000000000001',
      slug: 'clinics',
      name: 'Clinics',
      schema_org_type: 'MedicalClinic',
    },
    categories: [
      {
        public_id: '01JTESTCAT0000000000000001',
        slug: 'clinics',
        name: 'Clinics',
        is_primary: true,
      },
    ],
    address: {
      line_1: '10 Anna Salai',
      line_2: null,
      landmark: null,
      locality: { slug: 't-nagar', name: 'T. Nagar' },
      city: { slug: 'chennai', name: 'Chennai' },
      pincode: '600017',
      coordinates: { lat: 13.04, lng: 80.23 },
    },
    contact: {
      phone_masked: '+91 98XXXXXX10',
      has_whatsapp: true,
      website_url: 'https://example.com',
    },
    hours: [],
    services: [],
    amenities: [],
    payment_methods: [],
    faqs: [],
    media: [],
    ...overrides,
  };
}

describe('BusinessProfileView dynamic sections & XSS safety', () => {
  it('preserves default storefront sections when no category sections are configured', () => {
    const html = renderToStaticMarkup(
      React.createElement(BusinessProfileView, {
        profile: makeBaseProfile({ profile_sections: [], attributes: [] }),
        citySlug: 'chennai',
        canonicalPath: '/chennai/business/apollo-care-clinic',
        canonicalUrl: 'https://neargrid.in/chennai/business/apollo-care-clinic',
        breadcrumbs: [{ label: 'Home', href: '/' }],
        isAuthenticated: false,
      })
    );

    expect(html).toContain('Overview');
    expect(html).toContain('Services &amp; Pricing');
    expect(html).toContain('Opening Hours');
    expect(html).toContain('Frequently Asked Questions');
  });

  it('renders resolved sections in sort_order, localized labels, hides omitted sections, and escapes attribute HTML', () => {
    const html = renderToStaticMarkup(
      React.createElement(BusinessProfileView, {
        profile: makeBaseProfile({
          profile_sections: [
            {
              section_key: 'clinic_specs',
              label: 'மருத்துவமனை விவரங்கள்',
              display_label_en: 'Clinic Specifications',
              display_label_ta: 'மருத்துவமனை விவரங்கள்',
              is_required: false,
              suppress_when_empty: true,
              sort_order: 1,
              has_content: true,
              attributes: [
                {
                  definition_public_id: '01JATTR0000000000000000001',
                  attribute_key: 'emergency_24x7',
                  section_key: 'clinic_specs',
                  label: '24x7 அவசர சிகிச்சை',
                  label_en: '24x7 Emergency',
                  label_ta: '24x7 அவசர சிகிச்சை',
                  data_type: 'boolean',
                  unit: null,
                  value: true,
                  formatted_value: 'Yes',
                  sort_order: 1,
                },
                {
                  definition_public_id: '01JATTR0000000000000000002',
                  attribute_key: 'reg_note',
                  section_key: 'clinic_specs',
                  label: 'Registration Note',
                  label_en: 'Registration Note',
                  label_ta: null,
                  data_type: 'string',
                  unit: null,
                  value: '<script>alert(1)</script>',
                  formatted_value: '<script>alert(1)</script>',
                  sort_order: 2,
                },
              ],
            },
            {
              section_key: 'overview',
              label: 'மருத்துவமனை அறிமுகம்',
              display_label_en: 'Clinic Overview',
              display_label_ta: 'மருத்துவமனை அறிமுகம்',
              is_required: false,
              suppress_when_empty: true,
              sort_order: 2,
              has_content: true,
              attributes: [],
            },
          ],
          attributes: [],
        }),
        citySlug: 'chennai',
        canonicalPath: '/chennai/business/apollo-care-clinic',
        canonicalUrl: 'https://neargrid.in/chennai/business/apollo-care-clinic',
        breadcrumbs: [{ label: 'Home', href: '/' }],
        isAuthenticated: false,
      })
    );

    const clinicSpecsIndex = html.indexOf('மருத்துவமனை விவரங்கள்');
    const overviewIndex = html.indexOf('மருத்துவமனை அறிமுகம்');
    expect(clinicSpecsIndex).toBeGreaterThan(-1);
    expect(overviewIndex).toBeGreaterThan(clinicSpecsIndex);

    expect(html).toContain('24x7 அவசர சிகிச்சை');
    // Suppressed sections not in profile_sections must not render
    expect(html).not.toContain('id="services"');
    // Script tags must be escaped, never raw HTML
    expect(html).not.toContain('<script>alert(1)</script>');
    expect(html).toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
  });
});
