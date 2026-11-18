import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  enforceMutationSecurity,
  forwardLaravelJson,
  isValidPublicUlid,
  parseJsonBody,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';
import type { BusinessVerificationDto } from '@/shared/api/directory-types';

export async function GET(
  request: Request,
  context: { params: { businessId: string } }
) {
  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const { businessId } = context.params;
  if (!isValidPublicUlid(businessId)) {
    return bffError('INVALID_BUSINESS_ID', 'Invalid business identifier.', 400);
  }

  try {
    const result = await callLaravelApi<BusinessVerificationDto[]>(
      `/api/v1/owner/businesses/${encodeURIComponent(businessId)}/verifications`,
      {
        method: 'GET',
        bearerToken: session.record.accessToken,
        requestId: request.headers.get('x-request-id'),
      }
    );

    return forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to load verification history.', 503);
  }
}

export async function POST(
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

  const parsed = await parseJsonBody(request);
  if (!parsed.ok) return parsed.response;

  try {
    const result = await callLaravelApi<BusinessVerificationDto>(
      `/api/v1/owner/businesses/${encodeURIComponent(businessId)}/verifications`,
      {
        method: 'POST',
        body: parsed.data,
        bearerToken: session.record.accessToken,
        requestId: request.headers.get('x-request-id'),
      }
    );

    return forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to submit verification record.', 503);
  }
}
