import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  bffError,
  enforceMutationSecurity,
  forwardLaravelJson,
  isValidPublicUlid,
  parseJsonBody,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';

interface RouteContext {
  params: {
    businessId: string;
    reviewId: string;
  };
}

export async function POST(
  request: Request,
  context: RouteContext
): Promise<NextResponse> {
  const securityError = enforceMutationSecurity(request);
  if (securityError) return securityError;

  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const { businessId, reviewId } = context.params;
  if (!isValidPublicUlid(businessId) || !isValidPublicUlid(reviewId)) {
    return bffError('INVALID_IDENTIFIERS', 'Invalid business or review identifier.', 400);
  }

  const parsed = await parseJsonBody(request);
  if (!parsed.ok) return parsed.response;

  try {
    const result = await callLaravelApi(
      `/api/v1/owner/businesses/${encodeURIComponent(businessId)}/reviews/${encodeURIComponent(reviewId)}/reply`,
      {
        method: 'POST',
        body: parsed.data,
        bearerToken: session.record.accessToken,
        requestId: request.headers.get('x-request-id'),
      }
    );

    return forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to save review reply at this time.', 503);
  }
}

export async function DELETE(
  request: Request,
  context: RouteContext
): Promise<NextResponse> {
  const securityError = enforceMutationSecurity(request);
  if (securityError) return securityError;

  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const { businessId, reviewId } = context.params;
  if (!isValidPublicUlid(businessId) || !isValidPublicUlid(reviewId)) {
    return bffError('INVALID_IDENTIFIERS', 'Invalid business or review identifier.', 400);
  }

  try {
    const result = await callLaravelApi(
      `/api/v1/owner/businesses/${encodeURIComponent(businessId)}/reviews/${encodeURIComponent(reviewId)}/reply`,
      {
        method: 'DELETE',
        bearerToken: session.record.accessToken,
        requestId: request.headers.get('x-request-id'),
      }
    );

    return forwardLaravelJson(result, session.opaqueSessionId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to delete review reply at this time.', 503);
  }
}
