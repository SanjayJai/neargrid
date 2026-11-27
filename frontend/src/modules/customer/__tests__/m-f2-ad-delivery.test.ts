import { beforeEach, describe, expect, it, vi } from 'vitest';

const callLaravelApiMock = vi.fn();
const enforceMutationSecurityMock = vi.fn();
const requireActiveBffSessionMock = vi.fn();

vi.mock('@/lib/bff/laravel-client', () => ({
  callLaravelApi: (...args: unknown[]) => callLaravelApiMock(...args),
}));

vi.mock('@/lib/bff/route-helpers', async () => {
  const actual = await vi.importActual<typeof import('@/lib/bff/route-helpers')>(
    '@/lib/bff/route-helpers'
  );
  return {
    ...actual,
    enforceMutationSecurity: (...args: unknown[]) => enforceMutationSecurityMock(...args),
    requireActiveBffSession: (...args: unknown[]) => requireActiveBffSessionMock(...args),
  };
});

describe('M-F2 M5.3 public ad delivery & event tracking BFF', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    enforceMutationSecurityMock.mockReturnValue(null);
    requireActiveBffSessionMock.mockResolvedValue({ ok: false });
  });

  it('forwards public ad delivery requests and falls back safely when upstream fails', async () => {
    callLaravelApiMock.mockResolvedValueOnce({
      ok: true,
      status: 200,
      payload: { status: 'success', data: { placements: {} } },
    });

    const { GET } = await import('@/app/api/public/ads/deliver/route');
    const res = await GET(
      new Request('http://localhost/api/public/ads/deliver?page_type=home&placement_keys[]=home.featured_carousel')
    );
    expect(res.status).toBe(200);

    callLaravelApiMock.mockResolvedValueOnce({ ok: false, status: 503, payload: null });
    const fallback = await GET(
      new Request('http://localhost/api/public/ads/deliver?page_type=home&placement_keys[]=home.featured_carousel')
    );
    expect(fallback.status).toBe(200);
    expect(await fallback.json()).toEqual({ status: 'success', data: { placements: {} } });
  });

  it('validates event payloads and forwards impression/click tracking', async () => {
    const { POST } = await import('@/app/api/public/ads/events/route');

    const bad = await POST(
      new Request('http://localhost/api/public/ads/events', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event_type: 'invalid', token: 'abc' }),
      })
    );
    expect(bad.status).toBe(422);

    callLaravelApiMock.mockResolvedValueOnce({
      ok: true,
      status: 200,
      payload: { status: 'success', data: { recorded: true } },
    });
    const ok = await POST(
      new Request('http://localhost/api/public/ads/events', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event_type: 'impression', token: 'signed.token' }),
      })
    );
    expect(ok.status).toBe(200);
    expect(callLaravelApiMock).toHaveBeenCalledWith(
      '/api/v1/public/ads/impression',
      expect.objectContaining({ method: 'POST', body: { token: 'signed.token', session_id: undefined } })
    );
  });
});
