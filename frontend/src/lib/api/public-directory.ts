import { callLaravelApi } from '@/lib/bff/laravel-client';
import type { ApiErrorEnvelope } from '@/lib/bff/types';
import type {
  CategoryLandingResponseDto,
  CategoryTreeResponseDto,
  PublicBusinessProfileDto,
  ReferenceAttributesDto,
  ReferenceCategoryDto,
  ReferenceCityDto,
  ReferenceLocalityDto,
  ReferenceStateDto,
  SearchBusinessHitDto,
  SearchFiltersDataDto,
  SearchFiltersMetaDto,
  SearchQueryParams,
  SearchResponseMetaDto,
  SearchSuggestionsDto,
  SearchSuggestionsMetaDto,
} from '@/shared/api/directory-types';

export interface PublicApiResult<T, M = unknown> {
  ok: boolean;
  status: number;
  data: T | null;
  meta: M | null;
  error: ApiErrorEnvelope['error'] | null;
  redirectTargetPath?: string | null;
}

export function buildSearchQueryString(params: SearchQueryParams): string {
  const qs = new URLSearchParams();

  if (params.q && params.q.trim() !== '') qs.set('q', params.q.trim());
  if (params.city) qs.set('city', params.city);
  if (params.category) qs.set('category', params.category);
  if (params.locality) qs.set('locality', params.locality);
  if (params.localities) {
    for (const loc of params.localities) {
      if (loc) qs.append('localities[]', loc);
    }
  }
  if (params.lat !== undefined) qs.set('lat', String(params.lat));
  if (params.lng !== undefined) qs.set('lng', String(params.lng));
  if (params.radius_km !== undefined) qs.set('radius_km', String(params.radius_km));
  if (params.min_rating !== undefined) qs.set('min_rating', String(params.min_rating));
  if (params.verified_only) qs.set('verified_only', '1');
  if (params.open_now) qs.set('open_now', '1');
  if (params.amenities) {
    for (const a of params.amenities) {
      if (a) qs.append('amenities[]', a);
    }
  }
  if (params.payment_methods) {
    for (const pm of params.payment_methods) {
      if (pm) qs.append('payment_methods[]', pm);
    }
  }
  if (params.sort) qs.set('sort', params.sort);
  if (params.page !== undefined) qs.set('page', String(params.page));
  if (params.per_page !== undefined) qs.set('per_page', String(params.per_page));
  if (params.locale) qs.set('locale', params.locale);

  const built = qs.toString();
  return built ? `?${built}` : '';
}

function unwrapPublicResult<T, M = unknown>(
  res: Awaited<ReturnType<typeof callLaravelApi<T>>>
): PublicApiResult<T, M> {
  if (res.ok && res.payload && res.payload.status === 'success') {
    return {
      ok: true,
      status: res.status,
      data: res.payload.data,
      meta: (res.payload.meta as unknown as M) ?? null,
      error: null,
    };
  }

  const err =
    res.payload && res.payload.status === 'error'
      ? res.payload.error
      : {
          code: 'UPSTREAM_UNAVAILABLE',
          message: 'Directory API is temporarily unavailable.',
        };

  return {
    ok: false,
    status: res.status,
    data: null,
    meta: null,
    error: err,
    redirectTargetPath:
      res.status === 301
        ? (typeof err.details?.target_path === 'string'
            ? err.details.target_path
            : res.location)
        : null,
  };
}

/**
 * Server-side public Laravel 13 Directory & Search API client.
 * Safely handles unavailable upstream connections without throwing uncaught RSC errors.
 */
async function safePublicGet<T, M = unknown>(
  path: string,
  revalidateSeconds?: number
): Promise<PublicApiResult<T, M>> {
  try {
    const res = await callLaravelApi<T>(path, {
      method: 'GET',
      revalidateSeconds,
    });
    return unwrapPublicResult<T, M>(res);
  } catch {
    return {
      ok: false,
      status: 503,
      data: null,
      meta: null,
      error: {
        code: 'UPSTREAM_UNREACHABLE',
        message: 'Could not reach the NearGrid API service. Please try again.',
      },
    };
  }
}

export async function fetchPublicStates(
  locale: 'en' | 'ta' = 'en',
  options?: { countryCode?: string; q?: string }
): Promise<PublicApiResult<ReferenceStateDto[]>> {
  const params = new URLSearchParams({ locale });
  if (options?.countryCode) params.set('country_code', options.countryCode);
  if (options?.q) params.set('q', options.q);

  return safePublicGet<ReferenceStateDto[]>(
    `/api/v1/locations/states?${params.toString()}`,
    300
  );
}

