import fs from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';
import { getCategoryVisual } from '@/modules/customer/lib/category-icons';
import {
  buildShareableSearchUrl,
  isValidCoordinatePair,
  normalizeLocale,
  sanitizeSearchText,
  sanitizeSlug,
} from '@/modules/customer/lib/search-url';

describe('Step 2: Shareable Search URL & Parameter Validation', () => {
  it('builds clean shareable search URLs for English, Tamil, and Tanglish queries', () => {
    expect(
      buildShareableSearchUrl({
        city: 'chennai',
        locality: 't-nagar',
        category: 'plumbers',
        q: 'பிளம்பர்',
        locale: 'ta',
      })
    ).toBe(
      '/search?city=chennai&locality=t-nagar&category=plumbers&q=%E0%AE%AA%E0%AE%BF%E0%AE%B3%E0%AE%AE%E0%AF%8D%E0%AE%AA%E0%AE%B0%E0%AF%8D&locale=ta'
    );

    expect(
      buildShareableSearchUrl({
        lat: 13.0418,
        lng: 80.2341,
        radiusKm: 10,
        sort: 'distance',
        locale: 'en',
      })
    ).toBe('/search?lat=13.041800&lng=80.234100&radius_km=10&sort=distance');
  });

  it('sanitizes slugs, queries, locales, and rejects invalid coordinates or raw sort expressions', () => {
    expect(sanitizeSlug('chennai')).toBe('chennai');
    expect(sanitizeSlug("chennai' OR 1=1")).toBeNull();
    expect(sanitizeSlug('../admin')).toBeNull();

    expect(sanitizeSearchText("  plambar \x00\x07  ")).toBe('plambar');
    expect(normalizeLocale('ta')).toBe('ta');
    expect(normalizeLocale('fr')).toBe('en');

    expect(isValidCoordinatePair(13.0418, 80.2341)).toBe(true);
    expect(isValidCoordinatePair(95, 80.2341)).toBe(false);
    expect(isValidCoordinatePair(13.0418, 185)).toBe(false);
    expect(isValidCoordinatePair(Number.NaN, 80.2)).toBe(false);

    // Invalid sort expression & invalid coords are silently omitted instead of passed through
    expect(
      buildShareableSearchUrl({
        city: 'chennai',
        lat: 999,
        lng: 999,
        sort: 'id; DROP TABLE businesses',
      })
    ).toBe('/search?city=chennai');
  });

  it('maps Laravel seeded category icons with safe default fallback', () => {
    const plumberVisual = getCategoryVisual('droplet');
    expect(plumberVisual.bgClass).toContain('bg-');
    expect(plumberVisual.icon).toBeDefined();

    const fallbackVisual = getCategoryVisual('unknown-icon-slug');
    expect(fallbackVisual.icon).toBeDefined();
  });
});

describe('Step 2: Live Data & Geolocation Privacy Guardrails', () => {
  const root = process.cwd();

  it('homepage, header, and footer import zero demo-directory fixtures', () => {
    const step2Files = [
      'src/app/(customer)/page.tsx',
      'src/app/(customer)/layout.tsx',
      'src/modules/customer/components/CustomerHeader.tsx',
      'src/modules/customer/components/CustomerFooter.tsx',
      'src/modules/customer/components/LiveSearchBar.tsx',
      'src/modules/customer/components/HomeDirectoryCard.tsx',
    ];

    for (const relPath of step2Files) {
      const content = fs.readFileSync(path.resolve(root, relPath), 'utf8');
      expect(content, `${relPath} must not reference demo-directory`).not.toContain('demo-directory');
    }
  });

  it('LiveSearchBar implements debounce, AbortController, and user-initiated geolocation only', () => {
    const content = fs.readFileSync(
      path.resolve(root, 'src/modules/customer/components/LiveSearchBar.tsx'),
      'utf8'
    );

    expect(content).toContain('new AbortController()');
    expect(content).toContain('controller.abort()');
    expect(content).toContain('DEBOUNCE_MS');
    expect(content).toContain('/api/public/search/suggestions');
    // Ensure geolocation is only inside click handler, never inside useEffect
    expect(content).toContain('function handleNearMeClick()');
    const useEffects = content.match(/useEffect\([\s\S]*?\}, \[[^\]]*\]\);/g) ?? [];
    for (const effectBlock of useEffects) {
      expect(effectBlock).not.toContain('geolocation');
    }
  });

  it('CustomerHeader requests geolocation only on explicit Near Me click', () => {
    const content = fs.readFileSync(
      path.resolve(root, 'src/modules/customer/components/CustomerHeader.tsx'),
      'utf8'
    );
    expect(content).toContain('function handleNearMe()');
    const useEffects = content.match(/useEffect\([\s\S]*?\}, \[[^\]]*\]\);/g) ?? [];
    for (const effectBlock of useEffects) {
      expect(effectBlock).not.toContain('geolocation');
    }
  });

  it('isolates dev and production Next.js distDir and enforces server fetch timeout', () => {
    const nextConfig = fs.readFileSync(path.resolve(root, 'next.config.mjs'), 'utf8');
    expect(nextConfig).toContain('PHASE_DEVELOPMENT_SERVER');
    expect(nextConfig).toContain("'.next-dev'");
    expect(nextConfig).toContain("'.next'");

    const laravelClient = fs.readFileSync(path.resolve(root, 'src/lib/bff/laravel-client.ts'), 'utf8');
    expect(laravelClient).toContain('timeoutMs');
    expect(laravelClient).toContain('timeoutController.abort()');
  });
});
