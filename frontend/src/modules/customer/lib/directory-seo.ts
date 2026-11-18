import type {
  ReferenceCategoryDto,
  SearchBusinessHitDto,
  SearchQueryParams,
  SearchSeoMetaDto,
} from '@/shared/api/directory-types';

/**
 * Resolves a localized category display name across both parent categories and nested subcategories,
 * falling back to Title Case of the slug if not found.
 */
export function resolveCategoryDisplayName(
  categories: ReferenceCategoryDto[] | null | undefined,
  slug: string
): string {
  if (categories) {
    for (const parent of categories) {
      if (parent.slug === slug) return parent.name;
      if (Array.isArray(parent.children)) {
        const child = parent.children.find((c) => c.slug === slug);
        if (child) return child.name;
      }
    }
  }
  return slug
    .split('-')
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ');
}

export type CanonicalDirectoryPageKind = 'city_category' | 'city_locality_category';

export interface EvaluateDirectorySeoInput {
  pageKind: CanonicalDirectoryPageKind;
  citySlug: string;
  categorySlug: string;
  localitySlug?: string;
  params: SearchQueryParams;
  apiOk: boolean;
  totalOrganic: number;
  backendSeo?: SearchSeoMetaDto | null;
  siteUrl?: string;
}

export interface EvaluatedDirectorySeo {
  basePath: string;
  canonicalPath: string;
  canonicalUrl: string;
  isIndexable: boolean;
  robots: {
    index: boolean;
    follow: boolean;
  };
  robotsHeader: 'index, follow' | 'noindex, follow';
  noindexReason: string | null;
}

const MAX_INDEXED_PAGE = 50;
const MIN_LOCALITY_CATEGORY_LISTINGS = 3;
const MIN_CITY_CATEGORY_LISTINGS = 1;

/**
 * Deterministic SEO evaluation for canonical directory routes per docs/12-seo-architecture.md.
 * Fails closed to `noindex, follow` when API data is unavailable, filtered, sorted, thin, or deep-paginated.
 */
export function evaluateCanonicalDirectorySeo(
  input: EvaluateDirectorySeoInput
): EvaluatedDirectorySeo {
  const siteUrl = (
    input.siteUrl ??
    process.env.NEXT_PUBLIC_SITE_URL ??
    'http://localhost:3000'
  ).replace(/\/$/, '');

  const basePath =
    input.pageKind === 'city_locality_category' && input.localitySlug
      ? `/${encodeURIComponent(input.citySlug)}/${encodeURIComponent(input.localitySlug)}/${encodeURIComponent(input.categorySlug)}`
      : `/${encodeURIComponent(input.citySlug)}/${encodeURIComponent(input.categorySlug)}`;

  const { params } = input;
  const page = Math.max(1, params.page ?? 1);

  const hasExtraLocalityFilter =
    input.pageKind === 'city_category'
      ? Boolean(params.locality) || (params.localities?.length ?? 0) > 0
      : (params.localities?.length ?? 0) > 0;

  const hasArbitraryFilters =
    Boolean(params.q) ||
    Boolean(params.min_rating) ||
    Boolean(params.verified_only) ||
    Boolean(params.open_now) ||
    (params.amenities?.length ?? 0) > 0 ||
    (params.payment_methods?.length ?? 0) > 0 ||
    params.lat !== undefined ||
    params.lng !== undefined ||
    params.radius_km !== undefined ||
    hasExtraLocalityFilter;

  const hasNonDefaultSort = Boolean(params.sort && params.sort !== 'relevance');
  const minRequired =
    input.pageKind === 'city_locality_category'
      ? MIN_LOCALITY_CATEGORY_LISTINGS
      : MIN_CITY_CATEGORY_LISTINGS;

  let isIndexable = true;
  let noindexReason: string | null = null;

  if (!input.apiOk) {
    isIndexable = false;
    noindexReason = 'upstream_unavailable';
  } else if (hasArbitraryFilters) {
    isIndexable = false;
    noindexReason = 'filtered_facet_combination';
  } else if (hasNonDefaultSort) {
    isIndexable = false;
    noindexReason = 'non_default_sort_parameter';
  } else if (page > MAX_INDEXED_PAGE) {
    isIndexable = false;
    noindexReason = 'pagination_exceeds_index_limit';
  } else if (input.totalOrganic < minRequired) {
    isIndexable = false;
    noindexReason =
      input.pageKind === 'city_locality_category'
        ? 'thin_locality_category_page'
        : 'zero_active_listings';
  } else if (
    input.backendSeo &&
    (!input.backendSeo.is_indexable || input.backendSeo.robots !== 'index, follow')
  ) {
    isIndexable = false;
    noindexReason = input.backendSeo.noindex_reason ?? 'backend_noindex_directive';
  }

  // Page 1 canonical is ALWAYS the bare path; pages 2..50 self-reference only when indexable.
  const canonicalPath =
    isIndexable && page >= 2 && page <= MAX_INDEXED_PAGE
      ? `${basePath}?page=${page}`
      : basePath;

  return {
    basePath,
    canonicalPath,
    canonicalUrl: `${siteUrl}${canonicalPath}`,
    isIndexable,
    robots: {
      index: isIndexable,
      follow: true,
    },
    robotsHeader: isIndexable ? 'index, follow' : 'noindex, follow',
    noindexReason,
  };
}

/**
 * Builds Schema.org ItemList JSON-LD for Page 1 organic listings only
 * (sponsored cards excluded per docs/12-seo-architecture.md Section 4.2).
 */
export function buildOrganicItemListJsonLd(
  hits: SearchBusinessHitDto[],
  fallbackCitySlug: string,
  currentPage: number,
  siteUrl?: string
): Record<string, unknown> | null {
  if (currentPage !== 1) {
    return null;
  }

  const normalizedSiteUrl = (
    siteUrl ??
    process.env.NEXT_PUBLIC_SITE_URL ??
    'http://localhost:3000'
  ).replace(/\/$/, '');

  const organicHits = hits.filter((h) => !h.is_sponsored).slice(0, 10);
  if (organicHits.length === 0) {
    return null;
  }

  return {
    '@context': 'https://schema.org',
    '@type': 'ItemList',
    itemListElement: organicHits.map((hit, idx) => ({
      '@type': 'ListItem',
      position: idx + 1,
      name: hit.name,
      url: `${normalizedSiteUrl}/${encodeURIComponent(hit.city_slug ?? fallbackCitySlug)}/business/${encodeURIComponent(hit.slug)}`,
    })),
  };
}
