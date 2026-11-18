import { callLaravelApi } from '@/lib/bff/laravel-client';
import { bffError, forwardLaravelJson } from '@/lib/bff/route-helpers';
import type { ReferenceAttributesDto } from '@/shared/api/directory-types';

export async function GET(request: Request) {
  try {
    const result = await callLaravelApi<ReferenceAttributesDto>('/api/v1/attributes', {
      method: 'GET',
      requestId: request.headers.get('x-request-id'),
    });
    return forwardLaravelJson(result, undefined, { forwardCacheHeaders: true });
  } catch {
    return bffError('UPSTREAM_UNREACHABLE', 'Unable to load attributes.', 503);
  }
}
