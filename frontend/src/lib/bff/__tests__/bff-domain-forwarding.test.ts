import { NextRequest } from 'next/server';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('next/headers', () => ({
  cookies: () => ({
    get: () => undefined,
  }),
  headers: () => new Headers(),
}));

import { validateOriginAndCsrf } from '@/lib/bff/csrf';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import { stripUntrustedDomainFields } from '@/lib/bff/route-helpers';

describe('M-A2 M3 Step 2: BFF Domain Forwarding & Spoofing Protection', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  it('forwards validated X-NearGrid-Host and sanitized X-Entry-Path to Laravel while keeping Bearer token server-side', async () => {
    let capturedHeaders: Record<string, string> = {};

    const fetchMock = vi.fn(async (_url: string, init?: RequestInit) => {
      capturedHeaders = (init?.headers ?? {}) as Record<string, string>;
      return new Response(JSON.stringify({ status: 'success', data: { ok: true } }), {
        status: 200,
        headers: { 'content-type': 'application/json' },
      });
    });
    vi.stubGlobal('fetch', fetchMock);

    const prevSecret = process.env.BFF_INTERNAL_SECRET;
    process.env.BFF_INTERNAL_SECRET = 'bff-secret-999';

    const res = await callLaravelApi('/api/v1/enquiries', {
      method: 'POST',
      body: { requirement: 'Need dental checkup' },
      bearerToken: 'secret-sanctum-token-123',
      requestHost: 'VELLORE.NearGrid.IN:3000',
      entryPath: '/c/healthcare/dentists',
    });

    if (prevSecret === undefined) {
      delete process.env.BFF_INTERNAL_SECRET;
    } else {
      process.env.BFF_INTERNAL_SECRET = prevSecret;
    }

    expect(res.ok).toBe(true);
    expect(capturedHeaders['X-NearGrid-Host']).toBe('vellore.neargrid.in');
    expect(capturedHeaders['X-NearGrid-Bff-Secret']).toBe('bff-secret-999');
    expect(capturedHeaders['X-Entry-Path']).toBe('/c/healthcare/dentists');
    expect(capturedHeaders.Authorization).toBe('Bearer secret-sanctum-token-123');
    expect(JSON.stringify(res.payload)).not.toContain('secret-sanctum-token-123');
    expect(JSON.stringify(res.payload)).not.toContain('bff-secret-999');
  });

  it('strips untrusted client-supplied domain_id, source_domain_id, source_host and entry_path fields', () => {
    const clean = stripUntrustedDomainFields({
      category_slug: 'healthcare',
      city_slug: 'vellore',
      domain_id: 999,
      source_domain_id: 888,
      source_host: 'evil.example.com',
      entry_path: '/../../etc/passwd',
    });

    expect(clean).toEqual({
      category_slug: 'healthcare',
      city_slug: 'vellore',
    });
  });

  it('blocks CSRF origin bypass via spoofed X-Forwarded-Host', () => {
    const req = new NextRequest('https://vellore.neargrid.in/api/public/enquiries', {
      method: 'POST',
      headers: {
        host: 'vellore.neargrid.in',
        origin: 'https://evil.example.com',
        'x-forwarded-host': 'evil.example.com',
        'x-csrf-token': 'tok123',
        cookie: 'lbd_csrf=tok123',
      },
    });

    const result = validateOriginAndCsrf(req);
    expect(result.valid).toBe(false);
    expect(result.code).toBe('FORBIDDEN_ORIGIN');
  });

  it('rejects malformed Host and forwards sanitized host & stripped body in POST /api/public/enquiries', async () => {
    let forwardedBody: Record<string, unknown> = {};
    let forwardedHeaders: Record<string, string> = {};

    vi.stubGlobal(
      'fetch',
      vi.fn(async (_url: string, init?: RequestInit) => {
        forwardedHeaders = (init?.headers ?? {}) as Record<string, string>;
        forwardedBody = JSON.parse(String(init?.body ?? '{}')) as Record<string, unknown>;
        return new Response(
          JSON.stringify({ status: 'success', data: { public_id: '01ENQUIRY0000000000000001' } }),
          { status: 201, headers: { 'content-type': 'application/json' } }
        );
      })
    );

    const { POST } = await import('@/app/api/public/enquiries/route');

    // Malformed host -> 400 INVALID_HOST
    const badReq = new Request('https://vellore.neargrid.in/api/public/enquiries', {
      method: 'POST',
      headers: {
        host: 'evil.com/../../etc/passwd',
        origin: 'https://vellore.neargrid.in',
        'x-csrf-token': 'tok123',
        cookie: 'lbd_csrf=tok123',
      },
      body: JSON.stringify({ requirement: 'test' }),
    });
    const badRes = await POST(badReq);
    expect(badRes.status).toBe(400);

    // Spoofed headers/body -> uses validated host, strips domain_id/source_domain_id
    const req = new Request('https://vellore.neargrid.in/api/public/enquiries', {
      method: 'POST',
      headers: {
        host: 'vellore.neargrid.in',
        'x-forwarded-proto': 'https',
        origin: 'https://vellore.neargrid.in',
        referer: 'https://vellore.neargrid.in/c/healthcare',
        'x-forwarded-host': 'attacker.example.com',
        'x-neargrid-host': 'attacker.example.com',
        'x-csrf-token': 'tok123',
        cookie: 'lbd_csrf=tok123',
      },
      body: JSON.stringify({
        category_slug: 'healthcare',
        city_slug: 'vellore',
        domain_id: 999,
        source_domain_id: 999,
        source_host: 'attacker.example.com',
      }),
    });

    const okRes = await POST(req);
    expect(okRes.status).toBe(201);
    expect(forwardedHeaders['X-NearGrid-Host']).toBe('vellore.neargrid.in');
    expect(forwardedHeaders['X-Entry-Path']).toBe('/c/healthcare');
    expect(forwardedBody.domain_id).toBeUndefined();
    expect(forwardedBody.source_domain_id).toBeUndefined();
    expect(forwardedBody.source_host).toBeUndefined();
  });
});
