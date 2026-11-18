import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import { bffError, enforceMutationSecurity, forwardLaravelJson } from '@/lib/bff/route-helpers';
import { OtpChallengeResponseDto } from '@/lib/bff/types';

export async function POST(request: Request): Promise<NextResponse> {
  const securityError = enforceMutationSecurity(request);
  if (securityError) {
    return securityError;
  }

  let body: Record<string, unknown>;
  try {
    body = (await request.json()) as Record<string, unknown>;
  } catch {
    return bffError('BAD_REQUEST', 'Invalid JSON request body.', 400);
  }

  try {
    const result = await callLaravelApi<OtpChallengeResponseDto>('/api/v1/auth/otp/resend', {
      method: 'POST',
      body: {
        challenge_id: body.challenge_id,
        phone_e164: body.phone_e164,
        purpose: body.purpose ?? 'login',
      },
      requestId: request.headers.get('x-request-id'),
    });

    return await forwardLaravelJson(result);
  } catch {
    return bffError('UPSTREAM_UNAVAILABLE', 'Authentication service is currently unreachable.', 503);
  }
}
