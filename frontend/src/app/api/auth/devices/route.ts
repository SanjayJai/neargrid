import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  enforceMutationSecurity,
  forwardLaravelJson,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';
import { UserDeviceDto } from '@/lib/bff/types';

export const dynamic = 'force-dynamic';

export async function GET(request: Request): Promise<NextResponse> {
  const session = await requireActiveBffSession();
  if (!session.ok) {
    return session.response;
  }

  try {
    const result = await callLaravelApi<UserDeviceDto[]>('/api/v1/auth/devices', {
      method: 'GET',
      bearerToken: session.record.accessToken,
      requestId: request.headers.get('x-request-id'),
    });

    return await forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNAVAILABLE', 'Authentication service is currently unreachable.', 503);
  }
}

export async function POST(request: Request): Promise<NextResponse> {
  const securityError = enforceMutationSecurity(request);
  if (securityError) {
    return securityError;
  }

  const session = await requireActiveBffSession();
  if (!session.ok) {
    return session.response;
  }

  let body: Record<string, unknown>;
  try {
    body = (await request.json()) as Record<string, unknown>;
  } catch {
    return bffError('BAD_REQUEST', 'Invalid JSON request body.', 400);
  }

  try {
    const result = await callLaravelApi<UserDeviceDto>('/api/v1/auth/devices', {
      method: 'POST',
      bearerToken: session.record.accessToken,
      body,
      requestId: request.headers.get('x-request-id'),
    });

    return await forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNAVAILABLE', 'Authentication service is currently unreachable.', 503);
  }
}
