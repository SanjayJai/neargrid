'use client';

import { bffFetch } from '@/shared/api/bff-client';
import type { PublicBusinessProfileDto } from '@/shared/api/directory-types';
import type {
  SaveBusinessPayload,
  SavedBusinessIdsPayload,
} from '@/shared/api/saved-businesses-types';

export interface FetchSavedBusinessesOptions {
  page?: number;
  per_page?: number;
  signal?: AbortSignal;
}

export async function fetchSavedBusinesses(
  options: FetchSavedBusinessesOptions = {}
) {
  const params = new URLSearchParams();
  if (options.page !== undefined && options.page > 1) {
    params.set('page', String(options.page));
  }
  if (options.per_page !== undefined) {
    params.set('per_page', String(options.per_page));
  }

  const qs = params.toString();
  const path = `/api/account/saved-businesses${qs ? `?${qs}` : ''}`;

  return bffFetch<PublicBusinessProfileDto[]>(path, {
    method: 'GET',
    signal: options.signal,
  });
}

export async function fetchSavedBusinessIds(signal?: AbortSignal) {
  return bffFetch<SavedBusinessIdsPayload>('/api/account/saved-businesses/ids', {
    method: 'GET',
    signal,
  });
}

export async function saveBusiness(publicId: string, signal?: AbortSignal) {
  return bffFetch<SaveBusinessPayload>(
    `/api/account/saved-businesses/${encodeURIComponent(publicId)}`,
    {
      method: 'PUT',
      signal,
    }
  );
}

export async function unsaveBusiness(publicId: string, signal?: AbortSignal) {
  return bffFetch<null>(
    `/api/account/saved-businesses/${encodeURIComponent(publicId)}`,
    {
      method: 'DELETE',
      signal,
    }
  );
}
