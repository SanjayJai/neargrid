import { NextResponse } from 'next/server';
import { validateOriginAndCsrf } from './csrf';
import { LaravelCallResult } from './laravel-client';
import { clearOpaqueSessionCookie, readCurrentServerSession } from './session-cookie';
import { BffSessionRecord, getBffSessionStore } from './session-store';

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

export async function forwardLaravelJson<T>(
  result: LaravelCallResult<T>,
  opaqueSessionId?: string
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

  // Automatically expire local BFF session when Laravel rejects the Bearer token.
  if (result.status === 401 && opaqueSessionId) {
    await getBffSessionStore().delete(opaqueSessionId);
    clearOpaqueSessionCookie(response);
  }

  return response;
}
