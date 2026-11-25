import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it, vi, beforeEach } from 'vitest';

const mockCallLaravel = vi.fn();

vi.mock('@/lib/bff/laravel-client', () => ({
  callLaravelApi: (...args: unknown[]) => mockCallLaravel(...args),
}));

import {
  fetchPublicCategoryLanding,
  fetchPublicCategoryTree,
} from '@/lib/api/public-directory';

describe('M-A1 Milestone 3 DTOs, Horizontal Filters & Dynamic /c/[...slug] Pages', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('fetches 5-level CategoryTreeResponseDto with collections via fetchPublicCategoryTree', async () => {
    mockCallLaravel.mockResolvedValueOnce({
      ok: true,
      status: 200,
      payload: {
        status: 'success',
        data: {
          categories: [
            {
              public_id: '01CATMAIN',
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
              children: [],
            },
          ],
          collections: [
            {
              public_id: '01COL1',
              slug: 'pure-veg',
              path: '/c/collections/pure-veg',
              name: 'Pure Veg Dining',
              name_en: 'Pure Veg Dining',
              icon_name: 'sparkles',
              show_on_homepage: true,
              is_popular: true,
              category_slugs: ['restaurants'],
            },
          ],
        },
      },
    });

    const res = await fetchPublicCategoryTree('en');
    expect(res.ok).toBe(true);
    expect(res.data?.categories[0].node_type).toBe('main_category');
    expect(res.data?.collections[0].slug).toBe('pure-veg');
  });

  it('resolves CategoryLandingResponseDto including 301 redirect and 404 unpublished handling', async () => {
    mockCallLaravel.mockResolvedValueOnce({
      ok: true,
      status: 200,
      payload: {
        status: 'success',
        data: {
          redirect: true,
          http_status: 301,
          target_path: '/c/green-organic-cafes',
        },
      },
    });

    const redirectRes = await fetchPublicCategoryLanding('organic-cafes', 'en');
    expect(redirectRes.ok).toBe(true);
    expect(redirectRes.data?.redirect).toBe(true);
    expect(redirectRes.data?.target_path).toBe('/c/green-organic-cafes');

    mockCallLaravel.mockResolvedValueOnce({
      ok: false,
      status: 404,
      payload: {
        status: 'error',
        error: { code: 'CATEGORY_NOT_FOUND', message: 'Category not found.' },
      },
    });

    const notFoundRes = await fetchPublicCategoryLanding('draft-secret-cat', 'en');
    expect(notFoundRes.ok).toBe(false);
    expect(notFoundRes.status).toBe(404);
  });

  it('removes left SearchFilterSidebar from SearchResultsClient, CanonicalDirectoryView, and CategoryLandingView', () => {
    const root = path.resolve(process.cwd(), 'src');
    const listingFiles = [
      'modules/customer/components/SearchResultsClient.tsx',
      'modules/customer/components/CanonicalDirectoryView.tsx',
      'modules/customer/components/CategoryLandingView.tsx',
    ];

    for (const rel of listingFiles) {
      const content = fs.readFileSync(path.join(root, rel), 'utf8');
      expect(content).not.toContain('SearchFilterSidebar');
      expect(content).toContain('HorizontalFilterBar');
    }
  });

  it('implements /c/[...slug] dynamic category page with SEO metadata, JSON-LD, 301 redirect, and 404', () => {
    const root = path.resolve(process.cwd(), 'src');
    const pageContent = fs.readFileSync(
      path.join(root, 'app/(customer)/c/[...slug]/page.tsx'),
      'utf8'
    );
    expect(pageContent).toContain('generateMetadata');
    expect(pageContent).toContain('permanentRedirect');
    expect(pageContent).toContain('notFound');
    expect(pageContent).toContain('application/ld+json');
  });
});
