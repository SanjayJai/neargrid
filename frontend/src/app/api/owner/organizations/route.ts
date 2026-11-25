import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  extractBffTrustedHost,
  forwardLaravelJson,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';
import type { OwnerOrganizationDto } from '@/shared/api/directory-types';

export async function GET(request: Request): Promise<NextResponse> {
  const trustedHost = extractBffTrustedHost(request);
  if (!trustedHost) {
    return bffError('INVALID_HOST', 'Invalid request host.', 400);
  }

  const auth = await requireActiveBffSession();
  if (!auth.ok) return auth.response;

  try {
    const result = await callLaravelApi<OwnerOrganizationDto[]>('/api/v1/owner/organizations', {
      method: 'GET',
      bearerToken: auth.record.accessToken,
      requestId: request.headers.get('x-request-id'),
      requestHost: trustedHost,
    });

    return forwardLaravelJson(result, auth.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to load organizations.', 503);
  }
}
