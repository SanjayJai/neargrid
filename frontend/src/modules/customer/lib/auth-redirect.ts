export function sanitizeInternalRedirectPath(raw?: string | null): string {
  if (!raw) return '/dashboard/settings';
  const trimmed = raw.trim();
  if (!trimmed.startsWith('/') || trimmed.startsWith('//') || trimmed.includes('\\')) {
    return '/dashboard/settings';
  }
  return trimmed;
}
