export interface AdminPaginatedMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface AdminApiResult<T> {
  ok: boolean;
  data: T;
  meta?: AdminPaginatedMeta & { message?: string };
  message?: string;
}

export async function adminFetch<T>(
  path: string,
  options?: {
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    body?: unknown;
  }
): Promise<AdminApiResult<T>> {
  const res = await fetch(`/api/admin/${path.replace(/^\/+/, '')}`, {
    method: options?.method ?? 'GET',
    headers: options?.body !== undefined ? { 'Content-Type': 'application/json' } : undefined,
    body: options?.body !== undefined ? JSON.stringify(options.body) : undefined,
    cache: 'no-store',
  });

  const payload = await res.json().catch(() => null);
  if (!res.ok) {
    const errMsg =
      payload?.error?.message ||
      payload?.message ||
      `Request failed (${res.status})`;
    throw new Error(errMsg);
  }

  return {
    ok: true,
    data: (payload?.data ?? payload) as T,
    meta: payload?.meta,
    message: payload?.meta?.message ?? payload?.message,
  };
}
