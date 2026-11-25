import { callLaravelApi } from '@/lib/bff/laravel-client';
import { bffError, forwardLaravelJson } from '@/lib/bff/route-helpers';
import type { CategoryLandingResponseDto } from '@/shared/api/directory-types';

const SLUG_SEGMENT_RE = /^[a-z0-9-]+$/;

export async function GET(request: Request) {
  const url = new URL(request.url);
  const rawPath = (url.searchParams.get('path') ?? '').trim().replace(/^\/+|\/+$/g, '');
  const segments = rawPath ? rawPath.split('/') : [];

  if (
    segments.length === 0 ||
    segments.length > 5 ||
    !segments.every((seg) => SLUG_SEGMENT_RE.test(seg))
  ) {
    return bffError('INVALID_CATEGORY_PATH', 'Invalid category path.', 422);
  }

  const locale = url.searchParams.get('locale') === 'ta' ? 'ta' : 'en';
  const encodedPath = segments.map((seg) => encodeURIComponent(seg)).join('/');

  try {
    const result = await callLaravelApi<CategoryLandingResponseDto>(
      `/api/v1/categories/landing/${encodedPath}?locale=${locale}`,
      {
        method: 'GET',
        requestId: request.headers.get('x-request-id'),
      }
    );
    return forwardLaravelJson(result, undefined, { forwardCacheHeaders: true });
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to resolve category landing.', 503);
  }
}
