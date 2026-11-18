import { callLaravelApi } from '@/lib/bff/laravel-client';
import { bffError, forwardLaravelJson } from '@/lib/bff/route-helpers';
import type { ReferenceCityDto } from '@/shared/api/directory-types';

export async function GET(request: Request) {
  const url = new URL(request.url);
  const locale = url.searchParams.get('locale') === 'ta' ? 'ta' : 'en';

  try {
    const result = await callLaravelApi<ReferenceCityDto[]>(
      `/api/v1/locations/cities?locale=${locale}`,
      {
        method: 'GET',
        requestId: request.headers.get('x-request-id'),
      }
    );
    return forwardLaravelJson(result, undefined, { forwardCacheHeaders: true });
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to load cities.', 503);
  }
}
