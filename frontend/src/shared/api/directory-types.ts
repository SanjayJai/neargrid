/**
 * Frontend TypeScript contracts matching Laravel 13 Directory & Media Resources.
 * Prepared for Milestone 8 live integration; demo views remain clearly labelled until then.
 */

export type BusinessStatusDto =
  | 'draft'
  | 'pending_review'
  | 'active'
  | 'suspended'
  | 'rejected';

export type BusinessMemberRoleDto = 'owner' | 'manager' | 'editor' | 'lead_agent';

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
  document_type: string;
  upload_status: string;
  mime_type: string;
  file_size_bytes: number;
  width: number | null;
  height: number | null;
  sha256_checksum: string;
  created_at: string | null;
}

export interface KycSignedDownloadUrlDto {
  download_url: string;
  expires_at: string;
  ttl_seconds: number;
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
