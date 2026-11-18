import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  forwardLaravelJson,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';
import { SessionTokenDto } from '@/lib/bff/types';

export const dynamic = 'force-dynamic';

export async function GET(request: Request): Promise<NextResponse> {
  const session = await requireActiveBffSession();
  if (!session.ok) {
    return session.response;
  }

  try {
    const result = await callLaravelApi<SessionTokenDto[]>('/api/v1/auth/sessions', {
      method: 'GET',
      bearerToken: session.record.accessToken,
      requestId: request.headers.get('x-request-id'),
    });

    return await forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNAVAILABLE', 'Authentication service is currently unreachable.', 503);
  }
}
