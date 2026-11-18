import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  enforceMutationSecurity,
  forwardLaravelJson,
  parseJsonBody,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';
import type { OwnerBusinessProfileDto } from '@/shared/api/directory-types';

export async function GET(request: Request) {
  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  try {
    const result = await callLaravelApi<OwnerBusinessProfileDto[]>('/api/v1/owner/businesses', {
      method: 'GET',
      bearerToken: session.record.accessToken,
      requestId: request.headers.get('x-request-id'),
    });

    return forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to load your businesses.', 503);
  }
}

export async function POST(request: Request) {
  const csrfError = enforceMutationSecurity(request);
  if (csrfError) return csrfError;

  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const parsed = await parseJsonBody(request);
  if (!parsed.ok) return parsed.response;

  try {
    const result = await callLaravelApi<OwnerBusinessProfileDto>('/api/v1/owner/businesses', {
      method: 'POST',
      body: parsed.data,
      bearerToken: session.record.accessToken,
      requestId: request.headers.get('x-request-id'),
    });

    return forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to create business.', 503);
  }
}
