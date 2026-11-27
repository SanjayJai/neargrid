import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AdminOverviewView } from '@/modules/admin/components/AdminOverviewView';
import { filterAdminNavigation } from '@/modules/admin/lib/admin-navigation';
import { AdminAccessDenied } from '@/modules/admin/ui/AdminAccessDenied';
import { AdminCommandPalette } from '@/modules/admin/ui/AdminCommandPalette';
import { AdminConfirmDialog } from '@/modules/admin/ui/AdminConfirmDialog';
import { AdminDrawer } from '@/modules/admin/ui/AdminDrawer';
import { AdminShell } from '@/modules/admin/ui/AdminShell';
import type { UserDto } from '@/lib/bff/types';

(globalThis as unknown as { React: typeof React }).React = React;

const mockReadCurrentServerSession = vi.fn();
const mockGetServerAuthenticatedUser = vi.fn();
const mockCallLaravelApi = vi.fn();
const mockDeleteSession = vi.fn();

vi.mock('next/link', () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) =>
    React.createElement('a', { href, ...props }, children),
}));

vi.mock('next/navigation', () => ({
  usePathname: () => '/admin',
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));

vi.mock('@/lib/bff/session-cookie', () => ({
  readCurrentServerSession: mockReadCurrentServerSession,
  getServerAuthenticatedUser: mockGetServerAuthenticatedUser,
}));

vi.mock('@/lib/bff/session-store', () => ({
  getBffSessionStore: () => ({ delete: mockDeleteSession }),
}));

vi.mock('@/lib/bff/laravel-client', () => ({
  callLaravelApi: mockCallLaravelApi,
}));

const sampleAdminUser: UserDto = {
  public_id: '01ADMIN0000000000000000001',
  name: 'Master Admin',
  email: 'admin@neargrid.in',
  phone_e164: '+919999999999',
  phone_verified_at: null,
  avatar_url: null,
  roles: ['super_admin'],
  permissions: ['admin.view'],
  created_at: null,
};

describe('M-F2 M1 Admin Architecture, Security & Design System', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('authorizes super_admin via Laravel bootstrap and rejects unauthorized or unauthenticated users', async () => {
    const { authorizeAdminAccess } = await import('@/modules/admin/lib/admin-access');

    // Unauthenticated
    mockReadCurrentServerSession.mockResolvedValueOnce(null);
    const unauth = await authorizeAdminAccess('/admin');
    expect(unauth.status).toBe('unauthenticated');

    // Authorized session but Laravel returns 403 -> forbidden
    mockReadCurrentServerSession.mockResolvedValueOnce({
      opaqueSessionId: 'sess-1',
      record: { accessToken: 'secret-bearer' },
    });
    mockGetServerAuthenticatedUser.mockResolvedValueOnce(sampleAdminUser);
    mockCallLaravelApi.mockResolvedValueOnce({
      ok: false,
      status: 403,
      payload: { status: 'error', error: { code: 'FORBIDDEN', message: 'Forbidden' } },
    });

    const forbidden = await authorizeAdminAccess('/admin');
    expect(forbidden.status).toBe('forbidden');

    // Super admin 200 -> allowed
    mockReadCurrentServerSession.mockResolvedValueOnce({
      opaqueSessionId: 'sess-2',
      record: { accessToken: 'secret-bearer-admin' },
    });
    mockGetServerAuthenticatedUser.mockResolvedValueOnce(sampleAdminUser);
    mockCallLaravelApi.mockResolvedValueOnce({
      ok: true,
      status: 200,
      payload: {
        status: 'success',
        data: {
          actor: {
            public_id: sampleAdminUser.public_id,
            name: 'Master Admin',
            email: 'admin@neargrid.in',
            roles: ['super_admin'],
            permissions: [],
          },
          module_counts: {
            businesses_total: 12,
            pending_verifications: 2,
            pending_reviews: 1,
            open_enquiries: 4,
            domains_total: 3,
            categories_total: 40,
            users_total: 25,
          },
          recent_activity: [],
          capabilities: { navigation_search: true, record_search: false },
        },
      },
    });

    const allowed = await authorizeAdminAccess('/admin');
    expect(allowed.status).toBe('allowed');
  });

  it('filters global admin navigation across all grouped modules', () => {
    const adMatches = filterAdminNavigation('placements');
    expect(adMatches.some((item) => item.key === 'ad-placements')).toBe(true);

    const kycMatches = filterAdminNavigation('kyc');
    expect(kycMatches.some((item) => item.key === 'kyc')).toBe(true);
  });

  it('renders AdminShell, AdminOverviewView, AdminCommandPalette, AdminDrawer, AdminConfirmDialog and AdminAccessDenied safely', () => {
    const shellHtml = renderToStaticMarkup(
      React.createElement(
        AdminShell,
        { user: sampleAdminUser },
        React.createElement(AdminOverviewView, {
          bootstrap: {
            actor: {
              public_id: sampleAdminUser.public_id,
              name: 'Master Admin',
              email: 'admin@neargrid.in',
              roles: ['super_admin'],
              permissions: [],
            },
            module_counts: {
              businesses_total: 12,
              pending_verifications: 2,
              pending_reviews: 1,
              open_enquiries: 4,
              domains_total: 3,
              categories_total: 40,
              users_total: 25,
            },
            recent_activity: [
              {
                id: 101,
                actor_role: 'super_admin',
                action: 'domain.activated',
                auditable_type: 'Domain',
                auditable_id: 1,
                created_at: '2026-11-27T09:00:00Z',
              },
            ],
            capabilities: { navigation_search: true, record_search: false },
          },
        })
      )
    );

    expect(shellHtml).toContain('Platform Control Centre');
    expect(shellHtml).toContain('Directory &amp; Operations');
    expect(shellHtml).toContain('Growth, Ads &amp; Engagement');
    expect(shellHtml).toContain('domain.activated');
    expect(shellHtml).not.toContain('secret-bearer');

    const paletteHtml = renderToStaticMarkup(
      React.createElement(AdminCommandPalette, { open: true, onClose: vi.fn() })
    );
    expect(paletteHtml).toContain('Admin command palette');
    expect(paletteHtml).toContain('Control Centre');

    const drawerHtml = renderToStaticMarkup(
      React.createElement(
        AdminDrawer,
        { open: true, title: 'Inspect Record', onClose: vi.fn() },
        React.createElement('p', null, 'Drawer body')
      )
    );
    expect(drawerHtml).toContain('Inspect Record');

    const confirmHtml = renderToStaticMarkup(
      React.createElement(AdminConfirmDialog, {
        open: true,
        title: 'Suspend Domain',
        description: 'Confirm action',
        onConfirm: vi.fn(),
        onCancel: vi.fn(),
      })
    );
    expect(confirmHtml).toContain('Suspend Domain');

    const deniedHtml = renderToStaticMarkup(
      React.createElement(AdminAccessDenied, { user: sampleAdminUser })
    );
    expect(deniedHtml).toContain('Super Admin Access Required');
  });
});
