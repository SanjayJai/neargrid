import { callLaravelApi } from '@/lib/bff/laravel-client';
import { bffError, forwardLaravelJson, isValidSlug } from '@/lib/bff/route-helpers';
import type { ReferenceLocalityDto } from '@/shared/api/directory-types';

export async function GET(
  request: Request,
  context: { params: { citySlug: string } }
) {
  const citySlug = context.params.citySlug;
  if (!isValidSlug(citySlug)) {
    return bffError('INVALID_CITY_SLUG', 'Invalid city slug.', 400);
  }

  const url = new URL(request.url);
  const locale = url.searchParams.get('locale') === 'ta' ? 'ta' : 'en';

  try {
    const result = await callLaravelApi<ReferenceLocalityDto[]>(
      `/api/v1/locations/cities/${encodeURIComponent(citySlug)}/localities?locale=${locale}`,
      {
        method: 'GET',
        requestId: request.headers.get('x-request-id'),
      }
    );
    return forwardLaravelJson(result, undefined, { forwardCacheHeaders: true });
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to load localities.', 503);
  }
}
