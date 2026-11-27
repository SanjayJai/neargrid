import { callLaravelApi } from '@/lib/bff/laravel-client';
import {
  getServerAuthenticatedUser,
  readCurrentServerSession,
} from '@/lib/bff/session-cookie';
import { getBffSessionStore } from '@/lib/bff/session-store';
import type { UserDto } from '@/lib/bff/types';

export interface AdminAuditLogEntryDto {
  id: number;
  actor_role: string;
  action: string;
  auditable_type: string;
  auditable_id: number;
  created_at: string | null;
}

export interface AdminBootstrapDto {
  actor: {
    public_id: string;
    name: string | null;
    email: string | null;
    roles: string[];
    permissions: string[];
  };
  module_counts: {
    businesses_total: number;
    pending_verifications: number;
    pending_reviews: number;
    open_enquiries: number;
    domains_total: number;
    categories_total: number;
    users_total: number;
  };
  recent_activity: AdminAuditLogEntryDto[];
  capabilities: {
    navigation_search: boolean;
    record_search: boolean;
  };
}

export type AdminAccessResult =
  | { status: 'unauthenticated'; redirectTo: string }
  | { status: 'forbidden'; user: UserDto }
  | { status: 'allowed'; user: UserDto; bootstrap: AdminBootstrapDto };

export function buildAdminLoginRedirect(requestedPath?: string | null): string {
  const safePath =
    requestedPath && (requestedPath === '/admin' || requestedPath.startsWith('/admin/'))
      ? requestedPath
      : '/admin';
  return `/login?next=${encodeURIComponent(safePath)}`;
}

/**
 * Authoritative server-side guard for all /admin routes.
 * Delegates RBAC verification to Laravel GET /api/v1/admin/bootstrap using the server-only Bearer token.
 */
export async function authorizeAdminAccess(
  requestedPath?: string | null
): Promise<AdminAccessResult> {
  const session = await readCurrentServerSession();
  if (!session) {
    return {
      status: 'unauthenticated',
      redirectTo: buildAdminLoginRedirect(requestedPath),
    };
  }

  const user = await getServerAuthenticatedUser();
  if (!user) {
    return {
      status: 'unauthenticated',
      redirectTo: buildAdminLoginRedirect(requestedPath),
    };
  }

  try {
    const res = await callLaravelApi<AdminBootstrapDto>('/api/v1/admin/bootstrap', {
      method: 'GET',
      bearerToken: session.record.accessToken,
    });

    if (res.status === 401) {
      await getBffSessionStore().delete(session.opaqueSessionId);
      return {
        status: 'unauthenticated',
        redirectTo: buildAdminLoginRedirect(requestedPath),
      };
    }

    if (!res.ok || res.payload?.status !== 'success') {
      return {
        status: 'forbidden',
        user,
      };
    }

    return {
      status: 'allowed',
      user,
      bootstrap: res.payload.data,
    };
  } catch {
    return {
      status: 'forbidden',
      user,
    };
  }
}
