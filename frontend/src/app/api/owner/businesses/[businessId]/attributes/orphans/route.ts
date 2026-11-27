import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  enforceMutationSecurity,
  forwardLaravelJson,
  isValidPublicUlid,
  parseJsonBody,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';
import type { OwnerBusinessAttributesResponseDto } from '@/shared/api/directory-types';

export async function DELETE(
  request: Request,
  context: { params: { businessId: string } }
) {
  const csrfError = enforceMutationSecurity(request);
  if (csrfError) return csrfError;

  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const { businessId } = context.params;
  if (!isValidPublicUlid(businessId)) {
    return bffError('INVALID_BUSINESS_ID', 'Invalid business identifier.', 400);
  }

  let body: unknown = undefined;
  const contentLength = request.headers.get('content-length');
  if (contentLength && Number(contentLength) > 0) {
    const parsed = await parseJsonBody(request);
    if (!parsed.ok) return parsed.response;
    body = parsed.data;
  }

  try {
    const result = await callLaravelApi<{
      deleted_count: number;
      attributes: OwnerBusinessAttributesResponseDto;
    }>(
      `/api/v1/owner/businesses/${encodeURIComponent(businessId)}/attributes/orphans`,
      {
        method: 'DELETE',
        body,
        bearerToken: session.record.accessToken,
        requestId: request.headers.get('x-request-id'),
      }
    );

    return forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to clear orphaned attributes.', 503);
  }
}
