import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  getServerAuthenticatedUser,
  readCurrentServerSession,
} from '@/lib/bff/session-cookie';
import { getBffSessionStore } from '@/lib/bff/session-store';
import type { UserDto } from '@/lib/bff/types';
import type { OwnerBusinessProfileDto } from '@/shared/api/directory-types';

export type BusinessDashboardAccessResult =
  | { status: 'unauthenticated'; redirectTo: string }
  | { status: 'forbidden'; user: UserDto }
  | { status: 'allowed'; user: UserDto; businesses: OwnerBusinessProfileDto[] };

export function buildDashboardLoginRedirect(requestedPath?: string | null): string {
  const safePath =
    requestedPath &&
    (requestedPath.startsWith('/dashboard') || requestedPath.startsWith('/business/dashboard'))
      ? requestedPath
      : '/business/dashboard';
  return `/login?next=${encodeURIComponent(safePath)}`;
}

/**
 * Server-side authorization guard for all /dashboard routes.
 * Requires both an active BFF session and at least one owned or managed BusinessMember listing.
 */
export async function authorizeBusinessDashboardAccess(
  requestedPath?: string | null
): Promise<BusinessDashboardAccessResult> {
  const session = await readCurrentServerSession();
  if (!session) {
    return {
      status: 'unauthenticated',
      redirectTo: buildDashboardLoginRedirect(requestedPath),
    };
  }

  const user = await getServerAuthenticatedUser();
  if (!user) {
    return {
      status: 'unauthenticated',
      redirectTo: buildDashboardLoginRedirect(requestedPath),
    };
  }

  try {
    const res = await callLaravelApi<OwnerBusinessProfileDto[]>('/api/v1/owner/businesses', {
      method: 'GET',
      bearerToken: session.record.accessToken,
    });

    if (res.status === 401) {
      await getBffSessionStore().delete(session.opaqueSessionId);
      return {
        status: 'unauthenticated',
        redirectTo: buildDashboardLoginRedirect(requestedPath),
      };
    }

    if (!res.ok || res.payload?.status !== 'success' || res.payload.data.length === 0) {
      return {
        status: 'forbidden',
        user,
      };
    }

    return {
      status: 'allowed',
      user,
      businesses: res.payload.data,
    };
  } catch {
    return {
      status: 'forbidden',
      user,
    };
  }
}
