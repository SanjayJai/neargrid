import { cookies } from 'next/headers';
import { NextResponse } from 'next/server';
import { CSRF_COOKIE_NAME } from './csrf';
import { callLaravelApi } from './laravel-client';
import { BffSessionRecord, getBffSessionStore } from './session-store';
import { UserDto } from './types';

export const SESSION_COOKIE_NAME = 'lbd_sid';

export function attachOpaqueSessionCookie(
  response: NextResponse,
  opaqueSessionId: string,
  maxAgeSeconds: number
): void {
  response.cookies.set(SESSION_COOKIE_NAME, opaqueSessionId, {
    httpOnly: true,
    secure: process.env.NODE_ENV === 'production',
    sameSite: 'lax',
    path: '/',
    maxAge: Math.max(60, maxAgeSeconds),
  });
}

export function clearOpaqueSessionCookie(response: NextResponse): void {
  response.cookies.set(SESSION_COOKIE_NAME, '', {
    httpOnly: true,
    secure: process.env.NODE_ENV === 'production',
    sameSite: 'lax',
    path: '/',
    maxAge: 0,
  });
}

export function attachCsrfCookie(response: NextResponse, token: string): void {
  response.cookies.set(CSRF_COOKIE_NAME, token, {
    httpOnly: false,
    secure: process.env.NODE_ENV === 'production',
    sameSite: 'lax',
    path: '/',
    maxAge: 60 * 60 * 24,
  });
}

export async function readCurrentServerSession(): Promise<{
  opaqueSessionId: string;
  record: BffSessionRecord;
} | null> {
  const cookieStore = cookies();
  const opaqueSessionId = cookieStore.get(SESSION_COOKIE_NAME)?.value;

  if (!opaqueSessionId) {
    return null;
  }

  try {
    const record = await getBffSessionStore().get(opaqueSessionId);
    if (!record) {
      return null;
    }

    return { opaqueSessionId, record };
  } catch {
    return null;
  }
}

/**
 * Safely resolves the authenticated user on the server for RSC pages.
 * NEVER returns or exposes the Sanctum Bearer token.
 */
export async function getServerAuthenticatedUser(): Promise<UserDto | null> {
  const session = await readCurrentServerSession();
  if (!session) {
    return null;
  }

  try {
    const res = await callLaravelApi<UserDto>('/api/v1/auth/me', {
      method: 'GET',
      bearerToken: session.record.accessToken,
    });

    if (res.status === 401) {
      await getBffSessionStore().delete(session.opaqueSessionId);
      return null;
    }

    if (res.ok && res.payload?.status === 'success') {
      return res.payload.data;
    }

    return null;
  } catch {
    return null;
  }
}
