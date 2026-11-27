import { NextResponse } from 'next/server';
import {
  bffError,
  enforceMutationSecurity,
  requireActiveBffSession,
} from '@/lib/bff/route-helpers';

export const dynamic = 'force-dynamic';

export async function POST(request: Request): Promise<NextResponse> {
  const securityError = enforceMutationSecurity(request);
  if (securityError) return securityError;

  const session = await requireActiveBffSession();
  if (!session.ok) return session.response;

  const baseUrl = (process.env.LARAVEL_API_URL || 'http://127.0.0.1:8000').replace(/\/+$/, '');
  try {
    const formData = await request.formData();
    const upstreamRes = await fetch(`${baseUrl}/api/v1/admin/advertising/creatives/upload-image`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${session.record.accessToken}`,
        Accept: 'application/json',
      },
      body: formData,
      cache: 'no-store',
    });

    const payload = await upstreamRes.json().catch(() => null);
    const res = NextResponse.json(payload ?? { status: 'error' }, { status: upstreamRes.status });
    res.headers.set('Cache-Control', 'private, no-store');
    return res;
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to upload creative image.', 503);
  }
}
