import fs from 'node:fs';
import path from 'node:path';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CSRF_COOKIE_NAME, CSRF_HEADER_NAME, generateCsrfToken } from '@/lib/bff/csrf';
import { MemoryBffSessionStore, type BffSessionRecord } from '@/lib/bff/session-store';
import { isSafeInternalNavigationUrl } from '@/modules/customer/lib/safe-navigation';
import { sanitizeInternalRedirectPath } from '@/modules/customer/components/OtpLoginForm';

const FRONTEND_SRC = path.resolve(__dirname, '../../..');

const VALID_NOTIFICATION_UUID = 'a1b2c3d4-e5f6-7a8b-9c0d-1e2f3a4b5c6d';
const INVALID_NOTIFICATION_ID = 'not-a-valid-uuid-123';

const mockSessionStore = new MemoryBffSessionStore();
let mockCurrentSession: { opaqueSessionId: string; record: BffSessionRecord } | null = null;
const mockCallLaravelApi = vi.fn();

vi.mock('@/lib/bff/session-cookie', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/bff/session-cookie')>();
  return {
    ...actual,
    readCurrentServerSession: vi.fn(async () => mockCurrentSession),
  };
});

vi.mock('@/lib/bff/session-store', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/bff/session-store')>();
  return {
    ...actual,
    getBffSessionStore: vi.fn(() => mockSessionStore),
  };
});

vi.mock('@/lib/bff/laravel-client', () => ({
  callLaravelApi: (...args: unknown[]) => mockCallLaravelApi(...args),
}));

import { GET as getNotifications } from '@/app/api/account/notifications/route';
import { GET as getUnreadCount } from '@/app/api/account/notifications/unread-count/route';
import { POST as postMarkAllRead } from '@/app/api/account/notifications/mark-all-read/route';
import { PATCH as patchMarkRead } from '@/app/api/account/notifications/[id]/read/route';
import { DELETE as deleteNotification } from '@/app/api/account/notifications/[id]/route';

function makeCsrfHeaders(extra: Record<string, string> = {}) {
  const csrf = generateCsrfToken();
  return {
    host: 'localhost:3000',
    origin: 'http://localhost:3000',
    cookie: `${CSRF_COOKIE_NAME}=${csrf}`,
    [CSRF_HEADER_NAME]: csrf,
    ...extra,
  };
}

