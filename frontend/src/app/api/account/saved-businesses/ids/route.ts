import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import { bffError, forwardLaravelJson, requireActiveBffSession } from '@/lib/bff/route-helpers';

export async function GET(request: Request): Promise<NextResponse> {
  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  try {
    const result = await callLaravelApi('/api/v1/account/saved-businesses/ids', {
      method: 'GET',
      bearerToken: session.record.accessToken,
      requestId: request.headers.get('x-request-id'),
    });

    return await forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError(
      'UPSTREAM_UNREACHABLE',
      'Unable to retrieve saved business IDs at this time.',
      503
    );
  }
}
