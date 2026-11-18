import Link from 'next/link';
import { ArrowUpRight, CheckCircle2, Clock, MapPin, Phone, ShieldCheck, Star } from 'lucide-react';
import type { DemoBusiness } from '@/shared/data/demo-directory';
import { Badge } from '@/shared/ui/Badge';

interface BusinessCardProps {
  business: DemoBusiness;
}

export function BusinessCard({ business }: BusinessCardProps) {
  const profileHref = `/${business.citySlug}/business/${business.slug}`;

  return (
    <article className="group flex flex-col justify-between rounded-2xl border border-zinc-200 bg-white p-5 transition-all hover:border-zinc-400 hover:shadow-sm">
      <div>
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <div className="flex flex-wrap items-center gap-1.5">
            {business.isSponsored && <Badge variant="sponsored">Sponsored</Badge>}
            {business.isVerified && (
              <Badge variant="verified">
                <CheckCircle2 className="h-3 w-3" aria-hidden="true" />
                Verified
              </Badge>
            )}
            {business.isTrusted && (
              <Badge variant="trusted">
                <ShieldCheck className="h-3 w-3" aria-hidden="true" />
                Trusted
              </Badge>
            )}
            <Badge variant="demo">Demo Data</Badge>
          </div>

          <div
            className="inline-flex items-center gap-1 rounded-lg bg-zinc-950 px-2.5 py-1 text-xs font-semibold text-white"
            aria-label={`Rated ${business.ratingAvg} out of 5 from ${business.reviewCount} reviews`}
          >
            <Star className="h-3 w-3 fill-current" aria-hidden="true" />
            <span>{business.ratingAvg.toFixed(1)}</span>
            <span className="font-normal text-zinc-300">({business.reviewCount})</span>
          </div>
        </div>

        <Link href={profileHref} className="block">
          <h3 className="text-lg font-semibold tracking-tight text-zinc-950 group-hover:underline">
            {business.name}
          </h3>
        </Link>
        <p className="mt-1 text-sm text-zinc-600">{business.tagline}</p>

        <div className="mt-4 space-y-2 text-sm text-zinc-600">
          <p className="flex items-start gap-2">
            <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-zinc-400" aria-hidden="true" />
            <span>
              {business.localityName}, {business.cityName} ·{' '}
              <span className="font-medium text-zinc-900">{business.distanceKm.toFixed(1)} km</span>
            </span>
          </p>
          <p className="flex items-center gap-2">
            <Clock className="h-4 w-4 shrink-0 text-zinc-400" aria-hidden="true" />
            <span className={business.isOpenNow ? 'font-medium text-emerald-700' : 'text-zinc-500'}>
              {business.hoursSummary}
            </span>
          </p>
        </div>

        <div className="mt-4 flex flex-wrap gap-1.5">
          <Badge variant="neutral">{business.categoryName}</Badge>
          {business.amenities.slice(0, 2).map((amenity) => (
            <Badge key={amenity} variant="neutral">
              {amenity}
            </Badge>
          ))}
        </div>
      </div>

      <div className="mt-5 flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 pt-4">
        <span className="inline-flex items-center gap-1.5 text-xs font-medium text-zinc-500">
          <Phone className="h-3.5 w-3.5" aria-hidden="true" />
          {business.phoneMasked}
        </span>

        <div className="flex items-center gap-2">
          <Link
            href={`${profileHref}#enquire`}
            className="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-900 transition-colors hover:bg-zinc-100"
          >
            Enquire
          </Link>
          <Link
            href={profileHref}
            className="inline-flex items-center gap-1 rounded-lg bg-zinc-950 px-3.5 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-zinc-800"
          >
            View Profile
            <ArrowUpRight className="h-3.5 w-3.5" aria-hidden="true" />
          </Link>
        </div>
      </div>
    </article>
  );
}
