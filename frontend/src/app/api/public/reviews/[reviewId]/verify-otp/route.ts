import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import { bffError, enforceMutationSecurity, forwardLaravelJson, isValidPublicUlid, parseJsonBody } from '@/lib/bff/route-helpers';

interface RouteContext {
  params: {
    reviewId: string;
  };
}

export async function POST(request: Request, context: RouteContext): Promise<NextResponse> {
  const securityError = enforceMutationSecurity(request);
  if (securityError) return securityError;

  const reviewId = context.params.reviewId;
  if (!isValidPublicUlid(reviewId)) {
    return bffError('INVALID_REVIEW_ID', 'Invalid review identifier.', 400);
  }

  const parsed = await parseJsonBody(request);
  if (!parsed.ok) return parsed.response;

  try {
    const result = await callLaravelApi(`/api/v1/reviews/${encodeURIComponent(reviewId)}/verify-otp`, {
      method: 'POST',
      body: parsed.data,
      requestId: request.headers.get('x-request-id'),
    });

    return forwardLaravelJson(result);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to verify review at this time.', 503);
  }
}
