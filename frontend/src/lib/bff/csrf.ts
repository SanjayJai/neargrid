import crypto from 'crypto';

export const CSRF_COOKIE_NAME = 'lbd_csrf';
export const CSRF_HEADER_NAME = 'x-csrf-token';

export interface CsrfCheckResult {
  valid: boolean;
  code?: 'FORBIDDEN_ORIGIN' | 'INVALID_CSRF_TOKEN';
  message?: string;
}

export function generateCsrfToken(): string {
  return crypto.randomBytes(32).toString('hex');
}

function parseOrigin(urlValue: string | null): string | null {
  if (!urlValue) {
    return null;
  }

  try {
    return new URL(urlValue).origin.toLowerCase();
  } catch {
    return null;
  }
}

function allowedOriginsForRequest(request: Request): Set<string> {
  const allowed = new Set<string>();
  const siteUrlOrigin = parseOrigin(process.env.NEXT_PUBLIC_SITE_URL ?? null);
  if (siteUrlOrigin) {
    allowed.add(siteUrlOrigin);
  }

  const host = request.headers.get('x-forwarded-host') ?? request.headers.get('host');
  const proto = request.headers.get('x-forwarded-proto') ?? (process.env.NODE_ENV === 'production' ? 'https' : 'http');
  if (host) {
    const derived = parseOrigin(`${proto}://${host}`);
    if (derived) {
      allowed.add(derived);
    }
  }

  return allowed;
}

export function extractCookieValue(cookieHeader: string | null, name: string): string | null {
  if (!cookieHeader) {
    return null;
  }

  for (const part of cookieHeader.split(';')) {
    const [rawKey, ...rest] = part.trim().split('=');
    if (rawKey === name) {
      return decodeURIComponent(rest.join('='));
    }
  }

  return null;
}

export function constantTimeCompare(a: string, b: string): boolean {
  const bufA = Buffer.from(a, 'utf8');
  const bufB = Buffer.from(b, 'utf8');
  if (bufA.length !== bufB.length || bufA.length === 0) {
    return false;
  }

  return crypto.timingSafeEqual(bufA, bufB);
}

/**
 * Enforces strict Origin/Referer validation AND double-submit CSRF verification
 * on all state-changing Next.js BFF route handlers.
 */
export function validateOriginAndCsrf(request: Request): CsrfCheckResult {
  const method = request.method.toUpperCase();
  if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS') {
    return { valid: true };
  }

  const allowedOrigins = allowedOriginsForRequest(request);
  const requestOrigin = parseOrigin(request.headers.get('origin'))
    ?? parseOrigin(request.headers.get('referer'));

  if (!requestOrigin || !allowedOrigins.has(requestOrigin)) {
    return {
      valid: false,
      code: 'FORBIDDEN_ORIGIN',
      message: 'Cross-origin mutation request rejected.',
    };
  }

  const cookieToken = extractCookieValue(request.headers.get('cookie'), CSRF_COOKIE_NAME);
  const headerToken = request.headers.get(CSRF_HEADER_NAME);

  if (!cookieToken || !headerToken || !constantTimeCompare(cookieToken, headerToken)) {
    return {
      valid: false,
      code: 'INVALID_CSRF_TOKEN',
      message: 'Missing or invalid CSRF token.',
    };
  }

  return { valid: true };
}
