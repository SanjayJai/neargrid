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

describe('M-F2 M4 users, roles, permissions & security BFF', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('enables all M4 navigation items while keeping M5-M9 disabled', () => {
    const m4Items = ALL_ADMIN_NAV_ITEMS.filter((item) => item.milestone === 'M4');
    expect(m4Items.length).toBeGreaterThanOrEqual(4);
    expect(m4Items.every((item) => item.enabled)).toBe(true);

    const laterItems = ALL_ADMIN_NAV_ITEMS.filter(
      (item) => !['M1', 'M2', 'M3', 'M4'].includes(item.milestone)
    );
    expect(laterItems.length).toBeGreaterThan(0);
    expect(laterItems.every((item) => !item.enabled)).toBe(true);
  });

  it('enforces CSRF/origin security on mutating M4 BFF calls and forwards valid requests', async () => {
    enforceMutationSecurityMock.mockReturnValueOnce(
      new Response(JSON.stringify({ status: 'error' }), { status: 403 })
    );

    const { PATCH, GET } = await import('@/app/api/admin/[...path]/route');
    const blocked = await PATCH(
      new Request('http://localhost/api/admin/users/01JTEST/status', {
        method: 'PATCH',
        body: JSON.stringify({ status: 'suspended' }),
      }),
      { params: Promise.resolve({ path: ['users', '01JTEST', 'status'] }) }
    );
    expect(blocked.status).toBe(403);
    expect(callLaravelApiMock).not.toHaveBeenCalled();

    enforceMutationSecurityMock.mockReturnValue(null);
    requireActiveBffSessionMock.mockResolvedValue({
      ok: true,
      opaqueSessionId: 'opaque-1',
      record: { accessToken: 'sanctum-secret' },
    });
    callLaravelApiMock.mockResolvedValue({
      ok: true,
      status: 200,
      payload: { status: 'success', data: [] },
    });

    const okRes = await GET(new Request('http://localhost/api/admin/security/sessions'), {
      params: Promise.resolve({ path: ['security', 'sessions'] }),
    });
    expect(okRes.status).toBe(200);
    expect(callLaravelApiMock).toHaveBeenCalledWith(
      '/api/v1/admin/security/sessions',
      expect.objectContaining({ bearerToken: 'sanctum-secret' })
    );
  });
});
