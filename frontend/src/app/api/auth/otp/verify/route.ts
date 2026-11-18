import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import { bffError, enforceMutationSecurity, forwardLaravelJson } from '@/lib/bff/route-helpers';
import { attachOpaqueSessionCookie } from '@/lib/bff/session-cookie';
import { generateOpaqueSessionId, getBffSessionStore } from '@/lib/bff/session-store';
import { BffAuthClientData, LaravelVerifyOtpServerData } from '@/lib/bff/types';

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
    const result = await callLaravelApi<LaravelVerifyOtpServerData>('/api/v1/auth/otp/verify', {
      method: 'POST',
      body: {
        challenge_id: body.challenge_id,
        phone_e164: body.phone_e164,
        purpose: body.purpose ?? 'login',
        otp: body.otp,
        ...(body.name ? { name: body.name } : {}),
        device_name: typeof body.device_name === 'string' && body.device_name.trim() !== ''
          ? body.device_name.trim()
          : 'Next.js Web Browser',
      },
      requestId: request.headers.get('x-request-id'),
    });

    if (!result.ok || result.payload?.status !== 'success') {
      return await forwardLaravelJson(result);
    }

    const serverData = result.payload.data;
    const opaqueSessionId = generateOpaqueSessionId();
    const defaultTtl = Number(process.env.BFF_SESSION_TTL_SECONDS ?? 2592000);
    const remainingSeconds = Math.max(
      60,
      Math.floor((Date.parse(serverData.expires_at) - Date.now()) / 1000) || defaultTtl
    );

    // Store Sanctum Bearer token strictly server-side (Redis/encrypted store).
    await getBffSessionStore().set(
      opaqueSessionId,
      {
        accessToken: serverData.access_token,
        sessionId: serverData.session_id,
        userPublicId: serverData.user.public_id,
        expiresAt: serverData.expires_at,
        createdAt: new Date().toISOString(),
      },
      remainingSeconds
    );

    // Strip `access_token` completely before responding to browser JavaScript.
    const safeClientPayload: BffAuthClientData = {
      authenticated: true,
      session_id: serverData.session_id,
      expires_at: serverData.expires_at,
      user: serverData.user,
      ...(serverData.device ? { device: serverData.device } : {}),
    };

    const response = NextResponse.json(
      {
        status: 'success',
        data: safeClientPayload,
        meta: result.payload.meta,
      },
      { status: 200 }
    );

    attachOpaqueSessionCookie(response, opaqueSessionId, remainingSeconds);
    response.headers.set('Cache-Control', 'private, no-store');

    return response;
  } catch {
    return bffError('UPSTREAM_UNAVAILABLE', 'Authentication service is currently unreachable.', 503);
  }
}
