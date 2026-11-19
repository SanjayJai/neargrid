import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  enforceMutationSecurity,
  forwardLaravelJson,
  isValidPublicUlid,
  parseJsonBody,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';

export async function PATCH(
  request: Request,
  context: { params: { businessId: string; leadId: string } }
) {
  const csrfError = enforceMutationSecurity(request);
  if (csrfError) return csrfError;

  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const { businessId, leadId } = context.params;
  if (!isValidPublicUlid(businessId)) {
    return bffError('INVALID_BUSINESS_ID', 'Invalid business identifier.', 400);
  }
  if (!isValidPublicUlid(leadId)) {
    return bffError('INVALID_LEAD_ID', 'Invalid lead identifier.', 400);
  }

  const parsed = await parseJsonBody(request);
  if (!parsed.ok) return parsed.response;

  try {
    const result = await callLaravelApi(
      `/api/v1/owner/businesses/${encodeURIComponent(businessId)}/leads/${encodeURIComponent(leadId)}`,
      {
        method: 'PATCH',
        body: parsed.data,
        bearerToken: session.record.accessToken,
        requestId: request.headers.get('x-request-id'),
      }
    );

    return forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to update lead.', 503);
  }
}
