import Link from 'next/link';
import { ArrowRight, MapPin, MessageCircle, Phone } from 'lucide-react';
import type { SearchBusinessHitDto } from '@/shared/api/directory-types';
import { FallbackImage } from '@/shared/ui/FallbackImage';
import { RatingPill } from '@/shared/ui/RatingPill';
import {
  OpenStatusBadge,
  SponsoredBadge,
  TrustedBadge,
  VerifiedBadge,
} from '@/shared/ui/TrustBadges';
import { SaveBusinessButton } from './SaveBusinessButton';

interface HomeDirectoryCardProps {
  hit: SearchBusinessHitDto;
}

/**
 * Compact Justdial-style directory business card backed 100% by live SearchBusinessHitDto.
 */
export function HomeDirectoryCard({ hit }: HomeDirectoryCardProps) {
  const citySlug = hit.city_slug ?? 'chennai';
  const profileHref = `/${encodeURIComponent(citySlug)}/business/${encodeURIComponent(hit.slug)}`;
  const previewImage =
    hit.media_preview[0]?.urls.card ??
    hit.media_preview[0]?.urls.thumb ??
    hit.media_preview[0]?.urls.full ??
    null;

  return (
    <article className="group flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition-all hover:border-brand-300 hover:shadow-md sm:flex-row">
      {/* Thumbnail */}
      <Link
        href={profileHref}
        className="relative h-40 w-full shrink-0 overflow-hidden bg-gray-100 sm:h-auto sm:w-40"
      >
        <FallbackImage
          src={previewImage}
          alt={hit.media_preview[0]?.alt_text || hit.name}
          fallbackLabel={hit.primary_category?.name ?? hit.name}
        />
      </Link>

      {/* Content */}
      <div className="flex min-w-0 flex-1 flex-col justify-between p-4">
        <div>
          <div className="flex flex-wrap items-center gap-1.5">
            {hit.is_sponsored && <SponsoredBadge label={hit.sponsor_disclosure} />}
            {hit.is_verified && <VerifiedBadge />}
            {hit.is_trusted && <TrustedBadge />}
            <OpenStatusBadge isOpenNow={hit.is_open_now} />
          </div>

          <div className="mt-1.5 flex items-start justify-between gap-2">
            <Link href={profileHref} className="block min-w-0">
              <h3 className="truncate text-base font-bold text-brand-700 group-hover:underline">
                {hit.name}
              </h3>
            </Link>
            <SaveBusinessButton
              businessPublicId={hit.public_id}
              businessName={hit.name}
              size="sm"
            />
          </div>

          <div className="mt-1.5">
            <RatingPill ratingAvg={hit.rating_avg} reviewCount={hit.review_count} />
          </div>

          <p className="mt-2 flex items-start gap-1.5 text-xs text-ink-muted">
            <MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0 text-signal-600" aria-hidden="true" />
            <span className="line-clamp-1">
              {hit.address_short}
              {hit.distance_km !== null ? ` · ${hit.distance_km.toFixed(1)} km` : ''}
            </span>
          </p>

          {hit.services_preview.length > 0 && (
            <div className="mt-2 flex flex-wrap gap-1">
              {hit.services_preview.slice(0, 3).map((svc) => (
                <span
                  key={svc}
                  className="rounded bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-ink-muted"
                >
                  {svc}
                </span>
              ))}
            </div>
          )}
        </div>

        {/* Action Strip */}
        <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-3">
          <div className="flex flex-wrap items-center gap-2">
            <span className="inline-flex items-center gap-1.5 rounded-lg bg-trust-700 px-2.5 py-1.5 text-xs font-semibold text-white">
              <Phone className="h-3.5 w-3.5" aria-hidden="true" />
              {hit.phone_masked}
            </span>
            {hit.has_whatsapp && (
              <span className="inline-flex items-center gap-1 rounded-lg border border-trust-600/30 bg-trust-50 px-2.5 py-1.5 text-xs font-semibold text-trust-700">
                <MessageCircle className="h-3.5 w-3.5" aria-hidden="true" />
                WhatsApp
              </span>
            )}
          </div>

          <Link
            href={profileHref}
            className="inline-flex items-center gap-1 rounded-lg border border-brand-200 bg-brand-50 px-3 py-1.5 text-xs font-semibold text-brand-700 transition-colors hover:bg-brand-600 hover:text-white"
          >
            View Details
            <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
          </Link>
        </div>
      </div>
    </article>
  );
}
