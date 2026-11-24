import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  enforceMutationSecurity,
  forwardLaravelJson,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';

export async function POST(request: Request): Promise<NextResponse> {
  const csrfError = enforceMutationSecurity(request);
  if (csrfError) return csrfError;

  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  try {
    const result = await callLaravelApi('/api/v1/account/notifications/mark-all-read', {
      method: 'POST',
      bearerToken: session.record.accessToken,
      requestId: request.headers.get('x-request-id'),
    });

    return await forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to mark notifications as read.', 503);
  }
}
