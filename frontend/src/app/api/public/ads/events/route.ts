import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import { bffError, enforceMutationSecurity } from '@/lib/bff/route-helpers';

export const dynamic = 'force-dynamic';

export async function POST(request: Request): Promise<NextResponse> {
  const securityError = enforceMutationSecurity(request);
  if (securityError) return securityError;

  let body: { event_type?: string; token?: string; session_id?: string };
  try {
    body = await request.json();
  } catch {
    return bffError('INVALID_JSON', 'Invalid JSON payload.', 400);
  }

  const eventType = body.event_type === 'click' ? 'click' : body.event_type === 'impression' ? 'impression' : null;
  if (!eventType || typeof body.token !== 'string' || !body.token) {
    return bffError('INVALID_EVENT', 'Valid event_type and token are required.', 422);
  }

  const res = await callLaravelApi(`/api/v1/public/ads/${eventType}`, {
    method: 'POST',
    body: {
      token: body.token,
      session_id: typeof body.session_id === 'string' ? body.session_id : undefined,
    },
    requestHost: request.headers.get('host'),
    timeoutMs: 3000,
  });

  return NextResponse.json(res.payload, {
    status: res.status,
    headers: { 'Cache-Control': 'private, no-store' },
  });
}
