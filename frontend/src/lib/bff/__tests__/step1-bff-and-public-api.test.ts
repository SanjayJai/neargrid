import fs from 'fs';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  buildSearchQueryString,
  fetchPublicBusinessProfile,
  fetchPublicSearch,
} from '@/lib/api/public-directory';
import { CSRF_COOKIE_NAME, CSRF_HEADER_NAME, generateCsrfToken } from '@/lib/bff/csrf';
import { isTrustedLaravelKycDownloadUrl } from '@/lib/bff/laravel-client';
import { isValidPublicUlid, isValidSlug } from '@/lib/bff/route-helpers';
import { MemoryBffSessionStore, type BffSessionRecord } from '@/lib/bff/session-store';

const VALID_BIZ_ULID = '01JCG4K8N5P2Q7R9S1T3V5W7X9';
const VALID_DOC_ULID = '01JCG4K8N5P2Q7R9S1T3V5W7Y1';
const VALID_MEDIA_ULID = '01JCG4K8N5P2Q7R9S1T3V5W7Z2';

const mockSessionStore = new MemoryBffSessionStore();
let mockCurrentSession: { opaqueSessionId: string; record: BffSessionRecord } | null = null;

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

import { GET as getOwnerBusinesses, POST as createOwnerBusiness } from '@/app/api/owner/businesses/route';
import { GET as getOwnerBusiness } from '@/app/api/owner/businesses/[businessId]/route';
import { POST as uploadBusinessMedia } from '@/app/api/owner/businesses/[businessId]/media/route';
import { DELETE as deleteBusinessMedia } from '@/app/api/owner/businesses/[businessId]/media/[mediaId]/route';
import { GET as downloadKycDocument } from '@/app/api/owner/businesses/[businessId]/kyc-documents/[docId]/download/route';
import { POST as postBusinessClaim } from '@/app/api/businesses/[businessId]/claims/route';

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

describe('Step 1: Route Identifier, Slug & SSRF Security Guards', () => {
  it('validates ULIDs and slugs strictly to block path traversal and injection', () => {
    expect(isValidPublicUlid(VALID_BIZ_ULID)).toBe(true);
    expect(isValidPublicUlid('../etc/passwd')).toBe(false);
    expect(isValidPublicUlid('12345')).toBe(false);
    expect(isValidPublicUlid(`${VALID_BIZ_ULID}/../admin`)).toBe(false);

    expect(isValidSlug('chennai')).toBe(true);
    expect(isValidSlug('t-nagar')).toBe(true);
    expect(isValidSlug('../secret')).toBe(false);
    expect(isValidSlug("chennai' OR 1=1")).toBe(false);
  });

  it('enforces strict origin and path checks on Laravel-issued KYC signed URLs (SSRF guard)', () => {
    expect(
      isTrustedLaravelKycDownloadUrl(
        `http://127.0.0.1:8000/api/v1/kyc-documents/${VALID_DOC_ULID}/download?expires=999999&signature=abc`
      )
    ).toBe(true);
    expect(
      isTrustedLaravelKycDownloadUrl(
        `http://localhost:8000/api/v1/kyc-documents/${VALID_DOC_ULID}/download?expires=999999&signature=abc`
      )
    ).toBe(true);

    // Reject external hosts, cloud metadata IPs, and non-KYC paths
    expect(
      isTrustedLaravelKycDownloadUrl(
        `https://evil.example.com/api/v1/kyc-documents/${VALID_DOC_ULID}/download`
      )
    ).toBe(false);
    expect(
      isTrustedLaravelKycDownloadUrl('http://169.254.169.254/latest/meta-data/')
    ).toBe(false);
    expect(
      isTrustedLaravelKycDownloadUrl('http://127.0.0.1:8000/api/v1/auth/me')
    ).toBe(false);
  });

  it('has zero generic catch-all proxy routes under src/app/api/', () => {
    const apiRoot = path.resolve(process.cwd(), 'src/app/api');
    const dirs: string[] = [];

    function walk(dir: string) {
      for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        if (entry.isDirectory()) {
          dirs.push(entry.name);
          walk(path.join(dir, entry.name));
        }
      }
    }

    walk(apiRoot);
    expect(dirs.some((d) => d.startsWith('[...'))).toBe(false);
  });
});

