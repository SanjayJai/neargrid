'use client';

import Link from 'next/link';
import { ArrowUpRight } from 'lucide-react';
import { isSafeInternalNavigationUrl } from '@/modules/customer/lib/safe-navigation';
import { FallbackImage } from '@/shared/ui/FallbackImage';
import { SponsoredBadge } from '@/shared/ui/TrustBadges';

export interface DeliveredAdItem {
  creative_public_id: string;
  title: string;
  subtitle: string | null;
  cta_label: string | null;
  destination_url: string;
  image_url: string | null;
  mobile_image_url: string | null;
  alt_text: string;
  locale: 'en' | 'ta';
  disclosure_label: string;
  tracking_token: string;
  business: { public_id: string; name: string; slug: string } | null;
}

export interface DeliveredPlacement {
  placement_key: string;
  render_format: 'banner' | 'card' | 'native_listing' | 'text_link' | 'carousel' | 'strip';
  slot_position: string;
  width_px: number | null;
  height_px: number | null;
  max_ads: number;
  ads: DeliveredAdItem[];
}

function isSafeExternalUrl(url: string): boolean {
  try {
    const parsed = new URL(url);
    return parsed.protocol === 'https:' || parsed.protocol === 'http:';
  } catch {
    return false;
  }
}

function AdDestinationLink({
  href,
  onClick,
  className,
  children,
}: {
  href: string;
  onClick: () => void;
  className: string;
  children: React.ReactNode;
}) {
  if (isSafeInternalNavigationUrl(href)) {
    return (
      <Link href={href} onClick={onClick} className={className}>
        {children}
      </Link>
    );
  }
  if (isSafeExternalUrl(href)) {
    return (
      <a
        href={href}
        target="_blank"
        rel="noopener noreferrer sponsored"
        onClick={onClick}
        className={className}
      >
        {children}
      </a>
    );
  }
  return <span className={className}>{children}</span>;
}

