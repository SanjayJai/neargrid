import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import { bffError, enforceMutationSecurity, forwardLaravelJson, isValidPublicUlid, parseJsonBody } from '@/lib/bff/route-helpers';
import { readCurrentServerSession } from '@/lib/bff/session-cookie';

interface RouteContext {
  params: {
    businessId: string;
  };
}

export async function GET(request: Request, context: RouteContext): Promise<NextResponse> {
  const { businessId } = context.params;
  if (!isValidPublicUlid(businessId)) {
    return bffError('INVALID_BUSINESS_ID', 'Invalid business identifier.', 400);
  }

  const url = new URL(request.url);
  const search = url.searchParams.toString();
  const path = `/api/v1/businesses/${encodeURIComponent(businessId)}/reviews${search ? `?${search}` : ''}`;

  try {
    const result = await callLaravelApi(path, {
      method: 'GET',
      requestId: request.headers.get('x-request-id'),
    });

    return forwardLaravelJson(result);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Reviews service unavailable.', 503);
  }
}

export async function POST(request: Request, context: RouteContext): Promise<NextResponse> {
  const securityError = enforceMutationSecurity(request);
  if (securityError) return securityError;

  const { businessId } = context.params;
  if (!isValidPublicUlid(businessId)) {
    return bffError('INVALID_BUSINESS_ID', 'Invalid business identifier.', 400);
  }

  const parsed = await parseJsonBody(request);
  if (!parsed.ok) return parsed.response;

  const session = await readCurrentServerSession();
  const bearerToken = session?.record.accessToken;
  const opaqueId = session?.opaqueSessionId;

  try {
    const result = await callLaravelApi(
      `/api/v1/businesses/${encodeURIComponent(businessId)}/reviews`,
      {
        method: 'POST',
        body: parsed.data,
        bearerToken,
        requestId: request.headers.get('x-request-id'),
      }
    );

    return forwardLaravelJson(result, opaqueId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to submit review at this time.', 503);
  }
}
