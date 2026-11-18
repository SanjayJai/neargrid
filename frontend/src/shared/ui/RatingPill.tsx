import { Star } from 'lucide-react';

interface RatingPillProps {
  ratingAvg: number;
  reviewCount: number;
  size?: 'sm' | 'md';
}

/**
 * Directory-style color-coded rating badge reflecting verified aggregate rating tiers.
 */
export function RatingPill({ ratingAvg, reviewCount, size = 'sm' }: RatingPillProps) {
  if (reviewCount <= 0 && ratingAvg <= 0) {
    return (
      <span className="inline-flex items-center gap-1 rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs font-medium text-ink-subtle">
        New listing
      </span>
    );
  }

  const tierClass =
    ratingAvg >= 4.0
      ? 'bg-trust-700 text-white'
      : ratingAvg >= 3.0
        ? 'bg-amber-600 text-white'
        : 'bg-rose-600 text-white';

  const padClass = size === 'md' ? 'px-2.5 py-1 text-sm' : 'px-2 py-0.5 text-xs';

  return (
    <span
      className="inline-flex items-center gap-1.5"
      aria-label={`Rated ${ratingAvg.toFixed(1)} out of 5 based on ${reviewCount} ratings`}
    >
      <span
        className={`inline-flex items-center gap-1 rounded-md font-bold tabular-nums ${tierClass} ${padClass}`}
      >
        <span>{ratingAvg.toFixed(1)}</span>
        <Star className={size === 'md' ? 'h-3.5 w-3.5 fill-current' : 'h-3 w-3 fill-current'} aria-hidden="true" />
      </span>
      <span className="text-xs font-medium text-ink-muted">
        {reviewCount.toLocaleString('en-IN')} {reviewCount === 1 ? 'Rating' : 'Ratings'}
      </span>
    </span>
  );
}