describe('Step 1: Public Directory API Client', () => {
  const originalFetch = global.fetch;

  afterEach(() => {
    global.fetch = originalFetch;
  });

  it('serializes multilingual search params, array filters, and geo coordinates accurately', () => {
    const qs = buildSearchQueryString({
      q: 'பிளம்பர்',
      city: 'chennai',
      localities: ['t-nagar', 'adyar'],
      amenities: ['parking-available'],
      verified_only: true,
      open_now: true,
      lat: 13.0418,
      lng: 80.2341,
      radius_km: 6,
      sort: 'distance',
      page: 2,
      per_page: 20,
      locale: 'ta',
    });

    const parsed = new URLSearchParams(qs);
    expect(parsed.get('q')).toBe('பிளம்பர்');
    expect(parsed.get('city')).toBe('chennai');
    expect(parsed.getAll('localities[]')).toEqual(['t-nagar', 'adyar']);
    expect(parsed.getAll('amenities[]')).toEqual(['parking-available']);
    expect(parsed.get('verified_only')).toBe('1');
    expect(parsed.get('open_now')).toBe('1');
    expect(parsed.get('radius_km')).toBe('6');
    expect(parsed.get('sort')).toBe('distance');
    expect(parsed.get('locale')).toBe('ta');
  });

  it('returns typed public profile including phone_masked, rating_avg, review_count and handles 301 redirects', async () => {
    global.fetch = vi.fn().mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          status: 'success',
          data: {
            public_id: VALID_BIZ_ULID,
            name: 'Apex Dental Studio',
            slug: 'apex-dental-studio',
            rating_avg: 4.8,
            review_count: 142,
            photo_count: 3,
            contact: {
              phone_masked: '+9198401XXXXX',
              has_whatsapp: true,
              website_url: null,
            },
            media: [],
          },
          meta: { request_id: 'req_1', api_version: 'v1', timestamp: '2026-11-18T00:00:00Z' },
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } }
      )
    );

    const okRes = await fetchPublicBusinessProfile('chennai', 'apex-dental-studio');
    expect(okRes.ok).toBe(true);
    expect(okRes.data?.contact.phone_masked).toBe('+9198401XXXXX');
    expect(okRes.data?.rating_avg).toBe(4.8);
    expect(okRes.data?.review_count).toBe(142);

    // 301 redirect handling
    global.fetch = vi.fn().mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          status: 'error',
          error: {
            code: 'MOVED_PERMANENTLY',
            message: 'Moved',
            details: { target_path: '/chennai/business/new-slug' },
          },
        }),
        { status: 301, headers: { Location: '/chennai/business/new-slug' } }
      )
    );

    const movedRes = await fetchPublicBusinessProfile('chennai', 'old-slug');
    expect(movedRes.ok).toBe(false);
    expect(movedRes.status).toBe(301);
    expect(movedRes.redirectTargetPath).toBe('/chennai/business/new-slug');
  });

  it('gracefully returns 503 error envelope when Laravel API is unreachable', async () => {
    global.fetch = vi.fn().mockRejectedValueOnce(new Error('ECONNREFUSED'));
    const res = await fetchPublicSearch({ city: 'chennai', q: 'dentist' });
    expect(res.ok).toBe(false);
    expect(res.status).toBe(503);
    expect(res.error?.code).toBe('UPSTREAM_UNREACHABLE');
  });
});

