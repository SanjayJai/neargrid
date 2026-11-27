import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ALL_ADMIN_NAV_ITEMS } from '@/modules/admin/lib/admin-navigation';

const requireActiveBffSessionMock = vi.fn();
const callLaravelApiMock = vi.fn();

vi.mock('@/lib/bff/route-helpers', async () => {
  const actual = await vi.importActual<typeof import('@/lib/bff/route-helpers')>(
    '@/lib/bff/route-helpers'
  );
  return {
    ...actual,
    requireActiveBffSession: (...args: unknown[]) => requireActiveBffSessionMock(...args),
  };
});

vi.mock('@/lib/bff/laravel-client', () => ({
  callLaravelApi: (...args: unknown[]) => callLaravelApiMock(...args),
}));

describe('M-F2 M3 navigation & BFF proxy', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('enables all M3 navigation items while keeping M4-M9 disabled', () => {
    const m3Items = ALL_ADMIN_NAV_ITEMS.filter((item) => item.milestone === 'M3');
    expect(m3Items).toHaveLength(9);
    expect(m3Items.every((item) => item.enabled)).toBe(true);

    const laterItems = ALL_ADMIN_NAV_ITEMS.filter(
      (item) => !['M1', 'M2', 'M3', 'M4'].includes(item.milestone)
    );
    expect(laterItems.length).toBeGreaterThan(0);
    expect(laterItems.every((item) => !item.enabled)).toBe(true);
  });

  it('rejects disallowed admin prefixes and path traversal in BFF proxy', async () => {
    requireActiveBffSessionMock.mockResolvedValue({
      ok: true,
      opaqueSessionId: 'opaque-1',
      record: { accessToken: 'secret-token' },
    });

    const { GET } = await import('@/app/api/admin/[...path]/route');

    const badPrefix = await GET(new Request('http://localhost/api/admin/billing'), {
      params: Promise.resolve({ path: ['billing'] }),
    });
    expect(badPrefix.status).toBe(404);

    const badSegment = await GET(new Request('http://localhost/api/admin/businesses/..'), {
      params: Promise.resolve({ path: ['businesses', '..'] }),
    });
    expect(badSegment.status).toBe(400);

    const embeddedTraversal = await GET(new Request('http://localhost/api/admin/businesses/foo..bar'), {
      params: Promise.resolve({ path: ['businesses', 'foo..bar'] }),
    });
    expect(embeddedTraversal.status).toBe(400);

    const backslashSegment = await GET(new Request('http://localhost/api/admin/businesses/a%5Cb'), {
      params: Promise.resolve({ path: ['businesses', 'a\\b'] }),
    });
    expect(backslashSegment.status).toBe(400);
    expect(callLaravelApiMock).not.toHaveBeenCalled();
  });

  it('rejects unauthenticated requests and enforces private no-store caching', async () => {
    requireActiveBffSessionMock.mockResolvedValue({
      ok: false,
      response: new Response(JSON.stringify({ status: 'error' }), { status: 401 }),
    });

    const { GET } = await import('@/app/api/admin/[...path]/route');
    const unauth = await GET(new Request('http://localhost/api/admin/businesses'), {
      params: Promise.resolve({ path: ['businesses'] }),
    });
    expect(unauth.status).toBe(401);
    expect(callLaravelApiMock).not.toHaveBeenCalled();
  });

  it('forwards allowed M3 requests with server-side bearer token', async () => {
    requireActiveBffSessionMock.mockResolvedValue({
      ok: true,
      opaqueSessionId: 'opaque-1',
      record: { accessToken: 'secret-sanctum-token' },
    });
    callLaravelApiMock.mockResolvedValue({
      ok: true,
      status: 200,
      payload: { status: 'success', data: [] },
    });

    const { GET } = await import('@/app/api/admin/[...path]/route');
    const res = await GET(new Request('http://localhost/api/admin/businesses?status=active'), {
      params: Promise.resolve({ path: ['businesses'] }),
    });

    expect(res.status).toBe(200);
    expect(callLaravelApiMock).toHaveBeenCalledWith(
      '/api/v1/admin/businesses?status=active',
      expect.objectContaining({
        method: 'GET',
        bearerToken: 'secret-sanctum-token',
      })
    );
  });
});
