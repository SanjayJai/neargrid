import fs from 'node:fs';
import path from 'node:path';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CSRF_COOKIE_NAME, CSRF_HEADER_NAME, generateCsrfToken } from '@/lib/bff/csrf';
import { isValidPublicUlid } from '@/lib/bff/route-helpers';
import { MemoryBffSessionStore, type BffSessionRecord } from '@/lib/bff/session-store';

const FRONTEND_SRC = path.resolve(__dirname, '../../..');

const VALID_ENQUIRY_ULID = '01JCG4K8N5P2Q7R9S1T3V5W7E1';
const VALID_BIZ_ULID = '01JCG4K8N5P2Q7R9S1T3V5W7B1';

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

import { POST as postEnquiry } from '@/app/api/public/enquiries/route';
import { POST as verifyEnquiryOtp } from '@/app/api/public/enquiries/[enquiryId]/verify-otp/route';
import { POST as recordContactReveal } from '@/app/api/public/businesses/[businessId]/contact-reveal/route';

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

describe('Phase 3C Milestone 1: Privacy-First Customer Enquiries and Lead Generation', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockCurrentSession = null;
  });

  describe('Affirmative Consent, DPDP Compliance & Modal Component Architecture', () => {
    const modalPath = path.join(FRONTEND_SRC, 'modules/customer/components/EnquiryModal.tsx');
    const modalSource = fs.readFileSync(modalPath, 'utf-8');

    it('requires explicit affirmative consent and references DPDP Act compliance', () => {
      expect(modalSource).toContain('Digital Personal Data Protection');
      expect(modalSource).toContain('consentGiven');
      expect(modalSource).toContain('setConsentGiven');
      // Mandatory checkbox validation
      expect(modalSource).toContain('!consentGiven');
    });

    it('distinguishes direct enquiry consent from broadcast matching consent', () => {
      // Direct disclosure
      expect(modalSource).toContain('sharing my name, phone number, and enquiry details directly with');
      // Broadcast disclosure: explicitly mentions up to 4 eligible verified businesses
      expect(modalSource).toContain('up to 4 eligible, verified businesses');
    });

    it('implements 2-step OTP flow with 45s resend timer and 6-digit input', () => {
      expect(modalSource).toContain('type ModalStep =');
      expect(modalSource).toContain("'form' | 'otp' | 'success'");
      expect(modalSource).toContain('resendTimer');
      expect(modalSource).toContain('45');
      expect(modalSource).toContain('maxLength={6}');
      expect(modalSource).toContain('otp.length !== 6');
    });

    it('enforces Indian phone number format validation (10 digits starting 6-9)', () => {
      expect(modalSource).toContain('^[6-9]\\d{9}$');
      expect(modalSource).toContain('requirement.trim().length < 10');
    });

    it('wires into BusinessProfileView without leaking raw phone links or demo data', () => {
      const profileViewPath = path.join(
        FRONTEND_SRC,
        'modules/customer/components/BusinessProfileView.tsx'
      );
      const profileSource = fs.readFileSync(profileViewPath, 'utf-8');

      expect(profileSource).toContain('EnquiryModal');
      expect(profileSource).toContain('enquiryModalOpen');
      expect(profileSource).toContain('handleContactReveal');
      expect(profileSource).toContain('/contact-reveal');
      expect(profileSource).not.toContain('href={`tel:');
      expect(profileSource).not.toContain('https://wa.me/');
      expect(profileSource).not.toContain('demo-directory');
    });
  });

  describe('BFF Route: POST /api/public/enquiries', () => {
    it('blocks unauthenticated requests missing CSRF protection', async () => {
      const req = new Request('http://localhost:3000/api/public/enquiries', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          host: 'localhost:3000',
          origin: 'http://localhost:3000',
        },
        body: JSON.stringify({
          customer_name: 'Sanjay Kumar',
          phone: '9840123456',
          category_slug: 'dentists',
          city_slug: 'chennai',
          requirement: 'Need teeth cleaning appointment',
          consent_given: true,
        }),
      });

      const res = await postEnquiry(req);
      expect(res.status).toBe(403);
      const json = await res.json();
      expect(json.error.code).toBe('INVALID_CSRF_TOKEN');
    });

    it('forwards valid enquiry request to Laravel backend and handles OTP challenge', async () => {
      mockCallLaravelApi.mockResolvedValueOnce({
        ok: true,
        status: 202,
        payload: {
          status: 'success',
          data: {
            public_id: VALID_ENQUIRY_ULID,
            status: 'pending_otp',
            requires_otp: true,
            challenge_id: 'ch_test_12345',
            resend_after_seconds: 45,
          },
          meta: { message: 'OTP challenge initiated.' },
        },
      });

      const req = new Request('http://localhost:3000/api/public/enquiries', {
        method: 'POST',
        headers: makeCsrfHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({
          customer_name: 'Sanjay Kumar',
          phone: '9840123456',
          category_slug: 'dentists',
          city_slug: 'chennai',
          requirement: 'Need teeth cleaning appointment tomorrow',
          consent_given: true,
        }),
      });

      const res = await postEnquiry(req);
      expect(res.status).toBe(202);
      const json = await res.json();
      expect(json.status).toBe('success');
      expect(json.data.public_id).toBe(VALID_ENQUIRY_ULID);
      expect(json.data.requires_otp).toBe(true);

      expect(mockCallLaravelApi).toHaveBeenCalledWith(
        '/api/v1/enquiries',
        expect.objectContaining({
          method: 'POST',
          body: expect.objectContaining({
            customer_name: 'Sanjay Kumar',
            phone: '9840123456',
          }),
        })
      );
    });

    it('passes authenticated bearer token if customer has active session', async () => {
      mockCurrentSession = {
        opaqueSessionId: 'sess_opaque_abc',
        record: {
          accessToken: 'sanctum_token_secret_123',
          sessionId: 'ses_1',
          userPublicId: '01JUSR123456',
          expiresAt: new Date(Date.now() + 86400000).toISOString(),
          createdAt: new Date().toISOString(),
        },
      };

      mockCallLaravelApi.mockResolvedValueOnce({
        ok: true,
        status: 201,
        payload: {
          status: 'success',
          data: {
            public_id: VALID_ENQUIRY_ULID,
            status: 'dispatched',
            requires_otp: false,
          },
        },
      });

      const req = new Request('http://localhost:3000/api/public/enquiries', {
        method: 'POST',
        headers: makeCsrfHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({
          customer_name: 'Existing Customer',
          phone: '9840123456',
          category_slug: 'dentists',
          city_slug: 'chennai',
          requirement: 'Need dental checkup urgently',
          consent_given: true,
        }),
      });

      const res = await postEnquiry(req);
      expect(res.status).toBe(201);
      expect(mockCallLaravelApi).toHaveBeenCalledWith(
        '/api/v1/enquiries',
        expect.objectContaining({
          bearerToken: 'sanctum_token_secret_123',
        })
      );
    });
  });

  describe('BFF Route: POST /api/public/enquiries/[enquiryId]/verify-otp', () => {
    it('validates enquiry ULID and rejects path traversal', async () => {
      expect(isValidPublicUlid(VALID_ENQUIRY_ULID)).toBe(true);
      expect(isValidPublicUlid('../../etc/passwd')).toBe(false);

      const req = new Request(
        'http://localhost:3000/api/public/enquiries/../../etc/passwd/verify-otp',
        {
          method: 'POST',
          headers: makeCsrfHeaders({ 'Content-Type': 'application/json' }),
          body: JSON.stringify({ otp: '123456' }),
        }
      );

      const res = await verifyEnquiryOtp(req, { params: { enquiryId: '../../etc/passwd' } });
      expect(res.status).toBe(400);
      const json = await res.json();
      expect(json.error.code).toBe('INVALID_ENQUIRY_ID');
    });

    it('verifies OTP, captures Sanctum token in BFF store, attaches HttpOnly cookie, and strips token from client payload', async () => {
      const upstreamToken = '1|sanctum_secret_plain_text_token_xyz999';

      mockCallLaravelApi.mockResolvedValueOnce({
        ok: true,
        status: 200,
        payload: {
          status: 'success',
          data: {
            enquiry: {
              public_id: VALID_ENQUIRY_ULID,
              status: 'dispatched',
            },
            auth: {
              access_token: upstreamToken,
              session_id: 'ses_upstream_123',
              expires_at: new Date(Date.now() + 86400000).toISOString(),
              user: {
                public_id: '01JUSR99999999',
                phone_e164: '+919840123456',
                name: 'Sanjay Kumar',
              },
            },
          },
          meta: { message: 'Enquiry confirmed.' },
        },
      });

      const req = new Request(
        `http://localhost:3000/api/public/enquiries/${VALID_ENQUIRY_ULID}/verify-otp`,
        {
          method: 'POST',
          headers: makeCsrfHeaders({ 'Content-Type': 'application/json' }),
          body: JSON.stringify({ otp: '123456' }),
        }
      );

      const res = await verifyEnquiryOtp(req, { params: { enquiryId: VALID_ENQUIRY_ULID } });
      expect(res.status).toBe(200);

      const json = await res.json();
      expect(json.status).toBe('success');
      expect(json.data.enquiry.status).toBe('dispatched');
      expect(json.data.user.public_id).toBe('01JUSR99999999');

      // CRITICAL PRIVACY & SECURITY CHECK: Sanctum token MUST NOT leak to client JSON
      expect(JSON.stringify(json)).not.toContain(upstreamToken);
      expect(JSON.stringify(json)).not.toContain('access_token');

      // HttpOnly cookie MUST be set
      const setCookie = res.headers.get('set-cookie');
      expect(setCookie).toBeTruthy();
      expect(setCookie).toContain('lbd_sid=');
      expect(setCookie).toContain('HttpOnly');
    });
  });

  describe('BFF Route: POST /api/public/businesses/[businessId]/contact-reveal', () => {
    it('validates business ULID and records contact intent without exposing private number', async () => {
      mockCallLaravelApi.mockResolvedValueOnce({
        ok: true,
        status: 200,
        payload: {
          status: 'success',
          data: {
            business_public_id: VALID_BIZ_ULID,
            revealed: true,
          },
        },
      });

      const req = new Request(
        `http://localhost:3000/api/public/businesses/${VALID_BIZ_ULID}/contact-reveal`,
        {
          method: 'POST',
          headers: makeCsrfHeaders({ 'Content-Type': 'application/json' }),
          body: JSON.stringify({ channel: 'phone' }),
        }
      );

      const res = await recordContactReveal(req, { params: { businessId: VALID_BIZ_ULID } });
      expect(res.status).toBe(200);
      const json = await res.json();
      expect(json.status).toBe('success');
      expect(json.data.revealed).toBe(true);

      expect(mockCallLaravelApi).toHaveBeenCalledWith(
        `/api/v1/businesses/${VALID_BIZ_ULID}/contact-reveal`,
        expect.objectContaining({
          method: 'POST',
          body: { channel: 'phone' },
        })
      );
    });
  });
});