describe('Stage 1 Milestone 3: Customer Notification Centre & Secure BFF Integration', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockCurrentSession = {
      opaqueSessionId: 'sess_customer_123',
      record: {
        accessToken: 'sanctum_customer_token_xyz',
        sessionId: 'ses_cust_1',
        userPublicId: '01JUSR123456',
        expiresAt: new Date(Date.now() + 86400000).toISOString(),
        createdAt: new Date().toISOString(),
      },
    };
  });

  describe('BFF Authentication & Token Isolation Security', () => {
    it('rejects unauthenticated requests to GET /api/account/notifications with 401', async () => {
      mockCurrentSession = null;
      const request = new Request('http://localhost:3000/api/account/notifications');
      const response = await getNotifications(request);

      expect(response.status).toBe(401);
      const json = await response.json();
      expect(json.error.code).toBe('UNAUTHENTICATED');
    });

    it('rejects unauthenticated requests to GET /api/account/notifications/unread-count with 401', async () => {
      mockCurrentSession = null;
      const request = new Request('http://localhost:3000/api/account/notifications/unread-count');
      const response = await getUnreadCount(request);

      expect(response.status).toBe(401);
      const json = await response.json();
      expect(json.error.code).toBe('UNAUTHENTICATED');
    });

    it('rejects unauthenticated requests to POST /api/account/notifications/mark-all-read with 401', async () => {
      mockCurrentSession = null;
      const headers = makeCsrfHeaders();
      const request = new Request('http://localhost:3000/api/account/notifications/mark-all-read', {
        method: 'POST',
        headers,
      });
      const response = await postMarkAllRead(request);

      expect(response.status).toBe(401);
      const json = await response.json();
      expect(json.error.code).toBe('UNAUTHENTICATED');
    });

    it('rejects unauthenticated requests to PATCH /api/account/notifications/[id]/read with 401', async () => {
      mockCurrentSession = null;
      const headers = makeCsrfHeaders();
      const request = new Request(`http://localhost:3000/api/account/notifications/${VALID_NOTIFICATION_UUID}/read`, {
        method: 'PATCH',
        headers,
      });
      const response = await patchMarkRead(request, { params: { id: VALID_NOTIFICATION_UUID } });

      expect(response.status).toBe(401);
      const json = await response.json();
      expect(json.error.code).toBe('UNAUTHENTICATED');
    });

    it('rejects unauthenticated requests to DELETE /api/account/notifications/[id] with 401', async () => {
      mockCurrentSession = null;
      const headers = makeCsrfHeaders();
      const request = new Request(`http://localhost:3000/api/account/notifications/${VALID_NOTIFICATION_UUID}`, {
        method: 'DELETE',
        headers,
      });
      const response = await deleteNotification(request, { params: { id: VALID_NOTIFICATION_UUID } });

      expect(response.status).toBe(401);
      const json = await response.json();
      expect(json.error.code).toBe('UNAUTHENTICATED');
    });

    it('ensures Sanctum Bearer token is strictly kept server-side and never exposed in client responses', async () => {
      mockCallLaravelApi.mockResolvedValueOnce({
        status: 200,
        ok: true,
        payload: {
          status: 'success',
          data: [],
          meta: { pagination: { total: 0, count: 0, per_page: 15, current_page: 1, total_pages: 1 } },
        },
        retryAfter: null,
        requestId: null,
        cacheControl: null,
        robotsTag: null,
        location: null,
      });

      const request = new Request('http://localhost:3000/api/account/notifications');
      const response = await getNotifications(request);

      expect(response.status).toBe(200);
      const responseText = await response.text();
      expect(responseText).not.toContain('sanctum_customer_token_xyz');
      expect(mockCallLaravelApi).toHaveBeenCalledWith(
        expect.stringContaining('/api/v1/account/notifications'),
        expect.objectContaining({
          bearerToken: 'sanctum_customer_token_xyz',
        })
      );
    });
  });

  describe('CSRF Enforcement on Mutations', () => {
    it('blocks POST /api/account/notifications/mark-all-read with foreign origin', async () => {
      const request = new Request('http://localhost:3000/api/account/notifications/mark-all-read', {
        method: 'POST',
        headers: { host: 'localhost:3000', origin: 'http://evil.com' },
      });
      const response = await postMarkAllRead(request);

      expect(response.status).toBe(403);
      const json = await response.json();
      expect(json.error.code).toBe('FORBIDDEN_ORIGIN');
    });

    it('blocks POST /api/account/notifications/mark-all-read without CSRF header', async () => {
      const request = new Request('http://localhost:3000/api/account/notifications/mark-all-read', {
        method: 'POST',
        headers: { host: 'localhost:3000', origin: 'http://localhost:3000' },
      });
      const response = await postMarkAllRead(request);

      expect(response.status).toBe(403);
      const json = await response.json();
      expect(json.error.code).toBe('INVALID_CSRF_TOKEN');
    });

    it('blocks PATCH /api/account/notifications/[id]/read with mismatched CSRF token', async () => {
      const request = new Request(`http://localhost:3000/api/account/notifications/${VALID_NOTIFICATION_UUID}/read`, {
        method: 'PATCH',
        headers: {
          host: 'localhost:3000',
          origin: 'http://localhost:3000',
          cookie: `${CSRF_COOKIE_NAME}=token_one`,
          [CSRF_HEADER_NAME]: 'token_two',
        },
      });
      const response = await patchMarkRead(request, { params: { id: VALID_NOTIFICATION_UUID } });

      expect(response.status).toBe(403);
      const json = await response.json();
      expect(json.error.code).toBe('INVALID_CSRF_TOKEN');
    });

    it('blocks DELETE /api/account/notifications/[id] with mismatched CSRF token', async () => {
      const request = new Request(`http://localhost:3000/api/account/notifications/${VALID_NOTIFICATION_UUID}`, {
        method: 'DELETE',
        headers: {
          host: 'localhost:3000',
          origin: 'http://localhost:3000',
          cookie: `${CSRF_COOKIE_NAME}=token_alpha`,
          [CSRF_HEADER_NAME]: 'token_beta',
        },
      });
      const response = await deleteNotification(request, { params: { id: VALID_NOTIFICATION_UUID } });

      expect(response.status).toBe(403);
      const json = await response.json();
      expect(json.error.code).toBe('INVALID_CSRF_TOKEN');
    });
  });

  describe('Parameter and UUID Validation', () => {
    it('rejects invalid status filter with 400', async () => {
      const request = new Request('http://localhost:3000/api/account/notifications?status=archived');
      const response = await getNotifications(request);

      expect(response.status).toBe(400);
      const json = await response.json();
      expect(json.error.code).toBe('INVALID_QUERY_PARAMS');
      expect(json.error.message).toContain('status');
    });

    it('rejects invalid category filter with 400', async () => {
      const request = new Request('http://localhost:3000/api/account/notifications?category=marketing');
      const response = await getNotifications(request);

      expect(response.status).toBe(400);
      const json = await response.json();
      expect(json.error.code).toBe('INVALID_QUERY_PARAMS');
      expect(json.error.message).toContain('category');
    });

    it('rejects invalid page number with 400', async () => {
      const request = new Request('http://localhost:3000/api/account/notifications?page=0');
      const response = await getNotifications(request);

      expect(response.status).toBe(400);
      const json = await response.json();
      expect(json.error.code).toBe('INVALID_QUERY_PARAMS');
    });

    it('rejects excessive per_page value with 400', async () => {
      const request = new Request('http://localhost:3000/api/account/notifications?per_page=100');
      const response = await getNotifications(request);

      expect(response.status).toBe(400);
      const json = await response.json();
      expect(json.error.code).toBe('INVALID_QUERY_PARAMS');
    });

    it('rejects invalid notification UUID in PATCH /read with 400', async () => {
      const headers = makeCsrfHeaders();
      const request = new Request(`http://localhost:3000/api/account/notifications/${INVALID_NOTIFICATION_ID}/read`, {
        method: 'PATCH',
        headers,
      });
      const response = await patchMarkRead(request, { params: { id: INVALID_NOTIFICATION_ID } });

      expect(response.status).toBe(400);
      const json = await response.json();
      expect(json.error.code).toBe('INVALID_NOTIFICATION_ID');
    });

    it('rejects invalid notification UUID in DELETE with 400', async () => {
      const headers = makeCsrfHeaders();
      const request = new Request(`http://localhost:3000/api/account/notifications/${INVALID_NOTIFICATION_ID}`, {
        method: 'DELETE',
        headers,
      });
      const response = await deleteNotification(request, { params: { id: INVALID_NOTIFICATION_ID } });

      expect(response.status).toBe(400);
      const json = await response.json();
      expect(json.error.code).toBe('INVALID_NOTIFICATION_ID');
    });
  });

  describe('Upstream Request Forwarding & Response Handling', () => {
    it('forwards query parameters accurately to Laravel backend', async () => {
      mockCallLaravelApi.mockResolvedValueOnce({
        status: 200,
        ok: true,
        payload: {
          status: 'success',
          data: [
            {
              id: VALID_NOTIFICATION_UUID,
              category: 'enquiry',
              type: 'enquiry_matched',
              title: 'Your enquiry was matched',
              message: 'Your plumbing enquiry was matched with 3 businesses.',
              data: { enquiry_id: '01JENQ123' },
              read_at: null,
              created_at: '2026-11-24T10:00:00Z',
            },
          ],
          meta: { pagination: { total: 1, count: 1, per_page: 10, current_page: 2, total_pages: 1 } },
        },
        retryAfter: null,
        requestId: null,
        cacheControl: null,
        robotsTag: null,
        location: null,
      });

      const request = new Request(
        'http://localhost:3000/api/account/notifications?status=unread&category=enquiry&page=2&per_page=10'
      );
      const response = await getNotifications(request);

      expect(response.status).toBe(200);
      expect(mockCallLaravelApi).toHaveBeenCalledWith(
        '/api/v1/account/notifications?status=unread&category=enquiry&page=2&per_page=10',
        expect.objectContaining({
          method: 'GET',
          bearerToken: 'sanctum_customer_token_xyz',
        })
      );
      const json = await response.json();
      expect(json.data).toHaveLength(1);
      expect(json.data[0].id).toBe(VALID_NOTIFICATION_UUID);
      expect(json.data[0].category).toBe('enquiry');
    });

    it('forwards unread-count response from Laravel backend', async () => {
      mockCallLaravelApi.mockResolvedValueOnce({
        status: 200,
        ok: true,
        payload: {
          status: 'success',
          data: { unread_count: 5 },
        },
        retryAfter: null,
        requestId: null,
        cacheControl: null,
        robotsTag: null,
        location: null,
      });

      const request = new Request('http://localhost:3000/api/account/notifications/unread-count');
      const response = await getUnreadCount(request);

      expect(response.status).toBe(200);
      expect(mockCallLaravelApi).toHaveBeenCalledWith(
        '/api/v1/account/notifications/unread-count',
        expect.objectContaining({
          method: 'GET',
          bearerToken: 'sanctum_customer_token_xyz',
        })
      );
      const json = await response.json();
      expect(json.data.unread_count).toBe(5);
    });

    it('forwards mark-all-read request with POST to Laravel backend', async () => {
      mockCallLaravelApi.mockResolvedValueOnce({
        status: 200,
        ok: true,
        payload: {
          status: 'success',
          data: { marked_read_count: 3 },
        },
        retryAfter: null,
        requestId: null,
        cacheControl: null,
        robotsTag: null,
        location: null,
      });

      const headers = makeCsrfHeaders();
      const request = new Request('http://localhost:3000/api/account/notifications/mark-all-read', {
        method: 'POST',
        headers,
      });
      const response = await postMarkAllRead(request);

      expect(response.status).toBe(200);
      expect(mockCallLaravelApi).toHaveBeenCalledWith(
        '/api/v1/account/notifications/mark-all-read',
        expect.objectContaining({
          method: 'POST',
          bearerToken: 'sanctum_customer_token_xyz',
        })
      );
      const json = await response.json();
      expect(json.data.marked_read_count).toBe(3);
    });

    it('forwards single mark-as-read request with PATCH to Laravel backend', async () => {
      mockCallLaravelApi.mockResolvedValueOnce({
        status: 200,
        ok: true,
        payload: {
          status: 'success',
          data: {
            id: VALID_NOTIFICATION_UUID,
            read_at: '2026-11-24T12:00:00Z',
          },
        },
        retryAfter: null,
        requestId: null,
        cacheControl: null,
        robotsTag: null,
        location: null,
      });

      const headers = makeCsrfHeaders();
      const request = new Request(`http://localhost:3000/api/account/notifications/${VALID_NOTIFICATION_UUID}/read`, {
        method: 'PATCH',
        headers,
      });
      const response = await patchMarkRead(request, { params: { id: VALID_NOTIFICATION_UUID } });

      expect(response.status).toBe(200);
      expect(mockCallLaravelApi).toHaveBeenCalledWith(
        `/api/v1/account/notifications/${VALID_NOTIFICATION_UUID}/read`,
        expect.objectContaining({
          method: 'PATCH',
          bearerToken: 'sanctum_customer_token_xyz',
        })
      );
      const json = await response.json();
      expect(json.data.id).toBe(VALID_NOTIFICATION_UUID);
      expect(json.data.read_at).toBeTruthy();
    });

    it('forwards delete request with DELETE to Laravel backend', async () => {
      mockCallLaravelApi.mockResolvedValueOnce({
        status: 200,
        ok: true,
        payload: {
          status: 'success',
          data: { success: true },
        },
        retryAfter: null,
        requestId: null,
        cacheControl: null,
        robotsTag: null,
        location: null,
      });

      const headers = makeCsrfHeaders();
      const request = new Request(`http://localhost:3000/api/account/notifications/${VALID_NOTIFICATION_UUID}`, {
        method: 'DELETE',
        headers,
      });
      const response = await deleteNotification(request, { params: { id: VALID_NOTIFICATION_UUID } });

      expect(response.status).toBe(200);
      expect(mockCallLaravelApi).toHaveBeenCalledWith(
        `/api/v1/account/notifications/${VALID_NOTIFICATION_UUID}`,
        expect.objectContaining({
          method: 'DELETE',
          bearerToken: 'sanctum_customer_token_xyz',
        })
      );
      const json = await response.json();
      expect(json.data.success).toBe(true);
    });

    it('handles upstream unreachable error gracefully with 503', async () => {
      mockCallLaravelApi.mockRejectedValueOnce(new Error('Connection refused'));

      const request = new Request('http://localhost:3000/api/account/notifications');
      const response = await getNotifications(request);

      expect(response.status).toBe(503);
      const json = await response.json();
      expect(json.error.code).toBe('UPSTREAM_UNREACHABLE');
    });
  });

  describe('UI Component Architecture Verification', () => {
    it('CustomerNotificationCentre implements filters, status tabs, pagination, and empty states', () => {
      const centrePath = path.join(
        FRONTEND_SRC,
        'modules/customer/components/CustomerNotificationCentre.tsx'
      );
      const source = fs.readFileSync(centrePath, 'utf-8');

      // Header and controls
      expect(source).toContain('Notification Centre');
      expect(source).toContain('Mark all as read');

      // Status filters
      expect(source).toContain("'all'");
      expect(source).toContain("'unread'");

      // Category filters
      expect(source).toContain("'enquiry'");
      expect(source).toContain("'review'");
      expect(source).toContain("'account'");

      // Mutation actions
      expect(source).toContain('markNotificationAsRead');
      expect(source).toContain('markAllNotificationsAsRead');
      expect(source).toContain('deleteNotification');

      // Deep navigation links & action handling
      expect(source).toContain('handleActionClick');
      expect(source).toContain('notification.action_url');
      expect(source).toContain('router.push(notification.action_url)');

      // Visual distinctions and accessibility
      expect(source).toContain('aria-label');
      expect(source).toContain('formatRelativeTime');
      expect(source).toContain('No notifications yet');
      expect(source).toContain('No unread notifications');
    });

    it('NotificationBellFlyout implements unread counter badge and quick flyout', () => {
      const flyoutPath = path.join(
        FRONTEND_SRC,
        'modules/customer/components/NotificationBellFlyout.tsx'
      );
      const source = fs.readFileSync(flyoutPath, 'utf-8');

      expect(source).toContain('fetchUnreadCount');
      expect(source).toContain('fetchNotifications');
      expect(source).toContain('unread_count');
      expect(source).toContain('View all in Notification Centre');
      expect(source).toContain('/notifications');
      expect(source).toContain('markAllNotificationsAsRead');
    });

    it('CustomerHeader embeds NotificationBellFlyout in desktop navigation and mobile drawer', () => {
      const headerPath = path.join(
        FRONTEND_SRC,
        'modules/customer/components/CustomerHeader.tsx'
      );
      const source = fs.readFileSync(headerPath, 'utf-8');

      expect(source).toContain('NotificationBellFlyout');
      expect(source).toContain('/notifications');
      expect(source).toContain('<NotificationBellFlyout user={user} />');
    });

    it('Customer Notifications page protects unauthorized access with server-side redirect', () => {
      const pagePath = path.join(
        FRONTEND_SRC,
        'app/(customer)/notifications/page.tsx'
      );
      const source = fs.readFileSync(pagePath, 'utf-8');

      expect(source).toContain('getServerAuthenticatedUser');
      expect(source).toContain("redirect('/login?next=/notifications')");
      expect(source).toContain('CustomerNotificationCentre');
    });
  });

  describe('Safe Notification Action URLs & Internal Navigation Validation', () => {
    it('accepts valid internal relative paths including queries and hash fragments', () => {
      expect(isSafeInternalNavigationUrl('/dashboard/enquiries')).toBe(true);
      expect(isSafeInternalNavigationUrl('/enquiries/01JCG4K8N5P2Q7R9S1T3V5W7E1?tab=leads#active')).toBe(true);
      expect(isSafeInternalNavigationUrl('/chennai/business/t-nagar-plumbing')).toBe(true);
      expect(isSafeInternalNavigationUrl('/notifications?status=unread&category=enquiry')).toBe(true);
      expect(isSafeInternalNavigationUrl('/search?q=category:plumbing')).toBe(true);
      expect(isSafeInternalNavigationUrl('/')).toBe(true);
    });

    it('rejects external URLs (HTTP, HTTPS, FTP)', () => {
      expect(isSafeInternalNavigationUrl('https://attacker.com')).toBe(false);
      expect(isSafeInternalNavigationUrl('http://attacker.com/dashboard')).toBe(false);
      expect(isSafeInternalNavigationUrl('https://evil.com/notifications')).toBe(false);
      expect(isSafeInternalNavigationUrl('ftp://files.example.com')).toBe(false);
    });

    it('rejects protocol-relative URLs (//)', () => {
      expect(isSafeInternalNavigationUrl('//attacker.com')).toBe(false);
      expect(isSafeInternalNavigationUrl('//attacker.com/enquiries')).toBe(false);
      expect(isSafeInternalNavigationUrl('///evil.com')).toBe(false);
    });

    it('rejects backslash-based paths and obfuscated hostnames', () => {
      expect(isSafeInternalNavigationUrl('/\\attacker.com')).toBe(false);
      expect(isSafeInternalNavigationUrl('\\\\attacker.com')).toBe(false);
      expect(isSafeInternalNavigationUrl('\\attacker.com')).toBe(false);
      expect(isSafeInternalNavigationUrl('/dashboard\\enquiries')).toBe(false);
    });

    it('rejects javascript:, data:, and other dangerous schemes', () => {
      expect(isSafeInternalNavigationUrl('javascript:alert(1)')).toBe(false);
      expect(isSafeInternalNavigationUrl('/javascript:alert(1)')).toBe(false);
      expect(isSafeInternalNavigationUrl('data:text/html,<script>alert(1)</script>')).toBe(false);
      expect(isSafeInternalNavigationUrl('/data:text/html,evil')).toBe(false);
      expect(isSafeInternalNavigationUrl('vbscript:msgbox(1)')).toBe(false);
      expect(isSafeInternalNavigationUrl('file:///etc/passwd')).toBe(false);
    });

    it('rejects malformed URLs, control characters, non-strings, and empty values', () => {
      expect(isSafeInternalNavigationUrl('')).toBe(false);
      expect(isSafeInternalNavigationUrl('   ')).toBe(false);
      expect(isSafeInternalNavigationUrl(null)).toBe(false);
      expect(isSafeInternalNavigationUrl(undefined)).toBe(false);
      expect(isSafeInternalNavigationUrl(123)).toBe(false);
      expect(isSafeInternalNavigationUrl('/path\x00evil')).toBe(false);
      expect(isSafeInternalNavigationUrl('/path\x1Fevil')).toBe(false);
    });

    it('ensures CustomerNotificationCentre guards router.push with isSafeInternalNavigationUrl', () => {
      const centrePath = path.join(
        FRONTEND_SRC,
        'modules/customer/components/CustomerNotificationCentre.tsx'
      );
      const source = fs.readFileSync(centrePath, 'utf-8');

      expect(source).toContain('isSafeInternalNavigationUrl');
      expect(source).toContain('isSafeInternalNavigationUrl(notification.action_url)');
      expect(source).toContain('router.push(notification.action_url)');
    });

    it('ensures NotificationBellFlyout guards router.push with isSafeInternalNavigationUrl', () => {
      const flyoutPath = path.join(
        FRONTEND_SRC,
        'modules/customer/components/NotificationBellFlyout.tsx'
      );
      const source = fs.readFileSync(flyoutPath, 'utf-8');

      expect(source).toContain('isSafeInternalNavigationUrl');
      expect(source).toContain('isSafeInternalNavigationUrl(notification.action_url)');
    });

    it('simulates action click behaviour: invalid action URL preserves mark-as-read but prevents navigation', () => {
      const mockRouterPush = vi.fn();
      const mockMarkRead = vi.fn();

      function simulateActionClick(
        notification: { id: string; action_url: string | null; is_read: boolean },
        push: (url: string) => void,
        markRead: (id: string) => void
      ) {
        if (!notification.is_read) {
          markRead(notification.id);
        }
        if (notification.action_url && isSafeInternalNavigationUrl(notification.action_url)) {
          push(notification.action_url);
        }
      }

      // Test with malicious javascript: URL
      simulateActionClick(
        { id: VALID_NOTIFICATION_UUID, action_url: 'javascript:alert(1)', is_read: false },
        mockRouterPush,
        mockMarkRead
      );
      expect(mockMarkRead).toHaveBeenCalledWith(VALID_NOTIFICATION_UUID);
      expect(mockRouterPush).not.toHaveBeenCalled();

      // Test with malicious external HTTPS URL
      mockMarkRead.mockClear();
      mockRouterPush.mockClear();
      simulateActionClick(
        { id: VALID_NOTIFICATION_UUID, action_url: 'https://attacker.com/phish', is_read: false },
        mockRouterPush,
        mockMarkRead
      );
      expect(mockMarkRead).toHaveBeenCalledWith(VALID_NOTIFICATION_UUID);
      expect(mockRouterPush).not.toHaveBeenCalled();

      // Test with valid internal relative URL
      mockMarkRead.mockClear();
      mockRouterPush.mockClear();
      simulateActionClick(
        { id: VALID_NOTIFICATION_UUID, action_url: '/dashboard/enquiries', is_read: false },
        mockRouterPush,
        mockMarkRead
      );
      expect(mockMarkRead).toHaveBeenCalledWith(VALID_NOTIFICATION_UUID);
      expect(mockRouterPush).toHaveBeenCalledWith('/dashboard/enquiries');
    });
  });

  describe('Login Return Path & Open-Redirect Immunity', () => {
    it('redirects unauthenticated notification centre access to /login?next=/notifications', () => {
      const pagePath = path.join(
        FRONTEND_SRC,
        'app/(customer)/notifications/page.tsx'
      );
      const source = fs.readFileSync(pagePath, 'utf-8');

      expect(source).toContain("redirect('/login?next=/notifications')");
      expect(source).not.toContain('returnTo=');
    });

    it('preserves existing open-redirect protection and safely resolves /notifications', () => {
      // Safe internal next returns itself
      expect(sanitizeInternalRedirectPath('/notifications')).toBe('/notifications');
      expect(sanitizeInternalRedirectPath('/dashboard/settings')).toBe('/dashboard/settings');

      // Malicious or external next values default safely to /dashboard/settings
      expect(sanitizeInternalRedirectPath('https://attacker.com')).toBe('/dashboard/settings');
      expect(sanitizeInternalRedirectPath('//attacker.com')).toBe('/dashboard/settings');
      expect(sanitizeInternalRedirectPath('/\\attacker.com')).toBe('/dashboard/settings');
      expect(sanitizeInternalRedirectPath(null)).toBe('/dashboard/settings');
      expect(sanitizeInternalRedirectPath(undefined)).toBe('/dashboard/settings');
      expect(sanitizeInternalRedirectPath('')).toBe('/dashboard/settings');
    });
  });
});
