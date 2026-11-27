import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ALL_ADMIN_NAV_ITEMS } from '@/modules/admin/lib/admin-navigation';

const requireActiveBffSessionMock = vi.fn();
const enforceMutationSecurityMock = vi.fn();
const callLaravelApiMock = vi.fn();

vi.mock('@/lib/bff/route-helpers', async () => {
  const actual = await vi.importActual<typeof import('@/lib/bff/route-helpers')>(
    '@/lib/bff/route-helpers'
  );
  return {
    ...actual,
    requireActiveBffSession: (...args: unknown[]) => requireActiveBffSessionMock(...args),
    enforceMutationSecurity: (...args: unknown[]) => enforceMutationSecurityMock(...args),
  };
});

vi.mock('@/lib/bff/laravel-client', () => ({
  callLaravelApi: (...args: unknown[]) => callLaravelApiMock(...args),
}));

describe('M-F2 M5.2 campaign & creative administration', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('enables ad-campaigns, ad-creatives, and ad-placements in admin navigation', () => {
    const campaigns = ALL_ADMIN_NAV_ITEMS.find((i) => i.key === 'ad-campaigns');
    const creatives = ALL_ADMIN_NAV_ITEMS.find((i) => i.key === 'ad-creatives');
    const placements = ALL_ADMIN_NAV_ITEMS.find((i) => i.key === 'ad-placements');

    expect(campaigns?.enabled).toBe(true);
    expect(campaigns?.href).toBe('/admin/advertising/campaigns');
    expect(creatives?.enabled).toBe(true);
    expect(creatives?.href).toBe('/admin/advertising/creatives');
    expect(placements?.enabled).toBe(true);
    expect(placements?.href).toBe('/admin/advertising/placements');
  });

  it('forwards advertising BFF requests with server-side bearer token and enforces mutation security', async () => {
    enforceMutationSecurityMock.mockReturnValue(null);
    requireActiveBffSessionMock.mockResolvedValue({
      ok: true,
      opaqueSessionId: 'opaque-1',
      record: { accessToken: 'sanctum-token-m52' },
    });
    callLaravelApiMock.mockResolvedValue({
      ok: true,
      status: 200,
      payload: { status: 'success', data: [] },
    });

    const { GET, POST, PATCH, DELETE } = await import('@/app/api/admin/[...path]/route');
    const res = await GET(new Request('http://localhost/api/admin/advertising/campaigns'), {
      params: Promise.resolve({ path: ['advertising', 'campaigns'] }),
    });
    expect(res.status).toBe(200);
    expect(callLaravelApiMock).toHaveBeenCalledWith(
      '/api/v1/admin/advertising/campaigns',
      expect.objectContaining({ bearerToken: 'sanctum-token-m52' })
    );

    const patchRes = await PATCH(
      new Request('http://localhost/api/admin/advertising/creatives/01JCREATIVE0000000000000001', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ title: 'Updated Creative' }),
      }),
      { params: Promise.resolve({ path: ['advertising', 'creatives', '01JCREATIVE0000000000000001'] }) }
    );
    expect(patchRes.status).toBe(200);
    expect(callLaravelApiMock).toHaveBeenCalledWith(
      '/api/v1/admin/advertising/creatives/01JCREATIVE0000000000000001',
      expect.objectContaining({ method: 'PATCH', bearerToken: 'sanctum-token-m52' })
    );

    const deleteRes = await DELETE(
      new Request('http://localhost/api/admin/advertising/creatives/01JCREATIVE0000000000000001', {
        method: 'DELETE',
      }),
      { params: Promise.resolve({ path: ['advertising', 'creatives', '01JCREATIVE0000000000000001'] }) }
    );
    expect(deleteRes.status).toBe(200);
    expect(callLaravelApiMock).toHaveBeenCalledWith(
      '/api/v1/admin/advertising/creatives/01JCREATIVE0000000000000001',
      expect.objectContaining({ method: 'DELETE', bearerToken: 'sanctum-token-m52' })
    );

    enforceMutationSecurityMock.mockReturnValueOnce(
      new Response(JSON.stringify({ status: 'error' }), { status: 403 })
    );
    const blocked = await POST(
      new Request('http://localhost/api/admin/advertising/campaigns', { method: 'POST' }),
      { params: Promise.resolve({ path: ['advertising', 'campaigns'] }) }
    );
    expect(blocked.status).toBe(403);
  });

  it('enforces mutation security and session checks on creative upload-image route', async () => {
    enforceMutationSecurityMock.mockReturnValueOnce(
      new Response(JSON.stringify({ status: 'error' }), { status: 403 })
    );
    const { POST } = await import('@/app/api/admin/advertising/creatives/upload-image/route');
    const blocked = await POST(
      new Request('http://localhost/api/admin/advertising/creatives/upload-image', { method: 'POST' })
    );
    expect(blocked.status).toBe(403);

    enforceMutationSecurityMock.mockReturnValue(null);
    requireActiveBffSessionMock.mockResolvedValueOnce({
      ok: false,
      response: new Response(JSON.stringify({ status: 'error' }), { status: 401 }),
    });
    const unauth = await POST(
      new Request('http://localhost/api/admin/advertising/creatives/upload-image', { method: 'POST' })
    );
    expect(unauth.status).toBe(401);
  });
});
