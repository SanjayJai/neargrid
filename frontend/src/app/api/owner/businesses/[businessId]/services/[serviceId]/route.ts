import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  enforceMutationSecurity,
  forwardLaravelJson,
  isValidPublicUlid,
  parseJsonBody,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';
import type { BusinessServiceDto } from '@/shared/api/directory-types';

export async function PUT(
  request: Request,
  context: { params: { businessId: string; serviceId: string } }
) {
  const csrfError = enforceMutationSecurity(request);
  if (csrfError) return csrfError;

  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const { businessId, serviceId } = context.params;
  if (!isValidPublicUlid(businessId) || !isValidPublicUlid(serviceId)) {
    return bffError('INVALID_IDENTIFIER', 'Invalid business or service identifier.', 400);
  }

  const parsed = await parseJsonBody(request);
  if (!parsed.ok) return parsed.response;

  try {
    const result = await callLaravelApi<BusinessServiceDto>(
      `/api/v1/owner/businesses/${encodeURIComponent(businessId)}/services/${encodeURIComponent(serviceId)}`,
      {
        method: 'PUT',
        body: parsed.data,
        bearerToken: session.record.accessToken,
        requestId: request.headers.get('x-request-id'),
      }
    );

    return forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to update service.', 503);
  }
}

export async function DELETE(
  request: Request,
  context: { params: { businessId: string; serviceId: string } }
) {
  const csrfError = enforceMutationSecurity(request);
  if (csrfError) return csrfError;

  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const { businessId, serviceId } = context.params;
  if (!isValidPublicUlid(businessId) || !isValidPublicUlid(serviceId)) {
    return bffError('INVALID_IDENTIFIER', 'Invalid business or service identifier.', 400);
  }

  try {
    const result = await callLaravelApi<null>(
      `/api/v1/owner/businesses/${encodeURIComponent(businessId)}/services/${encodeURIComponent(serviceId)}`,
      {
        method: 'DELETE',
        bearerToken: session.record.accessToken,
        requestId: request.headers.get('x-request-id'),
      }
    );

    return forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to delete service.', 503);
  }
}
