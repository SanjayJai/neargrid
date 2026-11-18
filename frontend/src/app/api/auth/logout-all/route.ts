import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  enforceMutationSecurity,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';
import { clearOpaqueSessionCookie } from '@/lib/bff/session-cookie';
import { getBffSessionStore } from '@/lib/bff/session-store';

export async function POST(request: Request): Promise<NextResponse> {
  const securityError = enforceMutationSecurity(request);
  if (securityError) {
    return securityError;
  }

  const session = await requireActiveBffSession();
  if (!session.ok) {
    return session.response;
  }

  try {
    await callLaravelApi('/api/v1/auth/logout-all', {
      method: 'POST',
      bearerToken: session.record.accessToken,
      requestId: request.headers.get('x-request-id'),
    });
  } catch {
    return bffError('UPSTREAM_UNAVAILABLE', 'Authentication service is currently unreachable.', 503);
  }

  await getBffSessionStore().delete(session.opaqueSessionId);

  const response = NextResponse.json({
    status: 'success',
    data: { logged_out_all: true },
  });
  clearOpaqueSessionCookie(response);
  response.headers.set('Cache-Control', 'private, no-store');

  return response;
}