export async function fetchPublicCities(
  locale: 'en' | 'ta' = 'en',
  options?: { state?: string; q?: string }
): Promise<PublicApiResult<ReferenceCityDto[]>> {
  const params = new URLSearchParams({ locale });
  if (options?.state) params.set('state', options.state);
  if (options?.q) params.set('q', options.q);

  return safePublicGet<ReferenceCityDto[]>(
    `/api/v1/locations/cities?${params.toString()}`,
    300
  );
}

export async function fetchPublicLocalities(
  citySlug: string,
  locale: 'en' | 'ta' = 'en'
): Promise<PublicApiResult<ReferenceLocalityDto[]>> {
  return safePublicGet<ReferenceLocalityDto[]>(
    `/api/v1/locations/cities/${encodeURIComponent(citySlug)}/localities?locale=${encodeURIComponent(locale)}`,
    300
  );
}

export async function fetchPublicCategories(
  locale: 'en' | 'ta' = 'en'
): Promise<PublicApiResult<ReferenceCategoryDto[]>> {
  return safePublicGet<ReferenceCategoryDto[]>(
    `/api/v1/categories?locale=${encodeURIComponent(locale)}`,
    300
  );
}

export async function fetchPublicCategoryTree(
  locale: 'en' | 'ta' = 'en'
): Promise<PublicApiResult<CategoryTreeResponseDto>> {
  return safePublicGet<CategoryTreeResponseDto>(
    `/api/v1/categories/tree?locale=${encodeURIComponent(locale)}`,
    300
  );
}

export async function fetchPublicCategoryLanding(
  slugPath: string,
  locale: 'en' | 'ta' = 'en'
): Promise<PublicApiResult<CategoryLandingResponseDto>> {
  const encodedPath = slugPath
    .split('/')
    .filter(Boolean)
    .map((seg) => encodeURIComponent(seg))
    .join('/');

  return safePublicGet<CategoryLandingResponseDto>(
    `/api/v1/categories/landing/${encodedPath}?locale=${encodeURIComponent(locale)}`,
    120
  );
}

export async function fetchPublicAttributes(): Promise<PublicApiResult<ReferenceAttributesDto>> {
  return safePublicGet<ReferenceAttributesDto>('/api/v1/attributes', 300);
}

export async function fetchPublicBusinessProfile(
  citySlug: string,
  businessSlug: string,
  locale: 'en' | 'ta' = 'en'
): Promise<PublicApiResult<PublicBusinessProfileDto>> {
  return safePublicGet<PublicBusinessProfileDto>(
    `/api/v1/businesses/${encodeURIComponent(citySlug)}/${encodeURIComponent(businessSlug)}?locale=${encodeURIComponent(locale)}`,
    60
  );
}

export async function fetchPublicSearch(
  params: SearchQueryParams
): Promise<PublicApiResult<SearchBusinessHitDto[], SearchResponseMetaDto>> {
  const qs = buildSearchQueryString(params);
  return safePublicGet<SearchBusinessHitDto[], SearchResponseMetaDto>(`/api/v1/search${qs}`);
}

export async function fetchPublicNearby(
  params: SearchQueryParams & { lat: number; lng: number }
): Promise<PublicApiResult<SearchBusinessHitDto[], SearchResponseMetaDto>> {
  const qs = buildSearchQueryString(params);
  return safePublicGet<SearchBusinessHitDto[], SearchResponseMetaDto>(`/api/v1/search/nearby${qs}`);
}

export async function fetchPublicSuggestions(params: {
  q: string;
  city?: string;
  locale?: 'en' | 'ta';
}): Promise<PublicApiResult<SearchSuggestionsDto, SearchSuggestionsMetaDto>> {
  const qs = new URLSearchParams({ q: params.q });
  if (params.city) qs.set('city', params.city);
  if (params.locale) qs.set('locale', params.locale);
  return safePublicGet<SearchSuggestionsDto, SearchSuggestionsMetaDto>(
    `/api/v1/search/suggestions?${qs.toString()}`
  );
}

export async function fetchPublicSearchFilters(params: {
  city?: string;
  category?: string;
  locale?: 'en' | 'ta';
}): Promise<PublicApiResult<SearchFiltersDataDto, SearchFiltersMetaDto>> {
  const qs = new URLSearchParams();
  if (params.city) qs.set('city', params.city);
  if (params.category) qs.set('category', params.category);
  if (params.locale) qs.set('locale', params.locale);
  const suffix = qs.toString() ? `?${qs.toString()}` : '';
  return safePublicGet<SearchFiltersDataDto, SearchFiltersMetaDto>(
    `/api/v1/search/filters${suffix}`,
    120
  );
}
