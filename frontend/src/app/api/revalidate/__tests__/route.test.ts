import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { POST } from '../route';
import crypto from 'node:crypto';

vi.mock('next/cache', () => ({
  revalidatePath: vi.fn(),
}));

import { revalidatePath } from 'next/cache';

describe('Next.js Realtime ISR Revalidation Webhook (/api/revalidate)', () => {
  const TEST_SECRET = 'test_webhook_shared_secret_32_bytes_len';

  beforeEach(() => {
    vi.clearAllMocks();
    process.env.REVALIDATION_WEBHOOK_SECRET = TEST_SECRET;
  });

  afterEach(() => {
    delete process.env.REVALIDATION_WEBHOOK_SECRET;
  });

  function createSignedRequest(
    payload: unknown,
    options: {
      secret?: string;
      timestamp?: number;
      tamperSignature?: boolean;
      omitSignature?: boolean;
      malformedHeader?: boolean;
    } = {}
  ): Request {
    const rawBody =
      typeof payload === 'string' ? payload : JSON.stringify(payload);
    const ts = options.timestamp ?? Math.floor(Date.now() / 1000);
    const secret = options.secret ?? TEST_SECRET;

    const signature = crypto
      .createHmac('sha256', secret)
      .update(`${ts}.${rawBody}`)
      .digest('hex');

    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
    };

    if (!options.omitSignature) {
      if (options.malformedHeader) {
        headers['X-Webhook-Signature'] = 'invalid_format';
      } else {
        const finalSig = options.tamperSignature
          ? signature.slice(0, -2) + '00'
          : signature;
        headers['X-Webhook-Signature'] = `t=${ts},v1=${finalSig}`;
      }
    }

    return new Request('http://localhost:3000/api/revalidate', {
      method: 'POST',
      headers,
      body: rawBody,
    });
  }

  it('rejects request with 401 when signature header is missing', async () => {
    const req = createSignedRequest(
      { paths: ['/chennai'] },
      { omitSignature: true }
    );
    const res = await POST(req);
    expect(res.status).toBe(401);
    const json = await res.json();
    expect(json.error).toContain('Missing webhook signature');
  });

  it('rejects request with 401 when signature header is malformed', async () => {
    const req = createSignedRequest(
      { paths: ['/chennai'] },
      { malformedHeader: true }
    );
    const res = await POST(req);
    expect(res.status).toBe(401);
    const json = await res.json();
    expect(json.error).toContain('Malformed webhook signature');
  });

  it('rejects request with 400 when timestamp is outside the 60s freshness window', async () => {
    const staleTimestamp = Math.floor(Date.now() / 1000) - 120; // 2 minutes ago
    const req = createSignedRequest(
      { paths: ['/chennai'] },
      { timestamp: staleTimestamp }
    );
    const res = await POST(req);
    expect(res.status).toBe(400);
    const json = await res.json();
    expect(json.error).toContain('outside acceptable 60s window');
  });

  it('rejects request with 401 when HMAC signature is invalid or tampered', async () => {
    const req = createSignedRequest(
      { paths: ['/chennai'] },
      { secret: 'wrong_secret_key' }
    );
    const res = await POST(req);
    expect(res.status).toBe(401);
    const json = await res.json();
    expect(json.error).toContain('Invalid HMAC signature');
  });

  it('rejects request with 400 when payload is malformed or paths array is missing', async () => {
    const req = createSignedRequest({ wrong_field: true });
    const res = await POST(req);
    expect(res.status).toBe(400);
    const json = await res.json();
    expect(json.error).toContain(
      'Payload must include a non-empty array of paths'
    );
  });

  it('successfully validates signature, purges ISR cache for valid paths, and returns 200', async () => {
    const paths = ['/chennai/business/apex-dental-studio', '/chennai'];
    const req = createSignedRequest({ paths });
    const res = await POST(req);

    expect(res.status).toBe(200);
    const json = await res.json();
    expect(json.revalidated).toBe(true);
    expect(json.paths).toEqual(paths);

    expect(revalidatePath).toHaveBeenCalledWith(
      '/chennai/business/apex-dental-studio'
    );
    expect(revalidatePath).toHaveBeenCalledWith('/chennai');
  });
});