describe('Step 1: Authenticated Owner BFF, Multipart Media & Private KYC Proxies', () => {
  const originalFetch = global.fetch;
  const activeToken = '99|lbd_server_only_secret_token';

  beforeEach(async () => {
    const record: BffSessionRecord = {
      accessToken: activeToken,
      sessionId: '01JCG4K8N5P2Q7R9S1T3V5W7AA',
      userPublicId: '01JCG4K8N5P2Q7R9S1T3V5W7BB',
      expiresAt: new Date(Date.now() + 3600_000).toISOString(),
      createdAt: new Date().toISOString(),
    };
    await mockSessionStore.set('opaque-sid-1', record, 3600);
    mockCurrentSession = { opaqueSessionId: 'opaque-sid-1', record };
  });

  afterEach(() => {
    global.fetch = originalFetch;
    mockCurrentSession = null;
  });

  it('rejects unauthenticated requests with 401 and cross-origin/missing-CSRF mutations with 403', async () => {
    mockCurrentSession = null;
    const unauth = await getOwnerBusinesses(new Request('http://localhost:3000/api/owner/businesses'));
    expect(unauth.status).toBe(401);

    const badOrigin = await createOwnerBusiness(
      new Request('http://localhost:3000/api/owner/businesses', {
        method: 'POST',
        headers: {
          host: 'localhost:3000',
          origin: 'https://attacker.example',
          'content-type': 'application/json',
        },
        body: JSON.stringify({ name: 'Test' }),
      })
    );
    expect(badOrigin.status).toBe(403);
  });

  it('auto-clears local BFF session cookie & store when upstream Laravel returns 401', async () => {
    global.fetch = vi.fn().mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          status: 'error',
          error: { code: 'UNAUTHENTICATED', message: 'Token revoked.' },
        }),
        { status: 401, headers: { 'Content-Type': 'application/json' } }
      )
    );

    const res = await getOwnerBusinesses(new Request('http://localhost:3000/api/owner/businesses'));
    expect(res.status).toBe(401);
    expect(await mockSessionStore.get('opaque-sid-1')).toBeNull();
    expect(res.headers.get('set-cookie')).toContain('lbd_sid=');
  });

  it('forwards cross-business 403 Policy denial without leaking tokens or clearing valid session', async () => {
    global.fetch = vi.fn().mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          status: 'error',
          error: { code: 'FORBIDDEN', message: 'You are not authorized.' },
        }),
        { status: 403, headers: { 'Content-Type': 'application/json' } }
      )
    );

    const res = await getOwnerBusiness(
      new Request(`http://localhost:3000/api/owner/businesses/${VALID_BIZ_ULID}`),
      { params: { businessId: VALID_BIZ_ULID } }
    );
    expect(res.status).toBe(403);
    expect(res.headers.get('cache-control')).toBe('private, no-store');
    expect(await mockSessionStore.get('opaque-sid-1')).not.toBeNull();
  });

  it('proxies multipart business media upload with allowlisted fields and server-side Bearer token', async () => {
    let capturedAuth: string | null = null;
    let capturedFields: string[] = [];

    global.fetch = vi.fn().mockImplementationOnce(async (_url: string, init?: RequestInit) => {
      const hdrs = init?.headers as Record<string, string>;
      capturedAuth = hdrs?.Authorization ?? null;
      if (init?.body instanceof FormData) {
        capturedFields = Array.from(init.body.keys());
      }
      return new Response(
        JSON.stringify({
          status: 'success',
          data: {
            public_id: VALID_MEDIA_ULID,
            media_type: 'gallery',
            upload_status: 'ready',
            exif_stripped: true,
          },
          meta: { request_id: 'req_m', api_version: 'v1', timestamp: '2026-11-18T00:00:00Z' },
        }),
        { status: 201, headers: { 'Content-Type': 'application/json' } }
      );
    });

    const fd = new FormData();
    fd.append('file', new Blob(['fake-png'], { type: 'image/png' }), 'store.png');
    fd.append('media_type', 'gallery');
    fd.append('alt_text', 'Front view');
    fd.append('unexpected_field', 'should-be-stripped');

    const res = await uploadBusinessMedia(
      new Request(`http://localhost:3000/api/owner/businesses/${VALID_BIZ_ULID}/media`, {
        method: 'POST',
        headers: makeCsrfHeaders(),
        body: fd,
      }),
      { params: { businessId: VALID_BIZ_ULID } }
    );

    expect(res.status).toBe(201);
    expect(capturedAuth).toBe(`Bearer ${activeToken}`);
    expect(capturedFields).toEqual(['file', 'media_type', 'alt_text']);
    const text = await res.text();
    expect(text).not.toContain(activeToken);

    // Delete media rejects invalid ULID with 400
    const badDelete = await deleteBusinessMedia(
      new Request(`http://localhost:3000/api/owner/businesses/${VALID_BIZ_ULID}/media/bad`, {
        method: 'DELETE',
        headers: makeCsrfHeaders(),
      }),
      { params: { businessId: VALID_BIZ_ULID, mediaId: '../bad' } }
    );
    expect(badDelete.status).toBe(400);
  });

  it('streams private KYC bytes server-side without exposing Bearer token, signed URL, or private paths', async () => {
    const fakePdf = '%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF';
    const mintedSignedUrl = `http://127.0.0.1:8000/api/v1/kyc-documents/${VALID_DOC_ULID}/download?actor=01JCG4K8N5P2Q7R9S1T3V5W7BB&expires=999999&signature=super_secret_sig`;

    const fetchMock = vi
      .fn()
      // 1st call: mint short-lived signed URL
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            status: 'success',
            data: {
              download_url: mintedSignedUrl,
              expires_at: '2026-11-18T12:00:00Z',
              ttl_seconds: 60,
            },
          }),
          { status: 200, headers: { 'Content-Type': 'application/json' } }
        )
      )
      // 2nd call: download private PDF binary
      .mockResolvedValueOnce(
        new Response(fakePdf, {
          status: 200,
          headers: {
            'Content-Type': 'application/pdf',
            'X-Request-ID': 'req_kyc_1',
          },
        })
      );

    global.fetch = fetchMock;

    const res = await downloadKycDocument(
      new Request(
        `http://localhost:3000/api/owner/businesses/${VALID_BIZ_ULID}/kyc-documents/${VALID_DOC_ULID}/download`
      ),
      { params: { businessId: VALID_BIZ_ULID, docId: VALID_DOC_ULID } }
    );

    expect(res.status).toBe(200);
    expect(res.headers.get('content-type')).toBe('application/pdf');
    expect(res.headers.get('cache-control')).toContain('no-store');
    expect(res.headers.get('x-content-type-options')).toBe('nosniff');

    const body = await res.text();
    expect(body).toBe(fakePdf);
    expect(body).not.toContain('super_secret_sig');
    expect(body).not.toContain(activeToken);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('blocks SSRF if upstream signed-url response returns an untrusted origin', async () => {
    global.fetch = vi.fn().mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          status: 'success',
          data: {
            download_url: 'https://evil.example.com/steal-token',
            expires_at: '2026-11-18T12:00:00Z',
            ttl_seconds: 60,
          },
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } }
      )
    );

    const res = await downloadKycDocument(
      new Request(
        `http://localhost:3000/api/owner/businesses/${VALID_BIZ_ULID}/kyc-documents/${VALID_DOC_ULID}/download`
      ),
      { params: { businessId: VALID_BIZ_ULID, docId: VALID_DOC_ULID } }
    );

    expect(res.status).toBe(502);
    const json = (await res.json()) as { error?: { code?: string } };
    expect(json.error?.code).toBe('UNTRUSTED_SIGNED_URL');
  });

  describe('Step 4 Security Review: Business Claim BFF endpoint', () => {
    it('returns HTTP 401 when an unauthenticated user attempts to submit a claim', async () => {
      mockCurrentSession = null;

      const res = await postBusinessClaim(
        new Request(`http://localhost:3000/api/businesses/${VALID_BIZ_ULID}/claims`, {
          method: 'POST',
          headers: makeCsrfHeaders({ 'Content-Type': 'application/json' }),
          body: JSON.stringify({
            proof_file_path_private: 'kyc/2026/test.pdf',
            claim_notes: 'Owner verification',
          }),
        }),
        { params: { businessId: VALID_BIZ_ULID } }
      );

      expect(res.status).toBe(401);
      const json = (await res.json()) as { error?: { code?: string } };
      expect(json.error?.code).toBe('UNAUTHENTICATED');
    });

    it('returns HTTP 501 CLAIM_UPLOAD_UNAVAILABLE with zero Laravel upstream calls when an authenticated user submits a claim', async () => {
      const activeToken = '55|sanctum_claimant_token';
      mockCurrentSession = {
        opaqueSessionId: 'sess_claimant_123456789012345678901234567890',
        record: {
          accessToken: activeToken,
          sessionId: '01JCG4K8N5P2Q7R9S1T3V5W7A1',
          userPublicId: '01JCG4K8N5P2Q7R9S1T3V5W7A2',
          expiresAt: new Date(Date.now() + 3600_000).toISOString(),
          createdAt: new Date().toISOString(),
        },
      };

      const upstreamFetchSpy = vi.fn();
      global.fetch = upstreamFetchSpy;

      const res = await postBusinessClaim(
        new Request(`http://localhost:3000/api/businesses/${VALID_BIZ_ULID}/claims`, {
          method: 'POST',
          headers: makeCsrfHeaders({ 'Content-Type': 'application/json' }),
          body: JSON.stringify({
            proof_file_path_private: 'kyc/2026/fake-path.pdf',
            claim_notes: 'Legitimate owner claim',
          }),
        }),
        { params: { businessId: VALID_BIZ_ULID } }
      );

      expect(res.status).toBe(501);
      const json = (await res.json()) as {
        status: string;
        error?: { code?: string; message?: string };
      };
      expect(json.status).toBe('error');
      expect(json.error?.code).toBe('CLAIM_UPLOAD_UNAVAILABLE');
      expect(json.error?.message).toContain('platform administrator');
      expect(json.error?.message).not.toContain('support@neargrid.in');

      // Verify that no upstream Laravel call was made and no claim was created in database
      expect(upstreamFetchSpy).not.toHaveBeenCalled();
    });
  });
});
