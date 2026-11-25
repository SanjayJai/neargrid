import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

(globalThis as unknown as { React: typeof React }).React = React;

const mockFetchPublicSearch = vi.fn();
const mockFetchPublicSearchFilters = vi.fn();
const mockFetchPublicCities = vi.fn();
const mockFetchPublicCategories = vi.fn();
const mockFetchPublicLocalities = vi.fn();
const mockPermanentRedirect = vi.fn((target: string) => {
  throw new Error(`NEXT_REDIRECT:${target}`);
});

vi.mock('@/lib/api/public-directory', () => ({
  fetchPublicSearch: (...args: unknown[]) => mockFetchPublicSearch(...args),
  fetchPublicSearchFilters: (...args: unknown[]) => mockFetchPublicSearchFilters(...args),
  fetchPublicCities: (...args: unknown[]) => mockFetchPublicCities(...args),
  fetchPublicCategories: (...args: unknown[]) => mockFetchPublicCategories(...args),
  fetchPublicLocalities: (...args: unknown[]) => mockFetchPublicLocalities(...args),
}));

vi.mock('next/navigation', () => ({
  notFound: () => {
    throw new Error('NEXT_NOT_FOUND');
  },
  permanentRedirect: (target: string) => mockPermanentRedirect(target),
}));

import CityCategoryPage, {
  generateMetadata as generateCityCategoryMetadata,
} from '@/app/(customer)/[city]/[segment]/page';
import CityLocalityCategoryPage, {
  generateMetadata as generateCityLocalityCategoryMetadata,
} from '@/app/(customer)/[city]/[segment]/[category]/page';

describe('M-A0 Corrective Patch: Public Directory 301 Redirect Resolution', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockFetchPublicSearchFilters.mockResolvedValue({ ok: true, status: 200, data: null, meta: null, error: null });
    mockFetchPublicCities.mockResolvedValue({ ok: true, status: 200, data: [], meta: null, error: null });
    mockFetchPublicCategories.mockResolvedValue({ ok: true, status: 200, data: [], meta: null, error: null });
    mockFetchPublicLocalities.mockResolvedValue({ ok: true, status: 200, data: [], meta: null, error: null });
  });

  it('redirects /{oldCity}/{category} permanently when Laravel returns 301 safe target_path', async () => {
    mockFetchPublicSearch.mockResolvedValue({
      ok: false,
      status: 301,
      data: null,
      meta: null,
      error: { code: 'MOVED_PERMANENTLY', message: 'Moved' },
      redirectTargetPath: '/vellore-town/dentists',
    });

    const meta = await generateCityCategoryMetadata({
      params: { city: 'vellore', segment: 'dentists' },
      searchParams: {},
    });
    expect(meta.robots).toEqual({ index: false, follow: false });

    await expect(
      CityCategoryPage({
        params: { city: 'vellore', segment: 'dentists' },
        searchParams: {},
      })
    ).rejects.toThrow('NEXT_REDIRECT:/vellore-town/dentists');
    expect(mockPermanentRedirect).toHaveBeenCalledWith('/vellore-town/dentists');
  });

  it('redirects /{city}/{oldLocality}/{category} permanently when Laravel returns 301 safe target_path', async () => {
    mockFetchPublicSearch.mockResolvedValue({
      ok: false,
      status: 301,
      data: null,
      meta: null,
      error: { code: 'MOVED_PERMANENTLY', message: 'Moved' },
      redirectTargetPath: '/vellore-town/katpadi-west/dentists',
    });

    const meta = await generateCityLocalityCategoryMetadata({
      params: { city: 'vellore', segment: 'katpadi', category: 'dentists' },
      searchParams: {},
    });
    expect(meta.robots).toEqual({ index: false, follow: false });

    await expect(
      CityLocalityCategoryPage({
        params: { city: 'vellore', segment: 'katpadi', category: 'dentists' },
        searchParams: {},
      })
    ).rejects.toThrow('NEXT_REDIRECT:/vellore-town/katpadi-west/dentists');
    expect(mockPermanentRedirect).toHaveBeenCalledWith('/vellore-town/katpadi-west/dentists');
  });

  it('blocks unsafe external or protocol-relative redirectTargetPath values', async () => {
    for (const badTarget of ['https://attacker.example/phish', '//attacker.example/phish', '/vellore\\evil']) {
      vi.clearAllMocks();
      mockFetchPublicSearch.mockResolvedValue({
        ok: false,
        status: 301,
        data: null,
        meta: null,
        error: { code: 'MOVED_PERMANENTLY', message: 'Moved' },
        redirectTargetPath: badTarget,
      });

      await CityCategoryPage({
        params: { city: 'vellore', segment: 'dentists' },
        searchParams: {},
      });
      expect(mockPermanentRedirect).not.toHaveBeenCalled();
    }
  });
});
