import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  enforceMutationSecurity,
  forwardLaravelJson,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';

export async function DELETE(
  request: Request,
  context: { params: { sessionId: string } }
): Promise<NextResponse> {
  const securityError = enforceMutationSecurity(request);
  if (securityError) {
    return securityError;
  }

  const session = await requireActiveBffSession();
  if (!session.ok) {
    return session.response;
  }

  const sessionId = encodeURIComponent(context.params.sessionId);

  try {
    const result = await callLaravelApi<{ revoked: boolean }>(
      `/api/v1/auth/sessions/${sessionId}`,
      {
        method: 'DELETE',
        bearerToken: session.record.accessToken,
        requestId: request.headers.get('x-request-id'),
      }
    );

    return await forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNAVAILABLE', 'Authentication service is currently unreachable.', 503);
  }
}
