/**
 * Typed frontend DTOs strictly matching actual Laravel 13 API Resources and FormRequests
 * (Milestones 5, 6 & 7). Do NOT add unimplemented or invented response fields here.
 */

import type { ApiMeta } from '@/lib/bff/types';

// ---------------------------------------------------------------------------
// Reference Locations, Taxonomy & Attributes (ReferenceTaxonomyController)
// ---------------------------------------------------------------------------

export interface ReferenceStateDto {
  public_id: string;
  country_code: string;
  slug: string;
  name: string;
  name_en: string;
  cities_count: number;
  sort_order: number;
}

export interface ReferenceCityDto {
  public_id?: string;
  slug: string;
  name: string;
  name_en: string;
  state_slug?: string | null;
  state_name?: string | null;
  latitude: number | null;
  longitude: number | null;
  is_popular: boolean;
}

export interface ReferenceLocalityDto {
  public_id?: string;
  slug: string;
  name: string;
  name_en?: string;
  pincode: string | null;
  latitude: number | null;
  longitude: number | null;
  is_popular?: boolean;
}

export interface ReferenceCategoryChildDto {
  public_id: string;
  slug: string;
  name: string;
  schema_org_type: string;
  icon_name: string | null;
  is_featured: boolean;
}

export interface ReferenceCategoryDto extends ReferenceCategoryChildDto {
  children: ReferenceCategoryChildDto[];
}

// ---------------------------------------------------------------------------
// M-A1 Category Engine & Global SEO DTOs
// ---------------------------------------------------------------------------

export type CategoryNodeTypeDto =
  | 'main_category'
  | 'category_group'
  | 'subcategory'
  | 'speciality'
  | 'sub_speciality';

export type CategoryLifecycleStatusDto = 'draft' | 'review' | 'published' | 'archived';

export interface CategoryTreeNodeDto {
  public_id: string;
  slug: string;
  path: string;
  name: string;
  name_en: string;
  node_type: CategoryNodeTypeDto;
  depth: number;
  schema_org_type: string;
  icon_name: string | null;
  is_assignable: boolean;
  has_landing_page: boolean;
  show_on_homepage: boolean;
  is_popular: boolean;
  is_featured: boolean;
  sort_order: number;
  children: CategoryTreeNodeDto[];
}

export interface CategoryCollectionDto {
  public_id: string;
  slug: string;
  path: string;
  name: string;
  name_en: string;
  icon_name: string | null;
  show_on_homepage: boolean;
  is_popular: boolean;
  category_slugs: string[];
}

export interface CategoryTreeResponseDto {
  categories: CategoryTreeNodeDto[];
  collections: CategoryCollectionDto[];
}

export interface CategoryAttributeDefinitionDto {
  public_id: string;
  attribute_key: string;
  label_en: string;
  label_ta: string | null;
  data_type: 'string' | 'number' | 'boolean' | 'enum' | 'multi_enum';
  options_json: string[] | null;
  unit: string | null;
  is_filterable: boolean;
  is_required: boolean;
  show_on_profile: boolean;
  sort_order: number;
  is_active: boolean;
}

export interface CategoryFilterConfigDto {
  filter_key: string;
  filter_type: 'checkbox' | 'range' | 'toggle' | 'select';
  label_en: string;
  label_ta: string | null;
  sort_order: number;
  is_enabled: boolean;
}

export interface CategoryProfileSectionDto {
  section_key: string;
  display_label_en: string;
  display_label_ta: string | null;
  is_enabled: boolean;
  is_required: boolean;
  suppress_when_empty: boolean;
  sort_order: number;
}

export interface CategoryBreadcrumbDto {
  name: string;
  url: string;
}

export interface GlobalSeoPayloadDto {
  title: string;
  description: string;
  canonical_url: string;
  robots: string;
  indexable: boolean;
  indexability_reason: string;
  alternates: Record<string, string>;
  open_graph: {
    title: string;
    description: string;
    image_url: string | null;
    image_alt: string;
    url: string;
  };
  json_ld: Record<string, unknown>;
}

