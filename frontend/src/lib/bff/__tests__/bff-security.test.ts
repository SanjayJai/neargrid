import fs from 'fs';
import path from 'path';
import { describe, expect, it } from 'vitest';
import {
  CSRF_COOKIE_NAME,
  CSRF_HEADER_NAME,
  generateCsrfToken,
  validateOriginAndCsrf,
} from '@/lib/bff/csrf';
import {
  decryptSessionRecord,
  encryptSessionRecord,
  generateOpaqueSessionId,
  MemoryBffSessionStore,
} from '@/lib/bff/session-store';

describe('BFF Session Store & Encryption Security', () => {
  const secret = 'test-secret-with-more-than-thirty-two-chars-123456';

  it('encrypts Sanctum tokens at rest with AES-256-GCM and rejects tampered payloads', () => {
    const record = {
      accessToken: '12|lbd_super_secret_sanctum_bearer_token',
      sessionId: '01JCG4K8N5P2Q7R9S1T3V5W7X9',
      userPublicId: '01JCG4K8N5P2Q7R9S1T3V5W7X8',
      expiresAt: new Date(Date.now() + 3600_000).toISOString(),
      createdAt: new Date().toISOString(),
    };

    const encrypted = encryptSessionRecord(record, secret);
    expect(encrypted).not.toContain('lbd_super_secret_sanctum_bearer_token');

    const decrypted = decryptSessionRecord(encrypted, secret);
    expect(decrypted).toEqual(record);

    // Tamper with auth tag -> must return null
    const parts = encrypted.split('.');
    parts[1] = `${parts[1].slice(0, -2)}AA`;
    expect(decryptSessionRecord(parts.join('.'), secret)).toBeNull();
  });

  it('rejects expired session records even if still present in store', () => {
    const expired = {
      accessToken: '12|lbd_expired_token',
      sessionId: '01JCG4K8N5P2Q7R9S1T3V5W7X9',
      userPublicId: '01JCG4K8N5P2Q7R9S1T3V5W7X8',
      expiresAt: new Date(Date.now() - 10_000).toISOString(),
      createdAt: new Date(Date.now() - 60_000).toISOString(),
    };

    const encrypted = encryptSessionRecord(expired, secret);
    expect(decryptSessionRecord(encrypted, secret)).toBeNull();
  });

  it('stores and deletes opaque server-side sessions cleanly', async () => {
    const store = new MemoryBffSessionStore();
    const sid = generateOpaqueSessionId();
    expect(sid.length).toBeGreaterThanOrEqual(40);

    const record = {
      accessToken: '15|lbd_bearer_token_value',
      sessionId: '01JCG4K8N5P2Q7R9S1T3V5W7X9',
      userPublicId: '01JCG4K8N5P2Q7R9S1T3V5W7X8',
      expiresAt: new Date(Date.now() + 60_000).toISOString(),
      createdAt: new Date().toISOString(),
    };

    await store.set(sid, record, 60);
    expect(await store.get(sid)).toEqual(record);

    await store.delete(sid);
    expect(await store.get(sid)).toBeNull();
  });
});

describe('BFF Origin & Double-Submit CSRF Guard', () => {
  it('blocks cross-origin mutations even with matching CSRF header', () => {
    const csrf = generateCsrfToken();
    const req = new Request('http://localhost:3000/api/auth/logout', {
      method: 'POST',
      headers: {
        host: 'localhost:3000',
        origin: 'https://evil.example.com',
        cookie: `${CSRF_COOKIE_NAME}=${csrf}`,
        [CSRF_HEADER_NAME]: csrf,
      },
    });

    const result = validateOriginAndCsrf(req);
    expect(result.valid).toBe(false);
    expect(result.code).toBe('FORBIDDEN_ORIGIN');
  });

  it('blocks same-origin mutations with missing or mismatched CSRF token', () => {
    const csrf = generateCsrfToken();
    const req = new Request('http://localhost:3000/api/auth/logout', {
      method: 'POST',
      headers: {
        host: 'localhost:3000',
        origin: 'http://localhost:3000',
        cookie: `${CSRF_COOKIE_NAME}=${csrf}`,
        [CSRF_HEADER_NAME]: 'wrong-token',
      },
    });

    const result = validateOriginAndCsrf(req);
    expect(result.valid).toBe(false);
    expect(result.code).toBe('INVALID_CSRF_TOKEN');
  });

  it('permits same-origin mutations with valid double-submit CSRF token', () => {
    const csrf = generateCsrfToken();
    const req = new Request('http://localhost:3000/api/auth/logout', {
      method: 'POST',
      headers: {
        host: 'localhost:3000',
        origin: 'http://localhost:3000',
        cookie: `${CSRF_COOKIE_NAME}=${csrf}`,
        [CSRF_HEADER_NAME]: csrf,
      },
    });

    expect(validateOriginAndCsrf(req)).toEqual({ valid: true });
  });
});

describe('Static Guardrail: No localStorage token storage in frontend source', () => {
  function collectSourceFiles(dir: string): string[] {
    const files: string[] = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const fullPath = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        if (entry.name === '__tests__') continue;
        files.push(...collectSourceFiles(fullPath));
      } else if (entry.name.endsWith('.ts') || entry.name.endsWith('.tsx')) {
        files.push(fullPath);
      }
    }
    return files;
  }

  it('contains zero localStorage calls across src/', () => {
    const srcDir = path.resolve(process.cwd(), 'src');
    const files = collectSourceFiles(srcDir);
    expect(files.length).toBeGreaterThan(10);

    for (const file of files) {
      const content = fs.readFileSync(file, 'utf8');
      expect(content, `Unexpected localStorage API usage in ${file}`).not.toMatch(
        /\blocalStorage\.(?:getItem|setItem|removeItem|clear|key)\b|\blocalStorage\s*\[/
      );
    }
  });

  it('never leaks access_token in BFF otp verify response payload', () => {
    const verifyRoute = fs.readFileSync(
      path.resolve(process.cwd(), 'src/app/api/auth/otp/verify/route.ts'),
      'utf8'
    );
    expect(verifyRoute).toContain('attachOpaqueSessionCookie');
    expect(verifyRoute).toContain('getBffSessionStore().set');
    expect(verifyRoute).toContain('safeClientPayload');
    // Ensure safeClientPayload never assigns access_token
    const payloadBlock = verifyRoute.slice(
      verifyRoute.indexOf('const safeClientPayload'),
      verifyRoute.indexOf('const response = NextResponse.json')
    );
    expect(payloadBlock).not.toContain('access_token');
    expect(payloadBlock).not.toContain('accessToken');
  });
});
