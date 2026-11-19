import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import { bffError, enforceMutationSecurity, forwardLaravelJson, isValidPublicUlid, parseJsonBody } from '@/lib/bff/route-helpers';
import { readCurrentServerSession } from '@/lib/bff/session-cookie';

export async function POST(
  request: Request,
  context: { params: { businessId: string } }
): Promise<NextResponse> {
  const securityError = enforceMutationSecurity(request);
  if (securityError) return securityError;

  const { businessId } = context.params;
  if (!isValidPublicUlid(businessId)) {
    return bffError('INVALID_BUSINESS_ID', 'Invalid business identifier.', 400);
  }

  const parsed = await parseJsonBody(request);
  const channel =
    parsed.ok && parsed.data && typeof (parsed.data as Record<string, unknown>).channel === 'string'
      ? (parsed.data as Record<string, unknown>).channel
      : 'phone';

  const session = await readCurrentServerSession();
  const bearerToken = session?.record.accessToken;
  const opaqueId = session?.opaqueSessionId;

  try {
    const result = await callLaravelApi(`/api/v1/businesses/${businessId}/contact-reveal`, {
      method: 'POST',
      body: { channel },
      bearerToken,
      requestId: request.headers.get('x-request-id'),
    });

    return forwardLaravelJson(result, opaqueId);
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to record contact reveal.', 503);
  }
}