export interface CategoryLandingResponseDto {
  redirect: boolean;
  http_status?: number;
  target_path?: string;
  kind?: 'category' | 'collection';
  category?: {
    public_id: string;
    slug: string;
    path: string;
    name: string;
    node_type: CategoryNodeTypeDto;
    depth: number;
    schema_org_type: string;
    has_landing_page: boolean;
    children: Array<{
      public_id: string;
      slug: string;
      path: string;
      name: string;
      node_type: CategoryNodeTypeDto;
    }>;
  };
  collection?: {
    public_id: string;
    slug: string;
    name: string;
    description: string | null;
  };
  breadcrumbs?: CategoryBreadcrumbDto[];
  seo?: GlobalSeoPayloadDto;
}

export interface ReferenceAttributesDto {
  amenities: Array<{
    slug: string;
    name: string;
    icon_name: string | null;
    group_name: string | null;
  }>;
  payment_methods: Array<{
    slug: string;
    name: string;
    icon_name: string | null;
  }>;
}

// ---------------------------------------------------------------------------
// Directory Models & Resources (PublicBusinessResource / OwnerBusinessResource)
// ---------------------------------------------------------------------------

export type BusinessStatusDto =
  | 'draft'
  | 'pending_review'
  | 'active'
  | 'suspended'
  | 'rejected';

export type BusinessMemberRoleDto = 'owner' | 'manager' | 'editor' | 'lead_agent';

export type VerificationDocumentTypeDto =
  | 'gstin'
  | 'pan'
  | 'trade_license'
  | 'shop_act'
  | 'utility_bill'
  | 'incorporation_cert';

export interface BusinessHourSlotDto {
  day_of_week: number;
  slot_index: number;
  open_time: string | null;
  close_time: string | null;
  is_24_hours: boolean;
  is_closed: boolean;
}

export interface BusinessServiceDto {
  public_id: string;
  name: string;
  description: string | null;
  price_min_minor: number | null;
  price_max_minor: number | null;
  currency: string;
  price_unit: string | null;
  sort_order: number;
  is_active: boolean;
}

export type BusinessMediaTypeDto =
  | 'logo'
  | 'cover'
  | 'gallery'
  | 'interior'
  | 'exterior'
  | 'product'
  | 'menu'
  | 'certificate';

export type MediaUploadStatusDto = 'pending' | 'processing' | 'ready' | 'failed';

export interface MediaVariantUrlsDto {
  thumb: string | null;
  card: string | null;
  full: string | null;
}

export interface PublicBusinessMediaDto {
  public_id: string;
  media_type: BusinessMediaTypeDto;
  alt_text: string | null;
  width: number | null;
  height: number | null;
  sort_order: number;
  urls: MediaVariantUrlsDto;
}

export interface OwnerBusinessMediaDto extends PublicBusinessMediaDto {
  upload_status: MediaUploadStatusDto;
  moderation_status: 'pending' | 'approved' | 'rejected';
  mime_type: string;
  file_size_bytes: number;
  exif_stripped: boolean;
  failure_reason: string | null;
  processed_at: string | null;
  created_at: string | null;
}

export interface KycDocumentDto {
  public_id: string;
  document_type: VerificationDocumentTypeDto | string;
  upload_status: string;
  mime_type: string;
  file_size_bytes: number;
  width: number | null;
  height: number | null;
  sha256_checksum: string;
  created_at: string | null;
}

/**
 * Server-only signed URL response from Laravel POST .../kyc-documents/{doc}/signed-url.
 * NEVER expose this URL or signature to browser JavaScript; stream bytes via the BFF proxy.
 */
export interface LaravelKycSignedDownloadServerDto {
  download_url: string;
  expires_at: string;
  ttl_seconds: number;
}

