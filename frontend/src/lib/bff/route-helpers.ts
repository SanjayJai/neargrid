import { NextResponse } from 'next/server';
import { validateOriginAndCsrf } from './csrf';
import { LaravelCallResult } from './laravel-client';
import { clearOpaqueSessionCookie, readCurrentServerSession } from './session-cookie';
import { BffSessionRecord, getBffSessionStore } from './session-store';

const PUBLIC_ULID_REGEX = /^[0-9A-HJKMNP-TV-Z]{26}$/i;
const SAFE_SLUG_REGEX = /^[a-z0-9-]+$/;
const UUID_REGEX = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export function isValidPublicUlid(value: string): boolean {
  return PUBLIC_ULID_REGEX.test(value);
}

export function isValidNotificationId(value: string): boolean {
  return typeof value === 'string' && value.length === 36 && UUID_REGEX.test(value);
}

export function isValidSlug(value: string): boolean {
  return value.length >= 1 && value.length <= 140 && SAFE_SLUG_REGEX.test(value);
}

export function bffError(
  code: string,
  message: string,
  status: number,
  details?: Record<string, unknown>
): NextResponse {
  return NextResponse.json(
    {
      status: 'error',
      error: {
        code,
        message,
        ...(details ? { details } : {}),
      },
    },
    { status }
  );
}

export function enforceMutationSecurity(request: Request): NextResponse | null {
  const check = validateOriginAndCsrf(request);
  if (!check.valid) {
    return bffError(
      check.code ?? 'FORBIDDEN_ORIGIN',
      check.message ?? 'Forbidden request.',
      403
    );
  }

  return null;
}

export async function requireActiveBffSession(): Promise<
  | { ok: true; opaqueSessionId: string; record: BffSessionRecord }
  | { ok: false; response: NextResponse }
> {
  const session = await readCurrentServerSession();
  if (!session) {
    const res = bffError('UNAUTHENTICATED', 'Authentication is required.', 401);
    clearOpaqueSessionCookie(res);
    return { ok: false, response: res };
  }

  return {
    ok: true,
    opaqueSessionId: session.opaqueSessionId,
    record: session.record,
  };
}

export async function parseJsonBody(request: Request): Promise<
  | { ok: true; data: unknown }
  | { ok: false; response: NextResponse }
> {
  try {
    const data: unknown = await request.json();
    return { ok: true, data };
  } catch {
    return {
      ok: false,
      response: bffError('INVALID_JSON', 'Request body must be valid JSON.', 400),
    };
  }
}

export async function forwardLaravelJson<T>(
  result: LaravelCallResult<T>,
  opaqueSessionId?: string,
  options?: { forwardCacheHeaders?: boolean }
): Promise<NextResponse> {
  if (result.status === 204) {
    return new NextResponse(null, { status: 204 });
  }

  const response = NextResponse.json(
    result.payload ?? {
      status: 'error',
      error: {
        code: 'UPSTREAM_ERROR',
        message: 'Unexpected response from API service.',
      },
    },
    { status: result.status }
  );

  if (result.retryAfter) {
    response.headers.set('Retry-After', result.retryAfter);
  }
  if (result.requestId) {
    response.headers.set('X-Request-ID', result.requestId);
  }
  if (options?.forwardCacheHeaders) {
    if (result.cacheControl) {
      response.headers.set('Cache-Control', result.cacheControl);
    }
    if (result.robotsTag) {
      response.headers.set('X-Robots-Tag', result.robotsTag);
    }
  } else {
    response.headers.set('Cache-Control', 'private, no-store');
  }

  // Automatically expire local BFF session when Laravel rejects the Bearer token.
  if (result.status === 401 && opaqueSessionId) {
    await getBffSessionStore().delete(opaqueSessionId);
    clearOpaqueSessionCookie(response);
  }

  return response;
}
