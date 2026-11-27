import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import {
  CategoryExplorerSection,
  filterCategoryTree,
} from '@/modules/customer/components/CategoryExplorerSection';
import { CategoryLandingView } from '@/modules/customer/components/CategoryLandingView';
import { CustomerProfileView } from '@/modules/customer/components/CustomerProfileView';
import {
  resolveBusinessProfileHref,
  resolveCityPortalHref,
} from '@/lib/domains/cross-domain-nav';
import type {
  CategoryCollectionDto,
  CategoryTreeNodeDto,
  PublicResolvedDomainDto,
} from '@/shared/api/directory-types';

(globalThis as unknown as { React: typeof React }).React = React;

vi.mock('next/link', () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) =>
    React.createElement('a', { href, ...props }, children),
}));

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), prefetch: vi.fn() }),
}));

const sampleTree: CategoryTreeNodeDto[] = [
  {
    public_id: '01CATMAIN00000000000000001',
    slug: 'restaurants',
    path: '/c/restaurants',
    name: 'Restaurants',
    name_en: 'Restaurants',
    node_type: 'main_category',
    depth: 0,
    schema_org_type: 'Restaurant',
    icon_name: 'utensils',
    is_assignable: true,
    has_landing_page: true,
    show_on_homepage: true,
    is_popular: true,
    is_featured: true,
    sort_order: 1,
    children: [
      {
        public_id: '01CATGRP000000000000000001',
        slug: 'restaurant-types',
        path: '/c/restaurants/restaurant-types',
        name: 'Restaurant Types',
        name_en: 'Restaurant Types',
        node_type: 'category_group',
        depth: 1,
        schema_org_type: 'Restaurant',
        icon_name: null,
        is_assignable: false,
        has_landing_page: false,
        show_on_homepage: true,
        is_popular: false,
        is_featured: false,
        sort_order: 1,
        children: [
          {
            public_id: '01CATSUB000000000000000001',
            slug: 'biryani',
            path: '/c/restaurants/biryani',
            name: 'Biryani',
            name_en: 'Biryani',
            node_type: 'subcategory',
            depth: 2,
            schema_org_type: 'Restaurant',
            icon_name: null,
            is_assignable: true,
            has_landing_page: true,
            show_on_homepage: true,
            is_popular: true,
            is_featured: false,
            sort_order: 1,
            children: [],
          },
        ],
      },
    ],
  },
];

const sampleCollections: CategoryCollectionDto[] = [
  {
    public_id: '01COL000000000000000000001',
    slug: 'pure-veg',
    path: '/c/collections/pure-veg',
    name: 'Pure Veg Dining',
    name_en: 'Pure Veg Dining',
    icon_name: null,
    show_on_homepage: true,
    is_popular: true,
    category_slugs: ['restaurants', 'biryani'],
  },
];

