import { callLaravelApi, callLaravelMultipartApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  enforceMutationSecurity,
  forwardLaravelJson,
  isValidPublicUlid,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';
import type { KycDocumentDto } from '@/shared/api/directory-types';

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
    const result = await callLaravelApi<KycDocumentDto[]>(
      `/api/v1/owner/businesses/${encodeURIComponent(businessId)}/kyc-documents`,
      {
        method: 'GET',
        bearerToken: session.record.accessToken,
        requestId: request.headers.get('x-request-id'),
      }
    );

    return forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to load KYC documents.', 503);
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
    return bffError('INVALID_MULTIPART', 'Expected multipart/form-data KYC upload payload.', 400);
  }

  const file = incoming.get('file');
  const documentType = incoming.get('document_type');

  if (!(file instanceof Blob) || typeof documentType !== 'string' || documentType.trim() === '') {
    return bffError('VALIDATION_FAILED', 'A valid file and document_type are required.', 422);
  }

  const outgoing = new FormData();
  outgoing.append('file', file, file instanceof File ? file.name : 'kyc-document');
  outgoing.append('document_type', documentType.trim());

  const documentNumber = incoming.get('document_number');
  if (typeof documentNumber === 'string' && documentNumber.trim() !== '') {
    outgoing.append('document_number', documentNumber.trim());
  }

  try {
    const result = await callLaravelMultipartApi<KycDocumentDto>(
      `/api/v1/owner/businesses/${encodeURIComponent(businessId)}/kyc-documents`,
      outgoing,
      {
        bearerToken: session.record.accessToken,
        requestId: request.headers.get('x-request-id'),
      }
    );

    return forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to upload KYC document.', 503);
  }
}
