import { NextResponse } from 'next/server';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import { requireActiveBffSession } from '@/lib/bff/route-helpers';

export const dynamic = 'force-dynamic';

export async function GET(request: Request): Promise<NextResponse> {
  const url = new URL(request.url);
  const qs = url.searchParams.toString();
  const host = request.headers.get('host');

  let bearerToken: string | undefined;
  try {
    const session = await requireActiveBffSession();
    if (session.ok) {
      bearerToken = session.record.accessToken;
    }
  } catch {
    // Guest fallback
  }

  const res = await callLaravelApi(`/api/v1/public/ads/deliver?${qs}`, {
    method: 'GET',
    bearerToken,
    requestHost: host,
    timeoutMs: 4000,
  });

  if (!res.ok) {
    return NextResponse.json(
      { status: 'success', data: { placements: {} } },
      { status: 200, headers: { 'Cache-Control': 'private, no-store' } }
    );
  }

  return NextResponse.json(res.payload, {
    status: 200,
    headers: { 'Cache-Control': 'private, no-store' },
  });
}
