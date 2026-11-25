import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  extractBffTrustedHost,
  forwardLaravelJson,
  isValidPublicUlid,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';
import type { OwnerOrganizationBranchDto } from '@/shared/api/directory-types';

export async function GET(
  request: Request,
  context: { params: { orgId: string } }
): Promise<NextResponse> {
  const trustedHost = extractBffTrustedHost(request);
  if (!trustedHost) {
    return bffError('INVALID_HOST', 'Invalid request host.', 400);
  }

  const auth = await requireActiveBffSession();
  if (!auth.ok) return auth.response;

  const orgId = context.params.orgId;
  if (!isValidPublicUlid(orgId)) {
    return bffError('INVALID_IDENTIFIER', 'Invalid organization identifier.', 400);
  }

  try {
    const result = await callLaravelApi<OwnerOrganizationBranchDto[]>(
      `/api/v1/owner/organizations/${encodeURIComponent(orgId)}/branches`,
      {
        method: 'GET',
        bearerToken: auth.record.accessToken,
        requestId: request.headers.get('x-request-id'),
        requestHost: trustedHost,
      }
    );

    return forwardLaravelJson(result, auth.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to load organization branches.', 503);
  }
}
