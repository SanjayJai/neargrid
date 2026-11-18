import { callLaravelApi } from '@/lib/bff/laravel-client';
import { bffError, forwardLaravelJson } from '@/lib/bff/route-helpers';
import type { SearchFiltersDataDto } from '@/shared/api/directory-types';

export async function GET(request: Request) {
  const url = new URL(request.url);
  const qs = url.searchParams.toString();
  const path = qs ? `/api/v1/search/filters?${qs}` : '/api/v1/search/filters';

  try {
    const result = await callLaravelApi<SearchFiltersDataDto>(path, {
      method: 'GET',
      requestId: request.headers.get('x-request-id'),
    });
    return forwardLaravelJson(result, undefined, { forwardCacheHeaders: true });
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to reach search filters service.', 503);
  }
}
