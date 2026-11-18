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
  context: { params: { publicId: string } }
): Promise<NextResponse> {
  const securityError = enforceMutationSecurity(request);
  if (securityError) {
    return securityError;
  }

  const session = await requireActiveBffSession();
  if (!session.ok) {
    return session.response;
  }

  const publicId = encodeURIComponent(context.params.publicId);

  try {
    const result = await callLaravelApi<{ revoked_tokens_count: number }>(
      `/api/v1/auth/devices/${publicId}`,
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