export interface PublicBusinessProfileDto {
  public_id: string;
  name: string;
  slug: string;
  tagline: string | null;
  description: string | null;
  year_established: number | null;
  is_claimed: boolean;
  is_verified: boolean;
  is_trusted: boolean;
  rating_avg: number;
  review_count: number;
  photo_count: number;
  completeness_score: number;
  is_open_now: boolean;
  primary_category: {
    public_id: string;
    slug: string;
    name: string;
    schema_org_type: string;
  } | null;
  categories: Array<{
    public_id: string;
    slug: string;
    name: string;
    is_primary: boolean;
  }>;
  address: {
    line_1: string;
    line_2: string | null;
    landmark: string | null;
    locality: { slug: string; name: string } | null;
    city: { slug: string; name: string } | null;
    pincode: string;
    coordinates: { lat: number; lng: number };
  };
  contact: {
    phone_masked: string;
    has_whatsapp: boolean;
    website_url: string | null;
  };
  hours: BusinessHourSlotDto[];
  services: BusinessServiceDto[];
  amenities: Array<{ slug: string; name: string; icon_name: string | null }>;
  payment_methods: Array<{ slug: string; name: string }>;
  faqs: Array<{ question: string; answer: string }>;
  media: PublicBusinessMediaDto[];
}

