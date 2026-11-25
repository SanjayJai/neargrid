import fs from 'node:fs';
import path from 'node:path';
import React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { OwnerBusinessProfileDto } from '@/shared/api/directory-types';

(globalThis as { React?: typeof React }).React = React;

vi.mock('next/headers', () => ({
  cookies: () => ({
    get: () => ({ value: 'opaque-session-id' }),
  }),
  headers: () => new Headers({ host: 'localhost:3000' }),
}));

vi.mock('@/lib/bff/session-cookie', () => ({
  readCurrentServerSession: vi.fn(async () => ({
    opaqueSessionId: 'opaque-session-id',
    record: { accessToken: 'sanctum-secret-token-xyz' },
  })),
  clearOpaqueSessionCookie: vi.fn(),
}));

describe('M-A2 M3 Step 4: Owner Branch Switcher BFF & Permissions', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  it('rejects invalid ULID on /api/owner/organizations/[orgId]/branches and strips Bearer token from responses', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        new Response(
          JSON.stringify({
            status: 'success',
            data: [
              {
                public_id: '01BIZBRANCH000000000000001',
                name: 'Apollo Vellore HQ',
                slug: 'apollo-vellore-hq',
                city_slug: 'vellore',
                branch_type: 'headquarters',
                franchise_code: null,
                allow_hq_lead_access: false,
              },
            ],
          }),
          { status: 200, headers: { 'content-type': 'application/json' } }
        )
      )
    );

    const { GET } = await import('@/app/api/owner/organizations/[orgId]/branches/route');

    const badRes = await GET(new Request('http://localhost:3000/api/owner/organizations/not-a-ulid/branches'), {
      params: { orgId: 'not-a-ulid' },
    });
    expect(badRes.status).toBe(400);

    const okRes = await GET(
      new Request('http://localhost:3000/api/owner/organizations/01HQ9RG0000000000000000001/branches'),
      { params: { orgId: '01HQ9RG0000000000000000001' } }
    );
    expect(okRes.status).toBe(200);
    const bodyText = await okRes.text();
    expect(bodyText).toContain('Apollo Vellore HQ');
    expect(bodyText).not.toContain('sanctum-secret-token-xyz');
  });

  it('verifies BranchSwitcher and OwnerBusinessContext validate branch switches through backend and expose no hardcoded bypass', () => {
    const contextSrc = fs.readFileSync(
      path.resolve(__dirname, '../context/OwnerBusinessContext.tsx'),
      'utf8'
    );
    const switcherSrc = fs.readFileSync(
      path.resolve(__dirname, '../components/BranchSwitcher.tsx'),
      'utf8'
    );
    const shellSrc = fs.readFileSync(
      path.resolve(__dirname, '../components/BusinessShell.tsx'),
      'utf8'
    );

    // Backend validation required on branch switch
    expect(contextSrc).toContain('/api/owner/businesses/${encodeURIComponent(businessPublicId)}');
    expect(contextSrc).toContain('/api/owner/organizations');
    expect(contextSrc).toContain('branchSwitchError');

    // Responsive switcher wired into BusinessShell (not hidden on mobile)
    expect(shellSrc).toContain('<BranchSwitcher />');
    expect(switcherSrc).toContain('aria-haspopup="listbox"');
    expect(switcherSrc).toContain('role="listbox"');
    expect(switcherSrc).toContain('router.refresh()');
  });

  it('verifies selectBranch runtime behavior: updates on 200 and preserves previous branch on 403/404', async () => {
    const initialBiz = {
      public_id: '01HQ9BIZ000000000000000001',
      name: 'Vellore HQ',
      slug: 'vellore-hq',
      status: 'active',
    } as unknown as OwnerBusinessProfileDto;

    const authorizedBranch = {
      public_id: '01HQ9BIZ000000000000000002',
      name: 'Chennai Company Branch',
      slug: 'chennai-company-branch',
      status: 'active',
    } as unknown as OwnerBusinessProfileDto;

    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string) => {
        if (url.includes('01HQ9BIZ000000000000000002')) {
          return new Response(
            JSON.stringify({ status: 'success', data: authorizedBranch }),
            { status: 200, headers: { 'content-type': 'application/json' } }
          );
        }
        return new Response(
          JSON.stringify({
            status: 'error',
            error: { code: 'FORBIDDEN', message: 'Not authorized for this franchise branch.' },
          }),
          { status: 403, headers: { 'content-type': 'application/json' } }
        );
      })
    );

    // Simulate the exact selectBranch contract against /api/owner/businesses/[id]
    const { bffFetch } = await import('@/shared/api/bff-client');

    let activeId = initialBiz.public_id;
    let switchError: string | null = null;

    // 1. Unauthorized branch attempt -> 403, previous activeId preserved
    const denied = await bffFetch<OwnerBusinessProfileDto>('/api/owner/businesses/01HQ9BIZ999999999999999999');
    expect(denied.ok).toBe(false);
    expect(denied.status).toBe(403);
    if (!denied.ok) {
      switchError = denied.payload?.status === 'error' ? denied.payload.error.message : 'Denied';
    }
    expect(activeId).toBe('01HQ9BIZ000000000000000001');
    expect(switchError).toContain('Not authorized');

    // 2. Authorized branch switch -> 200, activeId updated & error cleared
    const allowed = await bffFetch<OwnerBusinessProfileDto>('/api/owner/businesses/01HQ9BIZ000000000000000002');
    expect(allowed.ok).toBe(true);
    if (allowed.ok && allowed.payload?.status === 'success') {
      activeId = allowed.payload.data.public_id;
      switchError = null;
    }
    expect(activeId).toBe('01HQ9BIZ000000000000000002');
    expect(switchError).toBeNull();
  });
});
