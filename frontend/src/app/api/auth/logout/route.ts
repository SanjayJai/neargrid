import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import { enforceMutationSecurity } from '@/lib/bff/route-helpers';
import { clearOpaqueSessionCookie, readCurrentServerSession } from '@/lib/bff/session-cookie';
import { getBffSessionStore } from '@/lib/bff/session-store';

export async function POST(request: Request): Promise<NextResponse> {
  const securityError = enforceMutationSecurity(request);
  if (securityError) {
    return securityError;
  }

  const session = await readCurrentServerSession();

  if (session) {
    try {
      await callLaravelApi('/api/v1/auth/logout', {
        method: 'POST',
        bearerToken: session.record.accessToken,
        requestId: request.headers.get('x-request-id'),
      });
    } catch {
      // Proceed to destroy server-side BFF session even if upstream is unreachable.
    }

    await getBffSessionStore().delete(session.opaqueSessionId);
  }

  const response = NextResponse.json({
    status: 'success',
    data: { logged_out: true },
  });
  clearOpaqueSessionCookie(response);
  response.headers.set('Cache-Control', 'private, no-store');

  return response;
}
