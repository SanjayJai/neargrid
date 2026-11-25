import { extractTrustedRequestHost, normalizeRequestHostname } from '@/lib/domains/host-resolver';
import { ApiEnvelope } from './types';

export interface LaravelCallOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  bearerToken?: string;
  requestId?: string | null;
  requestHost?: string | null;
  entryPath?: string | null;
  signal?: AbortSignal;
  revalidateSeconds?: number;
  timeoutMs?: number;
}

export interface LaravelCallResult<T> {
  ok: boolean;
  status: number;
  payload: ApiEnvelope<T> | null;
  retryAfter: string | null;
  requestId: string | null;
  cacheControl: string | null;
  robotsTag: string | null;
  location: string | null;
}

export interface LaravelBinaryDownloadResult {
  ok: boolean;
  status: number;
  bytes: ArrayBuffer | null;
  contentType: string;
  contentDisposition: string | null;
  requestId: string | null;
  errorPayload: ApiEnvelope<unknown> | null;
}

export function getLaravelApiBaseUrl(): string {
  return (process.env.API_BASE_URL ?? 'http://127.0.0.1:8000').replace(/\/+$/, '');
}

async function resolveInternalForwardedHost(explicitHost?: string | null): Promise<string | null> {
  if (explicitHost !== undefined) {
    return normalizeRequestHostname(explicitHost);
  }

  try {
    const { headers } = await import('next/headers');
    const headerBag = await Promise.resolve(headers());
    return extractTrustedRequestHost(headerBag);
  } catch {
    return null;
  }
}

/**
 * Server-only HTTP client for Laravel 13 /api/v1/* JSON endpoints.
 * Attaches Sanctum Bearer tokens exclusively on the server side.
 */
export async function callLaravelApi<T>(
  path: string,
  options: LaravelCallOptions = {}
): Promise<LaravelCallResult<T>> {
  const normalizedPath = path.startsWith('/') ? path : `/${path}`;
  const url = `${getLaravelApiBaseUrl()}${normalizedPath}`;

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

  const forwardedHost = await resolveInternalForwardedHost(options.requestHost);
  if (forwardedHost) {
    headers['X-NearGrid-Host'] = forwardedHost;
  }

  const bffInternalSecret = process.env.BFF_INTERNAL_SECRET;
  if (bffInternalSecret) {
    headers['X-NearGrid-Bff-Secret'] = bffInternalSecret;
  }

  if (options.entryPath && options.entryPath.startsWith('/') && !options.entryPath.includes('..')) {
    headers['X-Entry-Path'] = options.entryPath;
  }

  const timeoutMs = options.timeoutMs ?? 8000;
  const timeoutController = new AbortController();
  const timeoutHandle = setTimeout(() => timeoutController.abort(), timeoutMs);

  const onExternalAbort = () => timeoutController.abort();
  if (options.signal) {
    if (options.signal.aborted) {
      timeoutController.abort();
    } else {
      options.signal.addEventListener('abort', onExternalAbort, { once: true });
    }
  }

  const fetchInit: RequestInit & { next?: { revalidate: number } } = {
    method: options.method ?? 'GET',
    headers,
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    signal: timeoutController.signal,
    redirect: 'manual',
  };

  if (options.revalidateSeconds !== undefined && !options.bearerToken && (options.method ?? 'GET') === 'GET') {
    fetchInit.next = { revalidate: options.revalidateSeconds };
  } else {
    fetchInit.cache = 'no-store';
  }

  let response: Response;
  try {
    response = await fetch(url, fetchInit);
  } finally {
    clearTimeout(timeoutHandle);
    options.signal?.removeEventListener('abort', onExternalAbort);
  }

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
    cacheControl: response.headers.get('cache-control'),
    robotsTag: response.headers.get('x-robots-tag'),
    location: response.headers.get('location'),
  };
}

/**
 * Server-only multipart/form-data forwarder for public business media & private KYC uploads.
 * Never sets Content-Type manually so fetch computes the exact multipart boundary.
 */