export function AdFormatRenderers({
  placement,
  onAdClick,
}: {
  placement: DeliveredPlacement;
  onAdClick: (ad: DeliveredAdItem) => void;
}) {
  const { render_format: format, ads } = placement;
  if (ads.length === 0) return null;

  if (format === 'text_link') {
    const ad = ads[0];
    return (
      <div className="flex items-center justify-center gap-2 border-b border-brand-100 bg-brand-50/70 px-4 py-1.5 text-xs text-ink">
        <SponsoredBadge label={ad.disclosure_label} />
        <AdDestinationLink
          href={ad.destination_url}
          onClick={() => onAdClick(ad)}
          className="font-medium text-brand-700 hover:underline"
        >
          {ad.title} {ad.cta_label ? `— ${ad.cta_label}` : ''}
        </AdDestinationLink>
      </div>
    );
  }

  if (format === 'strip') {
    const ad = ads[0];
    return (
      <div className="overflow-hidden rounded-xl border border-amber-200/80 bg-gradient-to-r from-amber-50/70 via-white to-brand-50/60 p-3 shadow-xs">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            <SponsoredBadge label={ad.disclosure_label} />
            <div className="min-w-0">
              <div className="truncate text-sm font-semibold text-ink">{ad.title}</div>
              {ad.subtitle && <div className="truncate text-xs text-ink-subtle">{ad.subtitle}</div>}
            </div>
          </div>
          <AdDestinationLink
            href={ad.destination_url}
            onClick={() => onAdClick(ad)}
            className="inline-flex shrink-0 items-center gap-1 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700"
          >
            {ad.cta_label || 'Explore'}
            <ArrowUpRight className="h-3.5 w-3.5" />
          </AdDestinationLink>
        </div>
      </div>
    );
  }

  if (format === 'carousel') {
    return (
      <div className="space-y-2">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {ads.map((ad) => (
            <AdCardItem key={ad.creative_public_id} ad={ad} onAdClick={onAdClick} />
          ))}
        </div>
      </div>
    );
  }

  if (format === 'native_listing') {
    const ad = ads[0];
    return (
      <article className="flex flex-col overflow-hidden rounded-xl border border-amber-200 bg-amber-50/20 shadow-sm sm:flex-row">
        {ad.image_url && (
          <div className="relative h-36 w-full shrink-0 sm:h-auto sm:w-44">
            <FallbackImage src={ad.image_url} alt={ad.alt_text} fallbackLabel={ad.title} />
          </div>
        )}
        <div className="flex flex-1 flex-col justify-between p-4">
          <div>
            <SponsoredBadge label={ad.disclosure_label} />
            <h3 className="mt-1.5 text-base font-bold text-ink">{ad.title}</h3>
            {ad.subtitle && <p className="mt-1 text-xs text-ink-subtle">{ad.subtitle}</p>}
          </div>
          <div className="mt-3">
            <AdDestinationLink
              href={ad.destination_url}
              onClick={() => onAdClick(ad)}
              className="inline-flex items-center gap-1 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700"
            >
              {ad.cta_label || 'View Offer'}
              <ArrowUpRight className="h-3.5 w-3.5" />
            </AdDestinationLink>
          </div>
        </div>
      </article>
    );
  }

  if (format === 'card') {
    return (
      <div className={ads.length > 1 ? 'grid gap-4 sm:grid-cols-2 lg:grid-cols-3' : ''}>
        {ads.map((ad) => (
          <AdCardItem key={ad.creative_public_id} ad={ad} onAdClick={onAdClick} />
        ))}
      </div>
    );
  }

  // Default: 'banner'
  const ad = ads[0];
  return (
    <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
      <div className="grid items-center md:grid-cols-12">
        {ad.image_url && (
          <div className="relative h-44 md:col-span-5 md:h-48">
            <FallbackImage src={ad.image_url} alt={ad.alt_text} fallbackLabel={ad.title} />
          </div>
        )}
        <div className={ad.image_url ? 'p-5 md:col-span-7' : 'p-5 md:col-span-12'}>
          <SponsoredBadge label={ad.disclosure_label} />
          <h3 className="mt-2 text-lg font-bold text-ink">{ad.title}</h3>
          {ad.subtitle && <p className="mt-1 text-sm text-ink-subtle">{ad.subtitle}</p>}
          <div className="mt-4">
            <AdDestinationLink
              href={ad.destination_url}
              onClick={() => onAdClick(ad)}
              className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-700"
            >
              {ad.cta_label || 'Learn More'}
              <ArrowUpRight className="h-3.5 w-3.5" />
            </AdDestinationLink>
          </div>
        </div>
      </div>
    </div>
  );
}

function AdCardItem({
  ad,
  onAdClick,
}: {
  ad: DeliveredAdItem;
  onAdClick: (ad: DeliveredAdItem) => void;
}) {
  return (
    <div className="flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xs transition-shadow hover:shadow-md">
      {ad.image_url && (
        <div className="relative h-36 w-full overflow-hidden bg-gray-100">
          <FallbackImage src={ad.image_url} alt={ad.alt_text} fallbackLabel={ad.title} />
        </div>
      )}
      <div className="flex flex-1 flex-col justify-between p-4">
        <div>
          <SponsoredBadge label={ad.disclosure_label} />
          <h4 className="mt-1.5 text-sm font-bold text-ink">{ad.title}</h4>
          {ad.subtitle && <p className="mt-1 line-clamp-2 text-xs text-ink-subtle">{ad.subtitle}</p>}
        </div>
        <div className="mt-3">
          <AdDestinationLink
            href={ad.destination_url}
            onClick={() => onAdClick(ad)}
            className="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 hover:underline"
          >
            {ad.cta_label || 'Learn More'}
            <ArrowUpRight className="h-3.5 w-3.5" />
          </AdDestinationLink>
        </div>
      </div>
    </div>
  );
}
