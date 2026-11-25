import { NextRequest } from 'next/server';
import { describe, expect, it, vi } from 'vitest';
import {
  extractTrustedRequestHost,
  isLocalDevHost,
  normalizeRequestHostname,
  resolveDomainContextForHost,
} from '@/lib/domains/host-resolver';
import { middleware } from '@/middleware';
import type { PublicResolvedDomainDto } from '@/shared/api/directory-types';

describe('M-A2 M3 Step 1: Frontend Host Resolution & Security', () => {
  it('normalizes valid hostnames and rejects malformed hosts, out-of-range ports, and injection payloads', () => {
    expect(normalizeRequestHostname('VELLORE.NearGrid.IN:3000')).toBe('vellore.neargrid.in');
    expect(normalizeRequestHostname('localhost:3000')).toBe('localhost');
    expect(normalizeRequestHostname('127.0.0.1:8080')).toBe('127.0.0.1');

    expect(normalizeRequestHostname(null)).toBeNull();
    expect(normalizeRequestHostname('')).toBeNull();
    expect(normalizeRequestHostname('example.com:0')).toBeNull();
    expect(normalizeRequestHostname('example.com:99999')).toBeNull();
    expect(normalizeRequestHostname('example.com:abc')).toBeNull();
    expect(normalizeRequestHostname('a.com, b.com')).toBeNull();
    expect(normalizeRequestHostname('evil.com/path')).toBeNull();
    expect(normalizeRequestHostname('evil.com?x=1')).toBeNull();
    expect(normalizeRequestHostname('evil.com\r\nX-Injected: 1')).toBeNull();
  });

  it('ignores X-Forwarded-Host unless TRUST_FORWARDED_HOST=true AND matching TRUSTED_PROXY_SECRET is presented', () => {
    const headers = new Headers({
      host: 'vellore.neargrid.in:3000',
      'x-forwarded-host': 'attacker.example.com',
    });

    // Without secret, even trustForwardedHost=true rejects spoofed X-Forwarded-Host
    expect(
      extractTrustedRequestHost(headers, { trustForwardedHost: true, trustedProxySecret: '' })
    ).toBe('vellore.neargrid.in');

    // Wrong secret rejects X-Forwarded-Host
    headers.set('x-trusted-proxy-secret', 'wrong');
    expect(
      extractTrustedRequestHost(headers, {
        trustForwardedHost: true,
        trustedProxySecret: 'super-secret-proxy-key',
      })
    ).toBe('vellore.neargrid.in');

    // Matching secret from trusted proxy accepts X-Forwarded-Host
    headers.set('x-trusted-proxy-secret', 'super-secret-proxy-key');
    expect(
      extractTrustedRequestHost(headers, {
        trustForwardedHost: true,
        trustedProxySecret: 'super-secret-proxy-key',
      })
    ).toBe('attacker.example.com');
  });

  it('restricts localhost/127.0.0.1 primary fallback to non-production environments', async () => {
    expect(isLocalDevHost('localhost', 'development')).toBe(true);
    expect(isLocalDevHost('127.0.0.1', 'test')).toBe(true);
    expect(isLocalDevHost('localhost', 'production')).toBe(false);
    expect(isLocalDevHost('127.0.0.1', 'production')).toBe(false);

    const notFound = vi.fn(async () => ({
      ok: false,
      status: 404,
      data: null,
      meta: null,
      error: { code: 'DOMAIN_NOT_FOUND', message: 'Not found' },
    }));

    const prodLocal = await resolveDomainContextForHost('localhost:3000', notFound, 'production');
    expect(prodLocal.kind).toBe('unregistered_or_blocked');
    expect(prodLocal.isIndexable).toBe(false);
  });

  it('resolves eligible domains from public API and blocks unregistered/suspended/unverified hosts', async () => {
    const mockDomain: PublicResolvedDomainDto = {
      public_id: '01TESTDOMAIN00000000000001',
      hostname: 'vellore.neargrid.in',
      domain_type: 'city_portal',
      is_primary: false,
      visibility_mode: 'location_scoped',
      default_locale: 'en',
      default_city: {
        public_id: '01TESTCITY0000000000000001',
        slug: 'vellore',
        name: 'Vellore',
      },
      default_state: null,
      canonical_origin: 'https://vellore.neargrid.in',
      is_canonical_for_location: true,
      is_indexable: true,
    };

    const fetcher = vi.fn(async (host: string) => {
      if (host === 'vellore.neargrid.in') {
        return { ok: true, status: 200, data: mockDomain, meta: null, error: null };
      }
      return {
        ok: false,
        status: 404,
        data: null,
        meta: null,
        error: { code: 'DOMAIN_NOT_FOUND', message: 'Not found' },
      };
    });

    const okCtx = await resolveDomainContextForHost('VELLORE.neargrid.in:443', fetcher, 'production');
    expect(okCtx.kind).toBe('resolved');
    expect(okCtx.domain?.default_city?.slug).toBe('vellore');
    expect(okCtx.isIndexable).toBe(true);

    const blockedCtx = await resolveDomainContextForHost('suspended.neargrid.in', fetcher, 'production');
    expect(blockedCtx.kind).toBe('unregistered_or_blocked');
    expect(blockedCtx.domain).toBeNull();
    expect(blockedCtx.isIndexable).toBe(false);

    const localCtx = await resolveDomainContextForHost('127.0.0.1:3000', fetcher, 'development');
    expect(localCtx.kind).toBe('primary_fallback');
    expect(localCtx.hostname).toBe('127.0.0.1');

    const invalidCtx = await resolveDomainContextForHost('evil.com/admin', fetcher, 'production');
    expect(invalidCtx.kind).toBe('invalid');
    expect(fetcher).toHaveBeenCalledTimes(3);
  });

  it('middleware overwrites spoofed x-neargrid-host and preserves dashboard auth & x-pathname', () => {
    const req = new NextRequest('https://vellore.neargrid.in/dashboard/leads?tab=new', {
      headers: {
        host: 'vellore.neargrid.in',
        'x-neargrid-host': 'spoofed-evil.example.com',
        cookie: 'lbd_sid=opaque-session-id',
      },
    });

    const res = middleware(req);
    expect(res.status).toBe(200);
    expect(res.headers.get('x-middleware-request-x-neargrid-host')).toBe('vellore.neargrid.in');
    expect(res.headers.get('x-middleware-request-x-pathname')).toBe('/dashboard/leads?tab=new');

    const badHostReq = new NextRequest('https://vellore.neargrid.in/dashboard', {
      headers: { host: 'evil.com/../../etc/passwd' },
    });
    expect(middleware(badHostReq).status).toBe(400);
  });

  it('fails closed on /dashboard routes when host is unregistered, suspended, unverified, or invalid', async () => {
    const notFoundErr = new Error('NEXT_NOT_FOUND');

    vi.resetModules();
    vi.doMock('next/headers', () => ({
      headers: () => new Headers({ host: 'suspended.neargrid.in', 'x-pathname': '/dashboard' }),
    }));
    vi.doMock('next/navigation', () => ({
      notFound: () => {
        throw notFoundErr;
      },
      redirect: vi.fn(),
    }));
    vi.doMock('@/lib/domains/host-resolver', async () => {
      const actual = await vi.importActual<typeof import('@/lib/domains/host-resolver')>(
        '@/lib/domains/host-resolver'
      );
      return {
        ...actual,
        getServerDomainContext: async () => ({
          kind: 'unregistered_or_blocked' as const,
          hostname: 'suspended.neargrid.in',
          domain: null,
          isIndexable: false,
        }),
      };
    });

    const { default: BusinessLayout } = await import('@/app/(business)/layout');
    await expect(BusinessLayout({ children: null })).rejects.toThrow('NEXT_NOT_FOUND');

    vi.doUnmock('next/headers');
    vi.doUnmock('next/navigation');
    vi.doUnmock('@/lib/domains/host-resolver');
  });
});