export async function callLaravelMultipartApi<T>(
  path: string,
  formData: FormData,
  options: {
    bearerToken: string;
    requestId?: string | null;
    requestHost?: string | null;
  }
): Promise<LaravelCallResult<T>> {
  const normalizedPath = path.startsWith('/') ? path : `/${path}`;
  const url = `${getLaravelApiBaseUrl()}${normalizedPath}`;

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Client-Platform': 'web',
    Authorization: `Bearer ${options.bearerToken}`,
  };

  if (options.requestId) {
    headers['X-Request-ID'] = options.requestId;
  }

  const forwardedHost = await resolveInternalForwardedHost(options.requestHost);
  if (forwardedHost) {
    headers['X-NearGrid-Host'] = forwardedHost;
  }

  const bffInternalSecret = process.env.BFF_INTERNAL_SECRET;
  if (bffInternalSecret) {
    headers['X-NearGrid-Bff-Secret'] = bffInternalSecret;
  }

  const response = await fetch(url, {
    method: 'POST',
    headers,
    body: formData,
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
    cacheControl: response.headers.get('cache-control'),
    robotsTag: response.headers.get('x-robots-tag'),
    location: response.headers.get('location'),
  };
}

/**
 * Validates that a Laravel-issued KYC signed download URL matches our configured
 * Laravel API origin & expected KYC download path before fetching it server-side (SSRF guard).
 */
export function isTrustedLaravelKycDownloadUrl(signedUrl: string): boolean {
  let parsedSigned: URL;
  let parsedBase: URL;

  try {
    parsedSigned = new URL(signedUrl);
    parsedBase = new URL(getLaravelApiBaseUrl());
  } catch {
    return false;
  }

  if (parsedSigned.protocol !== parsedBase.protocol) {
    return false;
  }

  // Allow localhost / 127.0.0.1 parity in local dev while enforcing exact host+port otherwise
  const normalizeHost = (u: URL) =>
    `${u.hostname === 'localhost' ? '127.0.0.1' : u.hostname}:${u.port || (u.protocol === 'https:' ? '443' : '80')}`;

  if (normalizeHost(parsedSigned) !== normalizeHost(parsedBase)) {
    return false;
  }

  return /^\/api\/v1\/kyc-documents\/[0-9A-HJKMNP-TV-Z]{26}\/download$/i.test(parsedSigned.pathname);
}

/**
 * Server-only private KYC binary fetcher using a freshly minted signed URL + Sanctum Bearer token.
 * Never returns the signed URL, signature, storage key, or Bearer token to browser callers.
 */
export async function fetchLaravelSignedKycBinary(
  signedUrl: string,
  bearerToken: string,
  requestId?: string | null
): Promise<LaravelBinaryDownloadResult> {
  if (!isTrustedLaravelKycDownloadUrl(signedUrl)) {
    return {
      ok: false,
      status: 502,
      bytes: null,
      contentType: 'application/json',
      contentDisposition: null,
      requestId: null,
      errorPayload: {
        status: 'error',
        error: {
          code: 'UNTRUSTED_SIGNED_URL',
          message: 'Upstream signed download URL failed origin verification.',
        },
        meta: {
          request_id: requestId ?? null,
          api_version: 'v1',
          timestamp: new Date().toISOString(),
        },
      },
    };
  }

  // Rewrite host to API_BASE_URL when APP_URL uses localhost vs 127.0.0.1 internally
  const signedParsed = new URL(signedUrl);
  const baseParsed = new URL(getLaravelApiBaseUrl());
  const targetUrl = `${baseParsed.origin}${signedParsed.pathname}${signedParsed.search}`;

  const headers: Record<string, string> = {
    Accept: '*/*',
    'X-Client-Platform': 'web',
    Authorization: `Bearer ${bearerToken}`,
  };

  if (requestId) {
    headers['X-Request-ID'] = requestId;
  }

  const response = await fetch(targetUrl, {
    method: 'GET',
    headers,
    cache: 'no-store',
    redirect: 'manual',
  });

  if (!response.ok) {
    let errorPayload: ApiEnvelope<unknown> | null = null;
    try {
      errorPayload = (await response.json()) as ApiEnvelope<unknown>;
    } catch {
      errorPayload = null;
    }

    return {
      ok: false,
      status: response.status,
      bytes: null,
      contentType: response.headers.get('content-type') ?? 'application/json',
      contentDisposition: null,
      requestId: response.headers.get('x-request-id'),
      errorPayload,
    };
  }

  const bytes = await response.arrayBuffer();

  return {
    ok: true,
    status: response.status,
    bytes,
    contentType: response.headers.get('content-type') ?? 'application/octet-stream',
    contentDisposition: response.headers.get('content-disposition'),
    requestId: response.headers.get('x-request-id'),
    errorPayload: null,
  };
}
