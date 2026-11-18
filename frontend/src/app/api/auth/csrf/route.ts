import { NextResponse } from 'next/server';
import { generateCsrfToken } from '@/lib/bff/csrf';
import { attachCsrfCookie } from '@/lib/bff/session-cookie';

export const dynamic = 'force-dynamic';

export async function GET(): Promise<NextResponse> {
  const token = generateCsrfToken();
  const response = NextResponse.json({
    status: 'success',
    data: { csrf_token: token },
  });

  attachCsrfCookie(response, token);
  response.headers.set('Cache-Control', 'private, no-store');

  return response;
}
