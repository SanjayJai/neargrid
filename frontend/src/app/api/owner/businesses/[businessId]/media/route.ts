import { callLaravelApi, callLaravelMultipartApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  enforceMutationSecurity,
  forwardLaravelJson,
  isValidPublicUlid,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';
import type { OwnerBusinessMediaDto } from '@/shared/api/directory-types';

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
    const result = await callLaravelApi<OwnerBusinessMediaDto[]>(
      `/api/v1/owner/businesses/${encodeURIComponent(businessId)}/media`,
      {
        method: 'GET',
        bearerToken: session.record.accessToken,
        requestId: request.headers.get('x-request-id'),
      }
    );

    return forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to load business media.', 503);
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

  let incoming: FormData;
  try {
    incoming = await request.formData();
  } catch {
    return bffError('INVALID_MULTIPART', 'Expected multipart/form-data upload payload.', 400);
  }

  const file = incoming.get('file');
  const mediaType = incoming.get('media_type');

  if (!(file instanceof Blob) || typeof mediaType !== 'string' || mediaType.trim() === '') {
    return bffError('VALIDATION_FAILED', 'A valid file and media_type are required.', 422);
  }

  // Forward strictly allowlisted fields only.
  const outgoing = new FormData();
  outgoing.append('file', file, file instanceof File ? file.name : 'upload');
  outgoing.append('media_type', mediaType.trim());

  const altText = incoming.get('alt_text');
  if (typeof altText === 'string' && altText.trim() !== '') {
    outgoing.append('alt_text', altText.trim());
  }

  const sortOrder = incoming.get('sort_order');
  if (typeof sortOrder === 'string' && sortOrder.trim() !== '') {
    outgoing.append('sort_order', sortOrder.trim());
  }

  try {
    const result = await callLaravelMultipartApi<OwnerBusinessMediaDto>(
      `/api/v1/owner/businesses/${encodeURIComponent(businessId)}/media`,
      outgoing,
      {
        bearerToken: session.record.accessToken,
        requestId: request.headers.get('x-request-id'),
      }
    );

    return forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to upload business media.', 503);
  }
}
