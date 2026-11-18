import { ApiEnvelope } from './types';

export interface LaravelCallOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  bearerToken?: string;
  requestId?: string | null;
}

export interface LaravelCallResult<T> {
  ok: boolean;
  status: number;
  payload: ApiEnvelope<T> | null;
  retryAfter: string | null;
  requestId: string | null;
}

function getApiBaseUrl(): string {
  return (process.env.API_BASE_URL ?? 'http://127.0.0.1:8000').replace(/\/+$/, '');
}

/**
 * Server-only HTTP client for Laravel 13 /api/v1/* endpoints.
 * Attaches Sanctum Bearer tokens exclusively on the server side.
 */
export async function callLaravelApi<T>(
  path: string,
  options: LaravelCallOptions = {}
): Promise<LaravelCallResult<T>> {
  const normalizedPath = path.startsWith('/') ? path : `/${path}`;
  const url = `${getApiBaseUrl()}${normalizedPath}`;

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Client-Platform': 'web',
  };

  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }

  if (options.bearerToken) {
    headers.Authorization = `Bearer ${options.bearerToken}`;
  }

  if (options.requestId) {
    headers['X-Request-ID'] = options.requestId;
  }

  const response = await fetch(url, {
    method: options.method ?? 'GET',
    headers,
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    cache: 'no-store',
  });

  let payload: ApiEnvelope<T> | null = null;
  if (response.status !== 204) {
    try {
      payload = (await response.json()) as ApiEnvelope<T>;
    } catch {
      payload = null;
    }
  }

  return {
    ok: response.ok,
    status: response.status,
    payload,
    retryAfter: response.headers.get('retry-after'),
    requestId: response.headers.get('x-request-id'),
  };
}
