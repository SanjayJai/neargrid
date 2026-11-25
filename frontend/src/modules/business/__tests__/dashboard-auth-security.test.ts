import fs from 'node:fs';
import path from 'node:path';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { NextRequest } from 'next/server';

const mockReadSession = vi.fn();
const mockGetUser = vi.fn();
const mockCallLaravel = vi.fn();
const mockDeleteSession = vi.fn();

vi.mock('@/lib/bff/session-cookie', () => ({
  SESSION_COOKIE_NAME: 'lbd_sid',
  readCurrentServerSession: () => mockReadSession(),
  getServerAuthenticatedUser: () => mockGetUser(),
}));

vi.mock('@/lib/bff/laravel-client', () => ({
  callLaravelApi: (...args: unknown[]) => mockCallLaravel(...args),
}));

vi.mock('@/lib/bff/session-store', () => ({
  getBffSessionStore: () => ({
    delete: (...args: unknown[]) => mockDeleteSession(...args),
  }),
}));

import { middleware } from '@/middleware';
import { authorizeBusinessDashboardAccess } from '@/modules/business/lib/dashboard-access';
import { sanitizeInternalRedirectPath } from '@/modules/customer/components/OtpLoginForm';

const DASHBOARD_ROUTES = [
  '/dashboard',
  '/dashboard/profile',
  '/dashboard/media-services',
  '/dashboard/hours',
  '/dashboard/team',
  '/dashboard/verification',
  '/dashboard/enquiries',
  '/dashboard/reviews',
  '/dashboard/settings',
] as const;

describe('Merchant Dashboard Authentication & OTP Placeholder Security', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('redirects guests on /dashboard and all 9 sub-routes via middleware to /login?next=<path>', () => {
    for (const route of DASHBOARD_ROUTES) {
      const req = new NextRequest(`http://localhost:3000${route}`);
      const res = middleware(req);

      expect(res.status).toBe(307);
      const location = res.headers.get('location');
      expect(location).toBe(`http://localhost:3000/login?next=${encodeURIComponent(route)}`);
    }
  });

  it('passes x-pathname and allows server layout check when session cookie is present', () => {
    const req = new NextRequest('http://localhost:3000/dashboard/enquiries', {
      headers: { cookie: 'lbd_sid=sess_valid_123' },
    });
    const res = middleware(req);

    expect(res.status).toBe(200);
    expect(res.headers.get('x-middleware-request-x-pathname')).toBe('/dashboard/enquiries');
  });

  it('returns unauthenticated redirect when server session or user is missing', async () => {
    mockReadSession.mockResolvedValueOnce(null);

    const res = await authorizeBusinessDashboardAccess('/dashboard/reviews');
    expect(res).toEqual({
      status: 'unauthenticated',
      redirectTo: '/login?next=%2Fdashboard%2Freviews',
    });
  });

  it('forbids authenticated customers with zero active business memberships', async () => {
    mockReadSession.mockResolvedValueOnce({
      opaqueSessionId: 'sess_customer',
      record: { accessToken: 'tok_cust', userPublicId: 'usr_cust' },
    });
    mockGetUser.mockResolvedValueOnce({
      public_id: 'usr_cust',
      name: 'Customer User',
      phone_e164: '+919840011111',
      roles: ['customer'],
      permissions: [],
    });
    mockCallLaravel.mockResolvedValueOnce({
      ok: true,
      status: 200,
      payload: { status: 'success', data: [] },
    });

    const res = await authorizeBusinessDashboardAccess('/dashboard');
    expect(res.status).toBe('forbidden');
  });

  it('allows business owners and authorized BusinessMember staff with active listings', async () => {
    mockReadSession.mockResolvedValueOnce({
      opaqueSessionId: 'sess_staff',
      record: { accessToken: 'tok_staff', userPublicId: 'usr_staff' },
    });
    mockGetUser.mockResolvedValueOnce({
      public_id: 'usr_staff',
      name: 'Branch Staff',
      phone_e164: '+919840022222',
      roles: ['customer'],
      permissions: [],
    });
    mockCallLaravel.mockResolvedValueOnce({
      ok: true,
      status: 200,
      payload: {
        status: 'success',
        data: [{ public_id: '01BIZ123', name: 'Apex Dental Studio' }],
      },
    });

    const res = await authorizeBusinessDashboardAccess('/dashboard/enquiries');
    expect(res.status).toBe('allowed');
    if (res.status === 'allowed') {
      expect(res.businesses).toHaveLength(1);
    }
  });

  it('removes Unauthenticated Preview from BusinessShell and replaces placeholder="123456" across OTP inputs', () => {
    const root = path.resolve(process.cwd(), 'src');
    const shell = fs.readFileSync(
      path.join(root, 'modules/business/components/BusinessShell.tsx'),
      'utf8'
    );
    expect(shell).not.toContain('Unauthenticated Preview');

    const otpFiles = [
      'modules/customer/components/OtpLoginForm.tsx',
      'modules/customer/components/EnquiryModal.tsx',
      'modules/customer/components/CustomerReviewsSection.tsx',
    ];
    for (const rel of otpFiles) {
      const content = fs.readFileSync(path.join(root, rel), 'utf8');
      expect(content).not.toContain('placeholder="123456"');
      expect(content).toContain('placeholder="Enter 6-digit code"');
    }
  });

  it('rejects expired/tampered session tokens (401) and deletes the stale BFF session', async () => {
    mockReadSession.mockResolvedValueOnce({
      opaqueSessionId: 'sess_tampered_or_expired',
      record: { accessToken: 'revoked_tok', userPublicId: 'usr_1' },
    });
    mockGetUser.mockResolvedValueOnce({
      public_id: 'usr_1',
      name: 'User',
      phone_e164: '+919840033333',
      roles: ['customer'],
      permissions: [],
    });
    mockCallLaravel.mockResolvedValueOnce({
      ok: false,
      status: 401,
      payload: { status: 'error', error: { code: 'UNAUTHENTICATED', message: 'Expired' } },
    });

    const res = await authorizeBusinessDashboardAccess('/dashboard/team');
    expect(res).toEqual({
      status: 'unauthenticated',
      redirectTo: '/login?next=%2Fdashboard%2Fteam',
    });
    expect(mockDeleteSession).toHaveBeenCalledWith('sess_tampered_or_expired');
  });

  it('rejects external, protocol-relative, backslash, and script next redirect values while preserving valid dashboard paths', () => {
    const malicious = [
      'https://evil.example/phish',
      'http://evil.example/login',
      '//evil.example',
      '   //evil.example/steal   ',
      '/\\evil.example',
      'javascript:alert(1)',
      'data:text/html,<script>alert(1)</script>',
      '',
      null,
      undefined,
    ];
    for (const bad of malicious) {
      expect(sanitizeInternalRedirectPath(bad)).toBe('/dashboard/settings');
    }

    expect(sanitizeInternalRedirectPath('/dashboard')).toBe('/dashboard');
    expect(sanitizeInternalRedirectPath('/dashboard/enquiries?tab=new')).toBe(
      '/dashboard/enquiries?tab=new'
    );
  });
});
