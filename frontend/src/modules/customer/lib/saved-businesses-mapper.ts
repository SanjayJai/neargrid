import type {
  PublicBusinessProfileDto,
  SearchBusinessHitDto,
} from '@/shared/api/directory-types';

/**
 * Maps a PublicBusinessProfileDto (from GET /api/account/saved-businesses)
 * to SearchBusinessHitDto so existing DirectoryListingCard can be reused cleanly
 * without exposing any private business fields.
 */
export function mapPublicBusinessToSearchHit(
  profile: PublicBusinessProfileDto
): SearchBusinessHitDto {
  const addressParts = [
    profile.address.line_1,
    profile.address.locality?.name,
  ].filter((part): part is string => Boolean(part && part.trim() !== ''));

  return {
    public_id: profile.public_id,
    name: profile.name,
    slug: profile.slug,
    tagline: profile.tagline,
    city_slug: profile.address.city?.slug ?? null,
    city_name: profile.address.city?.name ?? null,
    locality_slug: profile.address.locality?.slug ?? null,
    locality_name: profile.address.locality?.name ?? null,
    primary_category: profile.primary_category
      ? {
          public_id: profile.primary_category.public_id,
          slug: profile.primary_category.slug,
          name: profile.primary_category.name,
        }
      : null,
    is_sponsored: false,
    sponsor_disclosure: null,
    is_verified: profile.is_verified,
    is_trusted: profile.is_trusted,
    is_claimed: profile.is_claimed,
    rating_avg: profile.rating_avg,
    review_count: profile.review_count,
    completeness_score: profile.completeness_score,
    distance_km: null,
    is_open_now: profile.is_open_now,
    address_short: addressParts.join(', '),
    phone_masked: profile.contact.phone_masked,
    has_whatsapp: profile.contact.has_whatsapp,
    services_preview: (profile.services ?? []).map((s) => s.name),
    media_preview: (profile.media ?? []).map((m) => ({
      public_id: m.public_id,
      media_type: m.media_type,
      alt_text: m.alt_text,
      urls: m.urls,
    })),
  };
}