describe('M-F1 Dual-Panel Category Explorer & Customer Account Pages', () => {
  it('filters 5-level category tree with full hierarchy breadcrumbs', () => {
    const matches = filterCategoryTree(sampleTree, 'biryani');
    expect(matches).toHaveLength(1);
    expect(matches[0].node.slug).toBe('biryani');
    expect(matches[0].breadcrumb).toEqual([
      'Restaurants',
      'Restaurant Types',
      'Biryani',
    ]);
  });

  it('renders dual-panel tablist/tabpanel explorer, collections, and /c/ links with city context', () => {
    const html = renderToStaticMarkup(
      React.createElement(CategoryExplorerSection, {
        categories: sampleTree,
        collections: sampleCollections,
        citySlug: 'chennai',
        cityLabel: 'Chennai',
        locale: 'en',
      })
    );

    expect(html).toContain('role="tablist"');
    expect(html).toContain('role="tabpanel"');
    expect(html).toContain('Pure Veg Dining');
    expect(html).toContain('/c/restaurants/biryani?city=chennai');
    expect(html).toContain('/c/collections/pure-veg?city=chennai');
  });

  it('renders CustomerProfileView with customer shortcuts and hides dashboard link for pure customer', () => {
    const customerHtml = renderToStaticMarkup(
      React.createElement(CustomerProfileView, {
        initialUser: {
          public_id: '01USER00000000000000000001',
          name: 'Priya Kumar',
          email: 'priya@example.com',
          phone_e164: '+919876543210',
          phone_verified_at: null,
          avatar_url: null,
          roles: ['customer'],
          permissions: [],
          created_at: null,
        },
      })
    );

    expect(customerHtml).toContain('My Account');
    expect(customerHtml).toContain('/saved');
    expect(customerHtml).toContain('/account/enquiries');
    expect(customerHtml).toContain('/notifications');
    expect(customerHtml).not.toContain('/dashboard/settings');

    const ownerHtml = renderToStaticMarkup(
      React.createElement(CustomerProfileView, {
        initialUser: {
          public_id: '01USER00000000000000000002',
          name: 'Arun Raj',
          email: 'arun@example.com',
          phone_e164: '+919876543211',
          phone_verified_at: null,
          avatar_url: null,
          roles: ['business_owner'],
          permissions: [],
          created_at: null,
        },
      })
    );

    expect(ownerHtml).toContain('/dashboard/settings');
  });

  it('preserves canonical domain and destination across registered domains', () => {
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

    expect(
      resolveCityPortalHref({
        targetCitySlug: 'chennai',
        currentDomain: vellorePortal,
        currentPath: '/c/restaurants/biryani',
        queryString: 'locale=ta',
        nodeEnv: 'production',
      })
    ).toBe('https://neargrid.in/c/restaurants/biryani?locale=ta&city=chennai');

    expect(
      resolveBusinessProfileHref({
        citySlug: 'chennai',
        businessSlug: 'apollo-hospital',
        canonicalUrl: 'https://chennai.neargrid.in/chennai/business/apollo-hospital',
        currentHost: 'vellore.neargrid.in',
        locale: 'ta',
        nodeEnv: 'production',
      })
    ).toBe('https://chennai.neargrid.in/chennai/business/apollo-hospital?locale=ta');
  });

  it('preserves selected city on CategoryLandingView child subcategory/speciality /c/ links', () => {
    const html = renderToStaticMarkup(
      React.createElement(CategoryLandingView, {
        landing: {
          redirect: false,
          kind: 'category',
          category: {
            public_id: '01CATMAIN00000000000000001',
            slug: 'restaurants',
            path: '/c/restaurants',
            name: 'Restaurants',
            node_type: 'main_category',
            depth: 0,
            schema_org_type: 'Restaurant',
            has_landing_page: true,
            children: [
              {
                public_id: '01CATSUB000000000000000001',
                slug: 'biryani',
                path: '/c/restaurants/biryani',
                name: 'Biryani',
                node_type: 'subcategory',
              },
              {
                public_id: '01CATSPEC00000000000000001',
                slug: 'hyderabadi-biryani',
                path: '/c/restaurants/biryani/hyderabadi-biryani',
                name: 'Hyderabadi Biryani',
                node_type: 'speciality',
              },
            ],
          },
        },
        cities: [
          { slug: 'chennai', name: 'Chennai', name_en: 'Chennai', latitude: 13.08, longitude: 80.27, is_popular: true },
          { slug: 'vellore', name: 'Vellore', name_en: 'Vellore', latitude: 12.91, longitude: 79.13, is_popular: true },
        ],
        selectedCitySlug: 'vellore',
        selectedCityName: 'Vellore',
        hits: [],
        meta: null,
        filtersData: null,
        currentParams: { city: 'vellore', category: 'restaurants' },
        basePath: '/c/restaurants',
        breadcrumbs: [
          { label: 'Home', href: '/?city=vellore' },
          { label: 'Restaurants' },
        ],
      })
    );

    expect(html).toContain('/c/restaurants/biryani?city=vellore');
    expect(html).toContain('/c/restaurants/biryani/hyderabadi-biryani?city=vellore');
    expect(html).toContain('/?city=vellore');
  });
});
