'use client';

import type { ApiEnvelope } from '@/lib/bff/types';

const CSRF_COOKIE_NAME = 'lbd_csrf';

function readCookie(name: string): string | null {
  if (typeof document === 'undefined') {
    return null;
  }

  for (const part of document.cookie.split(';')) {
    const [key, ...rest] = part.trim().split('=');
    if (key === name) {
      return decodeURIComponent(rest.join('='));
    }
  }

  return null;
}

export async function ensureCsrfToken(): Promise<string> {
  const existing = readCookie(CSRF_COOKIE_NAME);
  if (existing) {
    return existing;
  }

  const res = await fetch('/api/auth/csrf', {
    method: 'GET',
    credentials: 'same-origin',
    cache: 'no-store',
  });

  if (!res.ok) {
    throw new Error('Unable to initialize CSRF protection.');
  }

  const json = (await res.json()) as { data?: { csrf_token?: string } };
  const token = json.data?.csrf_token ?? readCookie(CSRF_COOKIE_NAME);
  if (!token) {
    throw new Error('CSRF token missing after initialization.');
  }

  return token;
}

export async function bffFetch<T>(
  path: string,
  options: {
    method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
    body?: unknown;
    signal?: AbortSignal;
  } = {}
): Promise<{ ok: boolean; status: number; payload: ApiEnvelope<T> | null }> {
  const method = options.method ?? 'GET';
  const headers: Record<string, string> = {
    Accept: 'application/json',
  };

  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }

  if (method !== 'GET') {
    headers['X-CSRF-Token'] = await ensureCsrfToken();
  }

  const response = await fetch(path, {
    method,
    headers,
    credentials: 'same-origin',
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    cache: 'no-store',
    signal: options.signal,
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
  };
}

/**
 * Browser multipart/form-data upload helper for public business media & private KYC docs.
 * Automatically injects double-submit CSRF header and same-origin credentials; never touches Bearer tokens.
 */
export async function bffUploadFormData<T>(
  path: string,
  formData: FormData,
  options: { signal?: AbortSignal } = {}
): Promise<{ ok: boolean; status: number; payload: ApiEnvelope<T> | null }> {
  const csrfToken = await ensureCsrfToken();

  const response = await fetch(path, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'X-CSRF-Token': csrfToken,
    },
    credentials: 'same-origin',
    body: formData,
    cache: 'no-store',
    signal: options.signal,
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
  };
}
