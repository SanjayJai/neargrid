import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  enforceMutationSecurity,
  forwardLaravelJson,
  isValidPublicUlid,
  parseJsonBody,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';
import type { BusinessMemberDto } from '@/shared/api/directory-types';

export async function PATCH(
  request: Request,
  context: { params: { businessId: string; memberId: string } }
) {
  const csrfError = enforceMutationSecurity(request);
  if (csrfError) return csrfError;

  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const { businessId, memberId } = context.params;
  if (!isValidPublicUlid(businessId) || !isValidPublicUlid(memberId)) {
    return bffError('INVALID_IDENTIFIER', 'Invalid business or member identifier.', 400);
  }

  const parsed = await parseJsonBody(request);
  if (!parsed.ok) return parsed.response;

  try {
    const result = await callLaravelApi<BusinessMemberDto>(
      `/api/v1/owner/businesses/${encodeURIComponent(businessId)}/members/${encodeURIComponent(memberId)}`,
      {
        method: 'PATCH',
        body: parsed.data,
        bearerToken: session.record.accessToken,
        requestId: request.headers.get('x-request-id'),
      }
    );

    return forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to update staff member.', 503);
  }
}

export async function DELETE(
  request: Request,
  context: { params: { businessId: string; memberId: string } }
) {
  const csrfError = enforceMutationSecurity(request);
  if (csrfError) return csrfError;

  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const { businessId, memberId } = context.params;
  if (!isValidPublicUlid(businessId) || !isValidPublicUlid(memberId)) {
    return bffError('INVALID_IDENTIFIER', 'Invalid business or member identifier.', 400);
  }

  try {
    const result = await callLaravelApi<null>(
      `/api/v1/owner/businesses/${encodeURIComponent(businessId)}/members/${encodeURIComponent(memberId)}`,
      {
        method: 'DELETE',
        bearerToken: session.record.accessToken,
        requestId: request.headers.get('x-request-id'),
      }
    );

    return forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to remove staff member.', 503);
  }
}
