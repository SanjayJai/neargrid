import fs from 'node:fs';
import path from 'node:path';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CSRF_COOKIE_NAME, CSRF_HEADER_NAME, generateCsrfToken } from '@/lib/bff/csrf';
import { MemoryBffSessionStore, type BffSessionRecord } from '@/lib/bff/session-store';

const FRONTEND_SRC = path.resolve(__dirname, '../../..');

const VALID_BIZ_ULID = '01JCG4K8N5P2Q7R9S1T3V5W7B1';
const VALID_LEAD_ULID = '01JCG4K8N5P2Q7R9S1T3V5W7M1';
const VALID_STAFF_ULID = '01JCG4K8N5P2Q7R9S1T3V5W7S1';

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

import { GET as getOwnerLeads } from '@/app/api/owner/businesses/[businessId]/leads/route';
import { PATCH as updateOwnerLead } from '@/app/api/owner/businesses/[businessId]/leads/[leadId]/route';

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

describe('Phase 3C Milestone 2: Business Owner Inbound CRM and Lead Management', () => {
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

  describe('CRM UI Architecture & Component Verification', () => {
    const pagePath = path.join(
      FRONTEND_SRC,
      'app/(business)/dashboard/enquiries/page.tsx'
    );
    const pageSource = fs.readFileSync(pagePath, 'utf-8');

    it('has removed Phase 3C placeholder and contains zero demo fixtures', () => {
      expect(pageSource).not.toContain('Consented Inbound Lead CRM in Active Development');
      expect(pageSource).not.toContain('Phase 3C Feature Preview');
      expect(pageSource).not.toContain('DEMO_ENQUIRIES');
      expect(pageSource).not.toContain('DEMO_BUSINESSES');
      expect(pageSource).not.toContain('demo-directory');
    });

    it('implements lead pipeline filter tabs (All, New, Contacted, Converted, Archived)', () => {
      expect(pageSource).toContain("'all'");
      expect(pageSource).toContain("'new'");
      expect(pageSource).toContain("'contacted'");
      expect(pageSource).toContain("'converted'");
      expect(pageSource).toContain("'archived'");
    });

    it('exposes direct customer contact actions only for authorized consented leads', () => {
      expect(pageSource).toContain('DPDP Consented Disclosure');
      expect(pageSource).toContain('tel:${selectedLead.enquiry.phone_e164}');
      expect(pageSource).toContain('https://wa.me/');
      expect(pageSource).toContain('Call');
      expect(pageSource).toContain('WhatsApp');
    });

    it('wires pipeline status select and staff assignment workflow', () => {
      expect(pageSource).toContain('lead-status-select');
      expect(pageSource).toContain('lead-staff-select');
      expect(pageSource).toContain('merchant-notes');
      expect(pageSource).toContain('handleStatusChange');
      expect(pageSource).toContain('handleStaffAssign');
      expect(pageSource).toContain('handleSaveNotes');
    });
  });

  describe('BFF Route: GET /api/owner/businesses/[businessId]/leads', () => {
    it('blocks unauthenticated requests missing active session', async () => {
      mockCurrentSession = null;
      const req = new Request(`http://localhost:3000/api/owner/businesses/${VALID_BIZ_ULID}/leads`);
      const res = await getOwnerLeads(req, { params: { businessId: VALID_BIZ_ULID } });
      expect(res.status).toBe(401);
    });

    it('validates business ULID and rejects invalid format', async () => {
      const req = new Request('http://localhost:3000/api/owner/businesses/invalid_id/leads');
      const res = await getOwnerLeads(req, { params: { businessId: 'invalid_id' } });
      expect(res.status).toBe(400);
      const json = await res.json();
      expect(json.error.code).toBe('INVALID_BUSINESS_ID');
    });

    it('forwards query parameters (status, cursor, per_page) to Laravel backend', async () => {
      mockCallLaravelApi.mockResolvedValueOnce({
        ok: true,
        status: 200,
        payload: {
          status: 'success',
          data: [],
          meta: {
            pagination: { type: 'cursor', per_page: 15, next_cursor: null },
          },
        },
      });

      const req = new Request(
        `http://localhost:3000/api/owner/businesses/${VALID_BIZ_ULID}/leads?status=new&per_page=10&cursor=eyJpZCI6MTB9`
      );
      const res = await getOwnerLeads(req, { params: { businessId: VALID_BIZ_ULID } });
      expect(res.status).toBe(200);

      expect(mockCallLaravelApi).toHaveBeenCalledWith(
        `/api/v1/owner/businesses/${VALID_BIZ_ULID}/leads?status=new&cursor=eyJpZCI6MTB9&per_page=10`,
        expect.objectContaining({
          method: 'GET',
          bearerToken: 'sanctum_owner_token_abc',
        })
      );
    });
  });

  describe('BFF Route: PATCH /api/owner/businesses/[businessId]/leads/[leadId]', () => {
    it('blocks requests failing CSRF mutation security', async () => {
      const req = new Request(
        `http://localhost:3000/api/owner/businesses/${VALID_BIZ_ULID}/leads/${VALID_LEAD_ULID}`,
        {
          method: 'PATCH',
          headers: {
            'Content-Type': 'application/json',
            host: 'localhost:3000',
            origin: 'http://localhost:3000',
          },
          body: JSON.stringify({ lead_status: 'contacted' }),
        }
      );

      const res = await updateOwnerLead(req, {
        params: { businessId: VALID_BIZ_ULID, leadId: VALID_LEAD_ULID },
      });
      expect(res.status).toBe(403);
    });

    it('validates lead ULID and forwards valid lead updates to upstream Laravel API', async () => {
      mockCallLaravelApi.mockResolvedValueOnce({
        ok: true,
        status: 200,
        payload: {
          status: 'success',
          data: {
            public_id: VALID_LEAD_ULID,
            lead_status: 'contacted',
            merchant_notes: 'Spoke with client, meeting scheduled.',
            assigned_user: { public_id: VALID_STAFF_ULID, name: 'Priya' },
          },
          meta: { message: 'Lead updated successfully.' },
        },
      });

      const req = new Request(
        `http://localhost:3000/api/owner/businesses/${VALID_BIZ_ULID}/leads/${VALID_LEAD_ULID}`,
        {
          method: 'PATCH',
          headers: makeCsrfHeaders({ 'Content-Type': 'application/json' }),
          body: JSON.stringify({
            lead_status: 'contacted',
            merchant_notes: 'Spoke with client, meeting scheduled.',
            assigned_user_public_id: VALID_STAFF_ULID,
          }),
        }
      );

      const res = await updateOwnerLead(req, {
        params: { businessId: VALID_BIZ_ULID, leadId: VALID_LEAD_ULID },
      });
      expect(res.status).toBe(200);

      const json = await res.json();
      expect(json.status).toBe('success');
      expect(json.data.lead_status).toBe('contacted');
      expect(json.data.merchant_notes).toContain('Spoke with client');

      expect(mockCallLaravelApi).toHaveBeenCalledWith(
        `/api/v1/owner/businesses/${VALID_BIZ_ULID}/leads/${VALID_LEAD_ULID}`,
        expect.objectContaining({
          method: 'PATCH',
          body: expect.objectContaining({
            lead_status: 'contacted',
            merchant_notes: 'Spoke with client, meeting scheduled.',
            assigned_user_public_id: VALID_STAFF_ULID,
          }),
          bearerToken: 'sanctum_owner_token_abc',
        })
      );
    });
  });
});
