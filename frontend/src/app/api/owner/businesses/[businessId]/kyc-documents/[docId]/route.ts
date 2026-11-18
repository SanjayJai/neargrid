import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  enforceMutationSecurity,
  forwardLaravelJson,
  isValidPublicUlid,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';

export async function DELETE(
  request: Request,
  context: { params: { businessId: string; docId: string } }
) {
  const csrfError = enforceMutationSecurity(request);
  if (csrfError) return csrfError;

  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const { businessId, docId } = context.params;
  if (!isValidPublicUlid(businessId) || !isValidPublicUlid(docId)) {
    return bffError('INVALID_IDENTIFIER', 'Invalid business or KYC document identifier.', 400);
  }

  try {
    const result = await callLaravelApi<{ deleted: boolean }>(
      `/api/v1/owner/businesses/${encodeURIComponent(businessId)}/kyc-documents/${encodeURIComponent(docId)}`,
      {
        method: 'DELETE',
        bearerToken: session.record.accessToken,
        requestId: request.headers.get('x-request-id'),
      }
    );

    return forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to delete KYC document.', 503);
  }
}