export interface OwnerBusinessProfileDto {
  public_id: string;
  name: string;
  slug: string;
  tagline: string | null;
  description: string | null;
  search_keywords: string | null;
  year_established: number | null;
  status: BusinessStatusDto;
  is_claimed: boolean;
  is_verified: boolean;
  verified_at: string | null;
  is_trusted: boolean;
  completeness_score: number;
  primary_category: {
    public_id: string;
    slug: string;
    name: string;
  } | null;
  categories: Array<{
    public_id: string;
    slug: string;
    name: string;
    is_primary: boolean;
  }>;
  address: {
    line_1: string;
    line_2: string | null;
    landmark: string | null;
    locality_slug: string | null;
    locality_name: string | null;
    city_slug: string | null;
    city_name: string | null;
    pincode: string;
    latitude: number;
    longitude: number;
  };
  contact: {
    primary_phone_e164: string;
    secondary_phone_e164: string | null;
    whatsapp_e164: string | null;
    email: string | null;
    website_url: string | null;
  };
  hours: BusinessHourSlotDto[];
  services: BusinessServiceDto[];
  amenities: string[];
  payment_methods: string[];
  my_membership: {
    is_owner: boolean;
    role: BusinessMemberRoleDto | null;
    capabilities: string[];
  } | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface OwnerHoursUpdateResponseDto {
  business_public_id: string;
  completeness_score: number;
  hours: BusinessHourSlotDto[];
}

export interface BusinessMemberDto {
  public_id: string;
  role: BusinessMemberRoleDto;
  status: string;
  capabilities: string[];
  user: {
    public_id: string;
    name: string | null;
    phone_e164: string | null;
  } | null;
  accepted_at: string | null;
  created_at: string | null;
}

export interface BusinessVerificationDto {
  public_id: string;
  document_type: VerificationDocumentTypeDto | string;
  status: string;
  rejection_reason: string | null;
  reviewed_at: string | null;
  created_at: string | null;
}

export interface BusinessClaimDto {
  public_id: string;
  status: string;
  claim_notes: string | null;
  review_notes: string | null;
  reviewed_at: string | null;
  created_at: string | null;
}

// ---------------------------------------------------------------------------
// Search Engine DTOs (SearchController & SearchBusinessHitResource)
// ---------------------------------------------------------------------------

export type SearchSortOptionDto = 'relevance' | 'rating' | 'distance' | 'popularity' | 'newest';

export interface SearchBusinessHitDto {
  public_id: string;
  name: string;
  slug: string;
  tagline: string | null;
  city_slug: string | null;
  city_name: string | null;
  locality_slug: string | null;
  locality_name: string | null;
  primary_category: {
    public_id: string;
    slug: string;
    name: string;
  } | null;
  is_sponsored: boolean;
  sponsor_disclosure: string | null;
  is_verified: boolean;
  is_trusted: boolean;
  is_claimed: boolean;
  rating_avg: number;
  review_count: number;
  completeness_score: number;
  distance_km: number | null;
  is_open_now: boolean;
  address_short: string;
  phone_masked: string;
  has_whatsapp: boolean;
  services_preview: string[];
  media_preview: Array<{
    public_id: string;
    media_type: BusinessMediaTypeDto;
    alt_text: string | null;
    urls: MediaVariantUrlsDto;
  }>;
}

export interface SearchSeoMetaDto {
  canonical_path: string;
  canonical_url: string;
  robots: 'index, follow' | 'noindex, follow';
  is_indexable: boolean;
  noindex_reason: string | null;
}

export interface SearchPaginationMetaDto {
  type: 'offset';
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
  has_more: boolean;
  sponsored_count: number;
  returned_count: number;
}

export interface SearchAppliedFiltersDto {
  q: string | null;
  city: string | null;
  category: string | null;
  localities: string[];
  radius_km: number | null;
  has_coordinates: boolean;
  min_rating: number | null;
  verified_only: boolean;
  open_now: boolean;
  amenities: string[];
  payment_methods: string[];
  sort: SearchSortOptionDto;
  locale: string;
}

export interface SearchResponseMetaDto extends ApiMeta {
  pagination: SearchPaginationMetaDto;
  applied_filters: SearchAppliedFiltersDto;
  counts: {
    total_organic: number;
    sponsored_count: number;
    returned_count: number;
  };
  query: {
    original: string | null;
    normalized: string | null;
  };
  seo: SearchSeoMetaDto;
  performance: {
    duration_ms: number;
    driver: string;
  };
}

export interface SearchSuggestionsDto {
  categories: Array<{ public_id: string; slug: string; name: string; type: 'category' }>;
  services: Array<{ name: string; type: 'service' }>;
  cities: Array<{ slug: string; name: string; type: 'city' }>;
  localities: Array<{
    slug: string;
    name: string;
    city_slug: string | null;
    pincode: string | null;
    type: 'locality';
  }>;
  businesses: Array<{
    public_id: string;
    name: string;
    slug: string;
    city_slug: string | null;
    locality_name: string | null;
    is_verified: boolean;
    type: 'business';
  }>;
}

export interface SearchSuggestionsMetaDto extends ApiMeta {
  query: {
    original: string;
    normalized: string;
  };
  cache: {
    hit: boolean;
    source: string;
  };
  performance: {
    duration_ms: number;
  };
}

export interface SearchFiltersDataDto {
  cities: Array<{ slug: string; name: string; is_popular: boolean }>;
  localities: Array<{
    slug: string;
    name: string;
    pincode: string | null;
    active_listing_count: number;
  }>;
  categories: Array<{
    public_id: string;
    slug: string;
    name: string;
    depth: number;
    is_featured: boolean;
  }>;
  amenities: Array<{ slug: string; name: string; icon_name: string | null }>;
  payment_methods: Array<{ slug: string; name: string }>;
  rating_options: Array<{ value: number; label: string }>;
  sort_options: Array<{ value: SearchSortOptionDto; label: string }>;
  radius: {
    min_km: number;
    max_km: number;
    default_km: number;
  };
}

export interface SearchFiltersMetaDto extends ApiMeta {
  counts: {
    total_active: number;
    verified_active: number;
  };
}

// ---------------------------------------------------------------------------
// Typed Request Payloads (matching Laravel FormRequests)
// ---------------------------------------------------------------------------

export interface SearchQueryParams {
  q?: string;
  city?: string;
  category?: string;
  locality?: string;
  localities?: string[];
  lat?: number;
  lng?: number;
  radius_km?: number;
  min_rating?: number;
  verified_only?: boolean;
  open_now?: boolean;
  amenities?: string[];
  payment_methods?: string[];
  sort?: SearchSortOptionDto;
  page?: number;
  per_page?: number;
  locale?: 'en' | 'ta';
}

export interface CreateBusinessInput {
  name: string;
  tagline?: string | null;
  description?: string | null;
  search_keywords?: string | null;
  year_established?: number | null;
  primary_category_slug: string;
  secondary_category_slugs?: string[];
  city_slug: string;
  locality_slug: string;
  address_line_1: string;
  address_line_2?: string | null;
  landmark?: string | null;
  pincode: string;
  latitude?: number | null;
  longitude?: number | null;
  primary_phone_e164: string;
  secondary_phone_e164?: string | null;
  whatsapp_e164?: string | null;
  email?: string | null;
  website_url?: string | null;
  amenity_slugs?: string[];
  payment_method_slugs?: string[];
}

export type UpdateBusinessInput = Partial<CreateBusinessInput> & {
  slug?: string;
};

export interface ReplaceBusinessHoursInput {
  hours: Array<{
    day_of_week: number;
    slot_index?: 1 | 2;
    is_closed?: boolean;
    is_24_hours?: boolean;
    open_time?: string | null;
    close_time?: string | null;
  }>;
}

export interface UpsertBusinessServiceInput {
  name: string;
  description?: string | null;
  price_min_minor?: number | null;
  price_max_minor?: number | null;
  currency?: string;
  price_unit?: string | null;
  sort_order?: number;
  is_active?: boolean;
}

export interface CreateBusinessMemberInput {
  user_public_id: string;
  role: Exclude<BusinessMemberRoleDto, 'owner'>;
  permissions?: string[] | null;
}

export interface UpdateBusinessMemberInput {
  role: Exclude<BusinessMemberRoleDto, 'owner'>;
  permissions?: string[] | null;
  status?: 'active' | 'suspended';
}

export interface SubmitBusinessClaimInput {
  proof_file_path_private: string;
  claim_notes?: string | null;
}

export interface SubmitVerificationInput {
  document_type: VerificationDocumentTypeDto;
  document_number: string;
  private_file_path: string;
}

// ---------------------------------------------------------------------------
// Inbound CRM Leads (Phase 3C Milestone 2)
// ---------------------------------------------------------------------------

export type LeadStatusDto = 'new' | 'contacted' | 'converted' | 'archived';

export interface OwnerLeadDto {
  public_id: string;
  lead_status: LeadStatusDto;
  delivery_channel: string;
  delivery_status: string;
  delivery_timestamp: string | null;
  is_read: boolean;
  read_at: string | null;
  contacted_at: string | null;
  merchant_notes: string | null;
  assigned_user: {
    public_id: string;
    name: string | null;
    email: string | null;
  } | null;
  enquiry: {
    public_id: string;
    customer_name: string;
    phone_e164: string;
    email: string | null;
    requirement: string;
    urgency: 'immediate' | 'within_week' | 'flexible';
    enquiry_type: 'direct' | 'broadcast';
    category: {
      name: string;
      slug: string;
    };
    city: {
      name: string;
      slug: string;
    };
    locality: {
      name: string;
      slug: string;
    } | null;
    created_at: string;
  };
  created_at: string;
  updated_at: string;
}

export interface UpdateOwnerLeadInput {
  lead_status?: LeadStatusDto;
  assigned_user_public_id?: string | null;
  merchant_notes?: string | null;
  is_read?: boolean;
}

export type ReviewTrustBadgeDto = 'verified_customer' | 'verified_lead' | 'none';

export interface PublicReviewDto {
  public_id: string;
  customer_name: string;
  rating: number;
  title: string | null;
  content: string | null;
  is_verified_interaction: boolean;
  trust_badge?: ReviewTrustBadgeDto;
  owner_reply: {
    text: string;
    replied_at: string | null;
  } | null;
  created_at: string;
}

export interface PublicReviewsBreakdownDto {
  '1': number;
  '2': number;
  '3': number;
  '4': number;
  '5': number;
}

export interface OwnerReviewDto {
  public_id: string;
  customer_name: string;
  rating: number;
  title: string | null;
  content: string | null;
  is_verified_interaction: boolean;
  trust_badge?: ReviewTrustBadgeDto;
  interaction_type: string | null;
  status: string;
  moderation_status: string;
  has_reply: boolean;
  owner_reply: {
    text: string;
    replied_at: string | null;
    replied_by?: {
      public_id: string;
      name: string | null;
    } | null;
  } | null;
  created_at: string;
  updated_at: string;
}

export interface SubmitReviewInput {
  customer_name: string;
  phone_e164: string;
  rating: number;
  title?: string;
  content?: string;
}

export interface SubmitReviewResult {
  review: PublicReviewDto;
  requires_otp: boolean;
  challenge_id?: string | null;
  expires_in_seconds?: number | null;
  resend_after_seconds?: number | null;
}


