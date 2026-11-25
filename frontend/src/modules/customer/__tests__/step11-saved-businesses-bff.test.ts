import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CSRF_COOKIE_NAME, CSRF_HEADER_NAME, generateCsrfToken } from '@/lib/bff/csrf';
import { MemoryBffSessionStore, type BffSessionRecord } from '@/lib/bff/session-store';

const VALID_ULID = '01HQENQ001ABCDEFGH12345678';
const INVALID_ULIDS = ['not-a-ulid', '01HQSHORT', '01HQINVALIDCHARSI_L_O_U_12'];

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

import { GET as getSavedBusinesses } from '@/app/api/account/saved-businesses/route';
import { GET as getSavedBusinessIds } from '@/app/api/account/saved-businesses/ids/route';
import {
  PUT as putSaveBusiness,
  DELETE as deleteUnsaveBusiness,
} from '@/app/api/account/saved-businesses/[publicId]/route';
import {
  fetchSavedBusinesses,
  fetchSavedBusinessIds,
  saveBusiness,
  unsaveBusiness,
} from '@/modules/customer/lib/saved-businesses-api';

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

describe('Saved Businesses Milestone 2: Next.js BFF & API Client Integration', () => {
  beforeEach(async () => {
    vi.clearAllMocks();
    mockCurrentSession = {
      opaqueSessionId: 'sess_customer_saved_123',
      record: {
        accessToken: 'sanctum_customer_secret_bearer_token_abc',
        sessionId: 'ses_cust_saved_1',
        userPublicId: '01JUSR12345678901234567890',
        expiresAt: new Date(Date.now() + 86400000).toISOString(),
        createdAt: new Date().toISOString(),
      },
    };
    await mockSessionStore.set(mockCurrentSession.opaqueSessionId, mockCurrentSession.record, 3600);
  });

  describe('BFF Authentication & Session Enforcement', () => {
    it('returns 401 for unauthenticated GET /api/account/saved-businesses', async () => {
      mockCurrentSession = null;
      const req = new Request('http://localhost:3000/api/account/saved-businesses');
      const res = await getSavedBusinesses(req);

      expect(res.status).toBe(401);
      const json = await res.json();
      expect(json.error.code).toBe('UNAUTHENTICATED');
      expect(mockCallLaravelApi).not.toHaveBeenCalled();
    });

    it('returns 401 for unauthenticated GET /api/account/saved-businesses/ids', async () => {
      mockCurrentSession = null;
      const req = new Request('http://localhost:3000/api/account/saved-businesses/ids');
      const res = await getSavedBusinessIds(req);

      expect(res.status).toBe(401);
      const json = await res.json();
      expect(json.error.code).toBe('UNAUTHENTICATED');
      expect(mockCallLaravelApi).not.toHaveBeenCalled();
    });

    it('returns 401 for unauthenticated PUT /api/account/saved-businesses/[publicId]', async () => {
      mockCurrentSession = null;
      const req = new Request(`http://localhost:3000/api/account/saved-businesses/${VALID_ULID}`, {
        method: 'PUT',
        headers: makeCsrfHeaders(),
      });
      const res = await putSaveBusiness(req, { params: { publicId: VALID_ULID } });

      expect(res.status).toBe(401);
      const json = await res.json();
      expect(json.error.code).toBe('UNAUTHENTICATED');
      expect(mockCallLaravelApi).not.toHaveBeenCalled();
    });

    it('returns 401 for unauthenticated DELETE /api/account/saved-businesses/[publicId]', async () => {
      mockCurrentSession = null;
      const req = new Request(`http://localhost:3000/api/account/saved-businesses/${VALID_ULID}`, {
        method: 'DELETE',
        headers: makeCsrfHeaders(),
      });
      const res = await deleteUnsaveBusiness(req, { params: { publicId: VALID_ULID } });

      expect(res.status).toBe(401);
      const json = await res.json();
      expect(json.error.code).toBe('UNAUTHENTICATED');
      expect(mockCallLaravelApi).not.toHaveBeenCalled();
    });
  });

  describe('Origin & CSRF Mutation Security', () => {
    it('rejects PUT with invalid Origin header with 403', async () => {
      const req = new Request(`http://localhost:3000/api/account/saved-businesses/${VALID_ULID}`, {
        method: 'PUT',
        headers: makeCsrfHeaders({ origin: 'https://attacker.example' }),
      });
      const res = await putSaveBusiness(req, { params: { publicId: VALID_ULID } });

      expect(res.status).toBe(403);
      const json = await res.json();
      expect(json.error.code).toBe('FORBIDDEN_ORIGIN');
      expect(mockCallLaravelApi).not.toHaveBeenCalled();
    });

    it('rejects DELETE with invalid Origin header with 403', async () => {
      const req = new Request(`http://localhost:3000/api/account/saved-businesses/${VALID_ULID}`, {
        method: 'DELETE',
        headers: makeCsrfHeaders({ origin: 'https://attacker.example' }),
      });
      const res = await deleteUnsaveBusiness(req, { params: { publicId: VALID_ULID } });

      expect(res.status).toBe(403);
      const json = await res.json();
      expect(json.error.code).toBe('FORBIDDEN_ORIGIN');
      expect(mockCallLaravelApi).not.toHaveBeenCalled();
    });

    it('rejects PUT with missing CSRF token with 403', async () => {
      const req = new Request(`http://localhost:3000/api/account/saved-businesses/${VALID_ULID}`, {
        method: 'PUT',
        headers: {
          host: 'localhost:3000',
          origin: 'http://localhost:3000',
        },
      });
      const res = await putSaveBusiness(req, { params: { publicId: VALID_ULID } });

      expect(res.status).toBe(403);
      expect(mockCallLaravelApi).not.toHaveBeenCalled();
    });

    it('rejects DELETE with mismatched CSRF token with 403', async () => {
      const csrf = generateCsrfToken();
      const req = new Request(`http://localhost:3000/api/account/saved-businesses/${VALID_ULID}`, {
        method: 'DELETE',
        headers: {
          host: 'localhost:3000',
          origin: 'http://localhost:3000',
          cookie: `${CSRF_COOKIE_NAME}=${csrf}`,
          [CSRF_HEADER_NAME]: 'mismatched_token_value',
        },
      });
      const res = await deleteUnsaveBusiness(req, { params: { publicId: VALID_ULID } });

      expect(res.status).toBe(403);
      expect(mockCallLaravelApi).not.toHaveBeenCalled();
    });
  });

  describe('Input Validation (ULIDs & Query Parameters)', () => {
    it.each(INVALID_ULIDS)('rejects invalid ULID "%s" on PUT with 400', async (badId) => {
      const req = new Request(`http://localhost:3000/api/account/saved-businesses/${badId}`, {
        method: 'PUT',
        headers: makeCsrfHeaders(),
      });
      const res = await putSaveBusiness(req, { params: Promise.resolve({ publicId: badId }) });

      expect(res.status).toBe(400);
      const json = await res.json();
      expect(json.error.code).toBe('INVALID_BUSINESS_ID');
      expect(mockCallLaravelApi).not.toHaveBeenCalled();
    });

    it.each(INVALID_ULIDS)('rejects invalid ULID "%s" on DELETE with 400', async (badId) => {
      const req = new Request(`http://localhost:3000/api/account/saved-businesses/${badId}`, {
        method: 'DELETE',
        headers: makeCsrfHeaders(),
      });
      const res = await deleteUnsaveBusiness(req, { params: Promise.resolve({ publicId: badId }) });

      expect(res.status).toBe(400);
      const json = await res.json();
      expect(json.error.code).toBe('INVALID_BUSINESS_ID');
      expect(mockCallLaravelApi).not.toHaveBeenCalled();
    });

    it('rejects invalid page and per_page query parameters with 400', async () => {
      const invalidUrls = [
        'http://localhost:3000/api/account/saved-businesses?page=0',
        'http://localhost:3000/api/account/saved-businesses?page=-2',
        'http://localhost:3000/api/account/saved-businesses?page=abc',
        'http://localhost:3000/api/account/saved-businesses?page=1.5',
        'http://localhost:3000/api/account/saved-businesses?per_page=0',
        'http://localhost:3000/api/account/saved-businesses?per_page=51',
        'http://localhost:3000/api/account/saved-businesses?per_page=xyz',
      ];

      for (const url of invalidUrls) {
        const res = await getSavedBusinesses(new Request(url));
        expect(res.status).toBe(400);
        const json = await res.json();
        expect(json.error.code).toBe('INVALID_QUERY_PARAMS');
      }

      expect(mockCallLaravelApi).not.toHaveBeenCalled();
    });
  });

  describe('Upstream Forwarding, Authorization Errors & Token Isolation', () => {
    it('forwards Bearer token server-side only and never leaks it in response body or headers', async () => {
      mockCallLaravelApi.mockResolvedValue({
        status: 200,
        payload: {
          status: 'success',
          data: [{ public_id: VALID_ULID, name: 'Apex Electricals' }],
          meta: {
            pagination: {
              type: 'offset',
              current_page: 2,
              per_page: 15,
              total: 20,
              last_page: 2,
              next_cursor: null,
            },
          },
        },
        requestId: 'req-saved-list-1',
      });

      const req = new Request('http://localhost:3000/api/account/saved-businesses?page=2&per_page=15');
      const res = await getSavedBusinesses(req);

      expect(res.status).toBe(200);
      expect(mockCallLaravelApi).toHaveBeenCalledWith(
        '/api/v1/account/saved-businesses?page=2&per_page=15',
        expect.objectContaining({
          method: 'GET',
          bearerToken: 'sanctum_customer_secret_bearer_token_abc',
        })
      );

      const rawBody = await res.text();
      expect(rawBody).not.toContain('sanctum_customer_secret_bearer_token_abc');
      expect(res.headers.get('cache-control')).toBe('private, no-store');
      expect(JSON.stringify(Object.fromEntries(res.headers.entries()))).not.toContain(
        'sanctum_customer_secret_bearer_token_abc'
      );
    });

    it('handles upstream 403 ACCOUNT_SUSPENDED cleanly', async () => {
      mockCallLaravelApi.mockResolvedValue({
        status: 403,
        payload: {
          status: 'error',
          error: {
            code: 'ACCOUNT_SUSPENDED',
            message: 'Account is suspended.',
          },
        },
      });

      const req = new Request(`http://localhost:3000/api/account/saved-businesses/${VALID_ULID}`, {
        method: 'PUT',
        headers: makeCsrfHeaders(),
      });
      const res = await putSaveBusiness(req, { params: { publicId: VALID_ULID } });

      expect(res.status).toBe(403);
      const json = await res.json();
      expect(json.error.code).toBe('ACCOUNT_SUSPENDED');
    });

    it('expires local BFF session when upstream returns 401', async () => {
      mockCallLaravelApi.mockResolvedValue({
        status: 401,
        payload: {
          status: 'error',
          error: { code: 'UNAUTHENTICATED', message: 'Token expired.' },
        },
      });

      const req = new Request('http://localhost:3000/api/account/saved-businesses/ids');
      const res = await getSavedBusinessIds(req);

      expect(res.status).toBe(401);
      const remaining = await mockSessionStore.get('sess_customer_saved_123');
      expect(remaining).toBeNull();
    });

    it('forwards 404 when saving an unpublished or deleted business', async () => {
      mockCallLaravelApi.mockResolvedValue({
        status: 404,
        payload: {
          status: 'error',
          error: { code: 'NOT_FOUND', message: 'Business not found.' },
        },
      });

      const req = new Request(`http://localhost:3000/api/account/saved-businesses/${VALID_ULID}`, {
        method: 'PUT',
        headers: makeCsrfHeaders(),
      });
      const res = await putSaveBusiness(req, { params: { publicId: VALID_ULID } });

      expect(res.status).toBe(404);
    });

    it('forwards 204 No Content on DELETE without error', async () => {
      mockCallLaravelApi.mockResolvedValue({
        status: 204,
        payload: null,
      });

      const req = new Request(`http://localhost:3000/api/account/saved-businesses/${VALID_ULID}`, {
        method: 'DELETE',
        headers: makeCsrfHeaders(),
      });
      const res = await deleteUnsaveBusiness(req, { params: { publicId: VALID_ULID } });

      expect(res.status).toBe(204);
      expect(mockCallLaravelApi).toHaveBeenCalledWith(
        `/api/v1/account/saved-businesses/${VALID_ULID}`,
        expect.objectContaining({
          method: 'DELETE',
          bearerToken: 'sanctum_customer_secret_bearer_token_abc',
        })
      );
    });

    it('returns 503 UPSTREAM_UNREACHABLE when Laravel call throws', async () => {
      mockCallLaravelApi.mockRejectedValue(new Error('ECONNREFUSED'));

      const res = await getSavedBusinessIds(
        new Request('http://localhost:3000/api/account/saved-businesses/ids')
      );

      expect(res.status).toBe(503);
      const json = await res.json();
      expect(json.error.code).toBe('UPSTREAM_UNREACHABLE');
    });
  });

  describe('More than 500 Saved Businesses ID Strategy & Pagination', () => {
    it('handles capped 500-ID payload cleanly when user has > 500 saved businesses', async () => {
      const fiveHundredIds = Array.from({ length: 500 }, (_, i) =>
        `01HQBIZ${String(i).padStart(19, '0')}`
      );

      mockCallLaravelApi.mockResolvedValue({
        status: 200,
        payload: {
          status: 'success',
          data: { saved_ids: fiveHundredIds },
        },
      });

      const res = await getSavedBusinessIds(
        new Request('http://localhost:3000/api/account/saved-businesses/ids')
      );

      expect(res.status).toBe(200);
      const json = await res.json();
      expect(json.data.saved_ids).toHaveLength(500);
      expect(json.data.saved_ids[0]).toBe(fiveHundredIds[0]);
      expect(json.data.saved_ids[499]).toBe(fiveHundredIds[499]);
    });

    it('supports paginated access beyond 500 saved businesses via page and per_page', async () => {
      mockCallLaravelApi.mockResolvedValue({
        status: 200,
        payload: {
          status: 'success',
          data: [{ public_id: VALID_ULID, name: 'Saved Biz #525' }],
          meta: {
            pagination: {
              type: 'offset',
              current_page: 11,
              per_page: 50,
              total: 525,
              last_page: 11,
              next_cursor: null,
            },
          },
        },
      });

      const res = await getSavedBusinesses(
        new Request('http://localhost:3000/api/account/saved-businesses?page=11&per_page=50')
      );

      expect(res.status).toBe(200);
      const json = await res.json();
      expect(json.meta.pagination.total).toBe(525);
      expect(json.meta.pagination.current_page).toBe(11);
      expect(mockCallLaravelApi).toHaveBeenCalledWith(
        '/api/v1/account/saved-businesses?page=11&per_page=50',
        expect.anything()
      );
    });
  });

  describe('Browser API Client (saved-businesses-api.ts)', () => {
    beforeEach(() => {
      const csrf = generateCsrfToken();
      Object.defineProperty(globalThis, 'document', {
        value: { cookie: `${CSRF_COOKIE_NAME}=${csrf}` },
        configurable: true,
        writable: true,
      });
    });

    it('fetchSavedBusinesses sends GET with pagination query parameters', async () => {
      const fetchMock = vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            status: 'success',
            data: [],
            meta: { pagination: { type: 'offset', current_page: 2, per_page: 10, total: 0, last_page: 1, next_cursor: null } },
          }),
          { status: 200, headers: { 'Content-Type': 'application/json' } }
        )
      );
      vi.stubGlobal('fetch', fetchMock);

      const res = await fetchSavedBusinesses({ page: 2, per_page: 10 });

      expect(res.ok).toBe(true);
      expect(res.status).toBe(200);
      expect(fetchMock).toHaveBeenCalledWith(
        '/api/account/saved-businesses?page=2&per_page=10',
        expect.objectContaining({
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
        })
      );
    });

    it('fetchSavedBusinessIds returns 500 IDs cleanly from BFF', async () => {
      const ids = Array.from({ length: 500 }, (_, i) => `01HQBIZ${String(i).padStart(19, '0')}`);
      const fetchMock = vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            status: 'success',
            data: { saved_ids: ids },
          }),
          { status: 200, headers: { 'Content-Type': 'application/json' } }
        )
      );
      vi.stubGlobal('fetch', fetchMock);

      const res = await fetchSavedBusinessIds();

      expect(res.ok).toBe(true);
      expect(res.payload?.status).toBe('success');
      if (res.payload?.status === 'success') {
        expect(res.payload.data.saved_ids).toHaveLength(500);
      }
    });

    it('saveBusiness sends PUT with CSRF header and returns 201 payload', async () => {
      const fetchMock = vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            status: 'success',
            data: { saved: true, public_id: VALID_ULID },
          }),
          { status: 201, headers: { 'Content-Type': 'application/json' } }
        )
      );
      vi.stubGlobal('fetch', fetchMock);

      const res = await saveBusiness(VALID_ULID);

      expect(res.ok).toBe(true);
      expect(res.status).toBe(201);
      expect(fetchMock).toHaveBeenCalledWith(
        `/api/account/saved-businesses/${VALID_ULID}`,
        expect.objectContaining({
          method: 'PUT',
          credentials: 'same-origin',
          headers: expect.objectContaining({
            'X-CSRF-Token': expect.any(String),
          }),
        })
      );
    });

    it('unsaveBusiness sends DELETE with CSRF header and handles 204 No Content', async () => {
      const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
      vi.stubGlobal('fetch', fetchMock);

      const res = await unsaveBusiness(VALID_ULID);

      expect(res.ok).toBe(true);
      expect(res.status).toBe(204);
      expect(res.payload).toBeNull();
      expect(fetchMock).toHaveBeenCalledWith(
        `/api/account/saved-businesses/${VALID_ULID}`,
        expect.objectContaining({
          method: 'DELETE',
          credentials: 'same-origin',
          headers: expect.objectContaining({
            'X-CSRF-Token': expect.any(String),
          }),
        })
      );
    });
  });
});
