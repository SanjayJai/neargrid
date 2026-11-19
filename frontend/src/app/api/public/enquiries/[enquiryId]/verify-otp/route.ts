import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import { bffError, enforceMutationSecurity, isValidPublicUlid, parseJsonBody } from '@/lib/bff/route-helpers';
import { attachOpaqueSessionCookie } from '@/lib/bff/session-cookie';
import { generateOpaqueSessionId, getBffSessionStore } from '@/lib/bff/session-store';

interface LaravelVerifyEnquiryServerData {
  enquiry: Record<string, unknown>;
  auth: {
    token_type: string;
    access_token: string;
    session_id: string;
    expires_at: string;
    user: {
      public_id: string;
      name: string | null;
      phone_e164: string;
    };
  };
}

export async function POST(
  request: Request,
  context: { params: { enquiryId: string } }
): Promise<NextResponse> {
  const securityError = enforceMutationSecurity(request);
  if (securityError) return securityError;

  const { enquiryId } = context.params;
  if (!isValidPublicUlid(enquiryId)) {
    return bffError('INVALID_ENQUIRY_ID', 'Invalid enquiry identifier.', 400);
  }

  const parsed = await parseJsonBody(request);
  if (!parsed.ok) return parsed.response;

  try {
    const result = await callLaravelApi<LaravelVerifyEnquiryServerData>(
      `/api/v1/enquiries/${enquiryId}/verify-otp`,
      {
        method: 'POST',
        body: parsed.data,
        requestId: request.headers.get('x-request-id'),
      }
    );

    if (!result.ok || result.payload?.status !== 'success') {
      const status = result.status || 400;
      return NextResponse.json(
        result.payload ?? {
          status: 'error',
          error: { code: 'OTP_VERIFICATION_FAILED', message: 'OTP verification failed.' },
        },
        { status }
      );
    }

    const serverData = result.payload.data;
    const opaqueSessionId = generateOpaqueSessionId();
    const defaultTtl = Number(process.env.BFF_SESSION_TTL_SECONDS ?? 2592000);
    const remainingSeconds = Math.max(
      60,
      Math.floor((Date.parse(serverData.auth.expires_at) - Date.now()) / 1000) || defaultTtl
    );

    // Keep Sanctum token strictly server-side
    await getBffSessionStore().set(
      opaqueSessionId,
      {
        accessToken: serverData.auth.access_token,
        sessionId: serverData.auth.session_id,
        userPublicId: serverData.auth.user.public_id,
        expiresAt: serverData.auth.expires_at,
        createdAt: new Date().toISOString(),
      },
      remainingSeconds
    );

    // Strip access_token from client-facing response payload
    const clientResponse = NextResponse.json(
      {
        status: 'success',
        data: {
          enquiry: serverData.enquiry,
          user: serverData.auth.user,
        },
        meta: result.payload.meta,
      },
      { status: 200 }
    );

    attachOpaqueSessionCookie(clientResponse, opaqueSessionId, remainingSeconds);

    return clientResponse;
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to verify OTP at this time.', 503);
  }
}
