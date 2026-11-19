import fs from 'node:fs';
import path from 'node:path';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CSRF_COOKIE_NAME, CSRF_HEADER_NAME, generateCsrfToken } from '@/lib/bff/csrf';
import { MemoryBffSessionStore, type BffSessionRecord } from '@/lib/bff/session-store';

const FRONTEND_SRC = path.resolve(__dirname, '../../..');

const VALID_BIZ_ULID = '01JCG4K8N5P2Q7R9S1T3V5W7B1';
const VALID_REVIEW_ULID = '01JCG4K8N5P2Q7R9S1T3V5W7M1';
const INVALID_ULID = 'not-a-valid-ulid-123';

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

import { GET as getPublicReviews, POST as postPublicReview } from '@/app/api/public/businesses/[businessId]/reviews/route';
import { POST as postVerifyOtp } from '@/app/api/public/reviews/[reviewId]/verify-otp/route';
import { POST as postReportReview } from '@/app/api/public/reviews/[reviewId]/report/route';
import { GET as getOwnerReviews } from '@/app/api/owner/businesses/[businessId]/reviews/route';
import { POST as postOwnerReply, DELETE as deleteOwnerReply } from '@/app/api/owner/businesses/[businessId]/reviews/[reviewId]/reply/route';

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

describe('Phase 3C Milestone 3: Customer Reviews, Bayesian Ratings & Owner Replies', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockCurrentSession = {
      opaqueSessionId: 'sess_owner_123',
      record: {
        accessToken: 'sanctum_owner_token_abc',
        sessionId: 'ses_1',
        userPublicId: '01JUSR123456',
        expiresAt: new Date(Date.now() + 86400000).toISOString(),
        createdAt: new Date().toISOString(),
      },
    };
  });

  describe('UI Component Architecture Verification', () => {
    it('CustomerReviewsSection renders breakdown, filters, verified customer badge and modal', () => {
      const componentPath = path.join(
        FRONTEND_SRC,
        'modules/customer/components/CustomerReviewsSection.tsx'
      );
      const source = fs.readFileSync(componentPath, 'utf-8');

      expect(source).toContain('Ratings &amp; Reviews');
      expect(source).toContain('Write a Review');
      expect(source).toContain('Verified Customer');
      expect(source).toContain('Response from the business owner');
      expect(source).toContain('Report Review');
      expect(source).toContain('DUPLICATE_REVIEW_30_DAYS');
      expect(source).toContain('CANNOT_REVIEW_OWN_BUSINESS');
    });

    it('BusinessProfileView embeds CustomerReviewsSection and removes Phase 3C pending notice', () => {
      const profilePath = path.join(
        FRONTEND_SRC,
        'modules/customer/components/BusinessProfileView.tsx'
      );
      const source = fs.readFileSync(profilePath, 'utf-8');

      expect(source).toContain('CustomerReviewsSection');
      expect(source).not.toContain('Individual Customer Reviews & Submissions');
      expect(source).not.toContain('Phase 3C Scheduled');
    });

    it('Business Owner Reviews Dashboard implements live CRM without mock fixtures', () => {
      const dashboardPath = path.join(
        FRONTEND_SRC,
        'app/(business)/dashboard/reviews/page.tsx'
      );
      const source = fs.readFileSync(dashboardPath, 'utf-8');

      expect(source).toContain('Customer Reviews');
      expect(source).toContain('Needs Reply');
      expect(source).toContain('Business Owner Response');
      expect(source).toContain('handleSaveReply');
      expect(source).toContain('handleDeleteReply');
      expect(source).not.toContain('DEMO_REVIEWS');
      expect(source).not.toContain('demo-reviews');
    });
  });

  describe('Next.js BFF Security & Proxy Routes', () => {
    it('enforces CSRF and mutation security on public review submission', async () => {
      // Insecure request without CSRF -> 403
      const badReq = new Request('http://localhost:3000/api/public/businesses/chennai/apex-dental/reviews', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          customer_name: 'Ananya S',
          phone_e164: '+919840111222',
          rating: 5,
        }),
      });

      const badRes = await postPublicReview(badReq, {
        params: { businessId: VALID_BIZ_ULID },
      });
      expect(badRes.status).toBe(403);

      // Secure request with CSRF -> calls upstream
      mockCallLaravelApi.mockResolvedValueOnce({
        status: 201,
        headers: new Headers({ 'content-type': 'application/json' }),
        data: { status: 'created', data: { review: { public_id: VALID_REVIEW_ULID }, requires_otp: false } },
      });

      const goodReq = new Request(`http://localhost:3000/api/public/businesses/${VALID_BIZ_ULID}/reviews`, {
        method: 'POST',
        headers: makeCsrfHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({
          customer_name: 'Ananya S',
          phone_e164: '+919840111222',
          rating: 5,
        }),
      });

      const goodRes = await postPublicReview(goodReq, {
        params: { businessId: VALID_BIZ_ULID },
      });
      expect(goodRes.status).toBe(201);
      expect(mockCallLaravelApi).toHaveBeenCalledWith(
        `/api/v1/businesses/${VALID_BIZ_ULID}/reviews`,
        expect.objectContaining({ method: 'POST' })
      );
    });

    it('validates review ULID and enforces CSRF on OTP verification', async () => {
      // Invalid ULID -> 400
      const invalidReq = new Request('http://localhost:3000/api/public/reviews/invalid/verify-otp', {
        method: 'POST',
        headers: makeCsrfHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ code: '123456' }),
      });

      const invalidRes = await postVerifyOtp(invalidReq, {
        params: { reviewId: INVALID_ULID },
      });
      expect(invalidRes.status).toBe(400);

      // Valid ULID with CSRF -> proxies upstream
      mockCallLaravelApi.mockResolvedValueOnce({
        status: 200,
        headers: new Headers({ 'content-type': 'application/json' }),
        data: { status: 'success', data: { public_id: VALID_REVIEW_ULID } },
      });

      const validReq = new Request('http://localhost:3000/api/public/reviews/valid/verify-otp', {
        method: 'POST',
        headers: makeCsrfHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ code: '123456' }),
      });

      const validRes = await postVerifyOtp(validReq, {
        params: { reviewId: VALID_REVIEW_ULID },
      });
      expect(validRes.status).toBe(200);
    });

    it('validates review ULID on report submission', async () => {
      const invalidReq = new Request('http://localhost:3000/api/public/reviews/invalid/report', {
        method: 'POST',
        headers: makeCsrfHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ reason: 'spam' }),
      });

      const invalidRes = await postReportReview(invalidReq, {
        params: { reviewId: INVALID_ULID },
      });
      expect(invalidRes.status).toBe(400);
    });

    it('rejects unauthenticated requests to owner reviews endpoint', async () => {
      mockCurrentSession = null;

      const req = new Request(`http://localhost:3000/api/owner/businesses/${VALID_BIZ_ULID}/reviews`);
      const res = await getOwnerReviews(req, {
        params: { businessId: VALID_BIZ_ULID },
      });

      expect(res.status).toBe(401);
      const json = await res.json();
      expect(json.error.code).toBe('UNAUTHENTICATED');
    });

    it('proxies owner reviews when authenticated with session bearer token', async () => {
      mockCallLaravelApi.mockResolvedValueOnce({
        status: 200,
        headers: new Headers({ 'content-type': 'application/json' }),
        data: { status: 'success', data: [] },
      });

      const req = new Request(`http://localhost:3000/api/owner/businesses/${VALID_BIZ_ULID}/reviews?rating=5`);
      const res = await getOwnerReviews(req, {
        params: { businessId: VALID_BIZ_ULID },
      });

      expect(res.status).toBe(200);
      expect(mockCallLaravelApi).toHaveBeenCalledWith(
        `/api/v1/owner/businesses/${VALID_BIZ_ULID}/reviews?rating=5`,
        expect.objectContaining({
          method: 'GET',
          bearerToken: 'sanctum_owner_token_abc',
        })
      );
    });

    it('enforces CSRF and ULID validation on owner reply mutations', async () => {
      // Missing CSRF -> 403
      const noCsrfReq = new Request(
        `http://localhost:3000/api/owner/businesses/${VALID_BIZ_ULID}/reviews/${VALID_REVIEW_ULID}/reply`,
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ reply_text: 'Thank you' }),
        }
      );
      const noCsrfRes = await postOwnerReply(noCsrfReq, {
        params: { businessId: VALID_BIZ_ULID, reviewId: VALID_REVIEW_ULID },
      });
      expect(noCsrfRes.status).toBe(403);

      // Invalid ULID -> 400
      const badIdReq = new Request(
        `http://localhost:3000/api/owner/businesses/${INVALID_ULID}/reviews/${VALID_REVIEW_ULID}/reply`,
        {
          method: 'POST',
          headers: makeCsrfHeaders({ 'Content-Type': 'application/json' }),
          body: JSON.stringify({ reply_text: 'Thank you' }),
        }
      );
      const badIdRes = await postOwnerReply(badIdReq, {
        params: { businessId: INVALID_ULID, reviewId: VALID_REVIEW_ULID },
      });
      expect(badIdRes.status).toBe(400);

      // Valid call proxies upstream with bearer token
      mockCallLaravelApi.mockResolvedValueOnce({
        status: 200,
        headers: new Headers({ 'content-type': 'application/json' }),
        data: { status: 'success', data: { public_id: VALID_REVIEW_ULID } },
      });

      const goodReq = new Request(
        `http://localhost:3000/api/owner/businesses/${VALID_BIZ_ULID}/reviews/${VALID_REVIEW_ULID}/reply`,
        {
          method: 'POST',
          headers: makeCsrfHeaders({ 'Content-Type': 'application/json' }),
          body: JSON.stringify({ reply_text: 'Thank you' }),
        }
      );
      const goodRes = await postOwnerReply(goodReq, {
        params: { businessId: VALID_BIZ_ULID, reviewId: VALID_REVIEW_ULID },
      });
      expect(goodRes.status).toBe(200);
      expect(mockCallLaravelApi).toHaveBeenCalledWith(
        `/api/v1/owner/businesses/${VALID_BIZ_ULID}/reviews/${VALID_REVIEW_ULID}/reply`,
        expect.objectContaining({
          method: 'POST',
          bearerToken: 'sanctum_owner_token_abc',
        })
      );

      // DELETE reply proxies upstream
      mockCallLaravelApi.mockResolvedValueOnce({
        status: 200,
        headers: new Headers({ 'content-type': 'application/json' }),
        data: { status: 'success', data: null },
      });

      const delReq = new Request(
        `http://localhost:3000/api/owner/businesses/${VALID_BIZ_ULID}/reviews/${VALID_REVIEW_ULID}/reply`,
        {
          method: 'DELETE',
          headers: makeCsrfHeaders(),
        }
      );
      const delRes = await deleteOwnerReply(delReq, {
        params: { businessId: VALID_BIZ_ULID, reviewId: VALID_REVIEW_ULID },
      });
      expect(delRes.status).toBe(200);
      expect(mockCallLaravelApi).toHaveBeenCalledWith(
        `/api/v1/owner/businesses/${VALID_BIZ_ULID}/reviews/${VALID_REVIEW_ULID}/reply`,
        expect.objectContaining({
          method: 'DELETE',
          bearerToken: 'sanctum_owner_token_abc',
        })
      );
    });

    it('forwards query filters on public reviews endpoint', async () => {
      mockCallLaravelApi.mockResolvedValueOnce({
        status: 200,
        headers: new Headers({ 'content-type': 'application/json' }),
        data: { status: 'success', data: [] },
      });

      const req = new Request(
        `http://localhost:3000/api/public/businesses/${VALID_BIZ_ULID}/reviews?rating=5&sort=highest`
      );

      const res = await getPublicReviews(req, {
        params: { businessId: VALID_BIZ_ULID },
      });

      expect(res.status).toBe(200);
      expect(mockCallLaravelApi).toHaveBeenCalledWith(
        `/api/v1/businesses/${VALID_BIZ_ULID}/reviews?rating=5&sort=highest`,
        expect.objectContaining({ method: 'GET' })
      );
    });
  });
});
