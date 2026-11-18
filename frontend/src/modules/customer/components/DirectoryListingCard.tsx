'use client';

import { useState } from 'react';
import Link from 'next/link';
import { ArrowRight, MapPin, MessageCircle, Phone, Send } from 'lucide-react';
import type { SearchBusinessHitDto } from '@/shared/api/directory-types';
import { FallbackImage } from '@/shared/ui/FallbackImage';
import { RatingPill } from '@/shared/ui/RatingPill';
import {
  OpenStatusBadge,
  SponsoredBadge,
  TrustedBadge,
  VerifiedBadge,
} from '@/shared/ui/TrustBadges';
import { EnquiryModal } from './EnquiryModal';

interface DirectoryListingCardProps {
  hit: SearchBusinessHitDto;
  priority?: boolean;
}

/**
 * Dense, Justdial-style horizontal directory business card.
 * Backed 100% by live SearchBusinessHitDto from Laravel 13 MySQL 8.0 FullText & Spatial engine.
 * Preserves contact privacy: displays masked phone and WhatsApp availability without exposing
 * private contact numbers or implying unsupported contact-reveal flows.
 */
export function DirectoryListingCard({ hit, priority = false }: DirectoryListingCardProps) {
  const [enquiryOpen, setEnquiryOpen] = useState(false);

  const citySlug = hit.city_slug ?? 'chennai';
  const profileHref = `/${encodeURIComponent(citySlug)}/business/${encodeURIComponent(hit.slug)}`;

  const previewImage =
    hit.media_preview[0]?.urls.card ??
    hit.media_preview[0]?.urls.thumb ??
    hit.media_preview[0]?.urls.full ??
    null;

  // SearchBusinessHitResource already embeds locality_name in address_short; avoid duplicating it.
  const addressParts: string[] = [];
  if (hit.address_short) {
    addressParts.push(hit.address_short);
  } else if (hit.locality_name) {
    addressParts.push(hit.locality_name);
  }
  if (
    hit.city_name &&
    !addressParts.join(', ').toLowerCase().includes(hit.city_name.toLowerCase())
  ) {
    addressParts.push(hit.city_name);
  }
  const formattedAddress = addressParts.join(', ');

  return (
    <>
      <article
        data-testid={`directory-hit-${hit.public_id}`}
        className="group flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition-all hover:border-brand-300 hover:shadow-md sm:flex-row"
      >
        {/* Thumbnail preview */}
        <Link
          href={profileHref}
          className="relative h-44 w-full shrink-0 overflow-hidden bg-gray-100 sm:h-auto sm:w-48 md:w-52"
        >
          <FallbackImage
            src={previewImage}
            alt={hit.media_preview[0]?.alt_text || `${hit.name} storefront`}
            fallbackLabel={hit.primary_category?.name ?? hit.name}
            priority={priority}
          />
        </Link>

        {/* Content area */}
        <div className="flex min-w-0 flex-1 flex-col justify-between p-4 sm:p-5">
          <div>
            {/* Header row: Badges */}
            <div className="flex flex-wrap items-center gap-1.5">
              {hit.is_sponsored && <SponsoredBadge label={hit.sponsor_disclosure} />}
              {hit.is_verified && <VerifiedBadge />}
              {hit.is_trusted && <TrustedBadge />}
              <OpenStatusBadge isOpenNow={hit.is_open_now} />
              {hit.primary_category && (
                <span className="rounded bg-brand-50 px-2 py-0.5 text-[11px] font-semibold text-brand-700">
                  {hit.primary_category.name}
                </span>
              )}
            </div>

            {/* Business Title */}
            <div className="mt-2 flex items-start justify-between gap-2">
              <Link href={profileHref} className="block group-hover:underline">
                <h2 className="text-base sm:text-lg font-bold text-brand-700 line-clamp-1">
                  {hit.name}
                </h2>
              </Link>
            </div>

            {hit.tagline && (
              <p className="mt-0.5 text-xs text-ink-muted line-clamp-1">{hit.tagline}</p>
            )}

            {/* Rating pill */}
            <div className="mt-2 flex items-center gap-2">
              <RatingPill ratingAvg={hit.rating_avg} reviewCount={hit.review_count} />
            </div>

            {/* Address & Locality with optional distance */}
            <p className="mt-2.5 flex items-start gap-1.5 text-xs text-ink-muted">
              <MapPin
                className="mt-0.5 h-3.5 w-3.5 shrink-0 text-signal-600"
                aria-hidden="true"
              />
              <span className="line-clamp-1">
                {formattedAddress}
                {hit.distance_km !== null && hit.distance_km !== undefined ? (
                  <strong className="ml-1 font-semibold text-signal-700">
                    · {hit.distance_km.toFixed(1)} km away
                  </strong>
                ) : null}
              </span>
            </p>

            {/* Service Chips */}
            {hit.services_preview && hit.services_preview.length > 0 && (
              <div className="mt-2.5 flex flex-wrap gap-1.5" aria-label="Offered services">
                {hit.services_preview.slice(0, 4).map((svc) => (
                  <span
                    key={svc}
                    className="rounded bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-ink-muted"
                  >
                    {svc}
                  </span>
                ))}
                {hit.services_preview.length > 4 && (
                  <span className="text-[11px] text-ink-muted self-center">
                    +{hit.services_preview.length - 4} more
                  </span>
                )}
              </div>
            )}
          </div>

          {/* Action strip */}
          <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-3">
            <div className="flex flex-wrap items-center gap-2">
              {/* Privacy-masked phone badge (non-interactive; no raw number exposed) */}
              {hit.phone_masked && (
                <span
                  aria-label={`Masked phone number: ${hit.phone_masked}`}
                  title="Contact number masked for privacy"
                  className="inline-flex items-center gap-1.5 rounded-lg bg-trust-700 px-3 py-1.5 text-xs font-semibold text-white"
                >
                  <Phone className="h-3.5 w-3.5" aria-hidden="true" />
                  <span>{hit.phone_masked}</span>
                </span>
              )}

              {/* WhatsApp availability badge (non-interactive; private number never exposed) */}
              {hit.has_whatsapp && (
                <span
                  aria-label="WhatsApp available"
                  title="This business supports WhatsApp contact"
                  className="inline-flex items-center gap-1 rounded-lg border border-trust-600/30 bg-trust-50 px-2.5 py-1.5 text-xs font-semibold text-trust-800"
                >
                  <MessageCircle className="h-3.5 w-3.5" aria-hidden="true" />
                  <span>WhatsApp Available</span>
                </span>
              )}

              {/* Enquiry status dialog CTA */}
              <button
                type="button"
                onClick={() => setEnquiryOpen(true)}
                className="inline-flex items-center gap-1 rounded-lg border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-ink hover:bg-gray-50 transition-colors"
              >
                <Send className="h-3 w-3 text-brand-600" aria-hidden="true" />
                <span>Send Enquiry</span>
              </button>
            </div>

            {/* View Profile */}
            <Link
              href={profileHref}
              className="inline-flex items-center gap-1 rounded-lg border border-brand-200 bg-brand-50 px-3 py-1.5 text-xs font-semibold text-brand-700 transition-colors hover:bg-brand-600 hover:text-white"
            >
              <span>View Profile</span>
              <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
            </Link>
          </div>
        </div>
      </article>

      {/* Honest Phase 3C Enquiry Feature Status Dialog */}
      <EnquiryModal
        isOpen={enquiryOpen}
        onClose={() => setEnquiryOpen(false)}
        businessName={hit.name}
        profileHref={profileHref}
        phoneMasked={hit.phone_masked}
      />
    </>
  );
}
