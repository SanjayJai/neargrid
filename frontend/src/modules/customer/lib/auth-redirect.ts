import type { UserDto } from '@/lib/bff/types';

export function sanitizeInternalRedirectPath(raw?: string | null): string {
  if (!raw) return '/account';
  const trimmed = raw.trim();
  if (!trimmed.startsWith('/') || trimmed.startsWith('//') || trimmed.includes('\\')) {
    return '/account';
  }
  return trimmed;
}

export function resolvePostLoginRedirect(
  user?: Pick<UserDto, 'roles'> | null,
  rawNext?: string | null
): string {
  if (rawNext && rawNext.trim().length > 0) {
    return sanitizeInternalRedirectPath(rawNext);
  }

  const roles = user?.roles ?? [];
  if (roles.includes('super_admin')) {
    return '/admin';
  }
  if (roles.includes('business_owner')) {
    return '/business/dashboard';
  }

  return '/account';
}
