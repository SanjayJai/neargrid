import Link from 'next/link';

interface NearGridLogoProps {
  href?: string;
  variant?: 'customer' | 'business';
  compact?: boolean;
}

/**
 * Original NearGrid geometric brand mark and wordmark.
 */
export function NearGridLogo({
  href = '/',
  variant = 'customer',
  compact = false,
}: NearGridLogoProps) {
  return (
    <Link
      href={href}
      className="inline-flex items-center gap-2.5 text-ink transition-opacity hover:opacity-90"
      aria-label={variant === 'business' ? 'NearGrid Business home' : 'NearGrid home'}
    >
      <span
        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-600 text-white shadow-sm"
        aria-hidden="true"
      >
        <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none">
          <rect x="3" y="3" width="7.5" height="7.5" rx="1.8" fill="currentColor" />
          <rect x="13.5" y="3" width="7.5" height="7.5" rx="1.8" fill="#FB923C" />
          <rect x="3" y="13.5" width="7.5" height="7.5" rx="1.8" fill="currentColor" fillOpacity="0.85" />
          <circle cx="17.25" cy="17.25" r="3.75" fill="currentColor" />
        </svg>
      </span>

      {!compact && (
        <span className="leading-tight">
          <span className="block text-lg font-bold tracking-tight text-brand-700">
            Near<span className="text-signal-600">Grid</span>
          </span>
          {variant === 'business' && (
            <span className="block text-[11px] font-medium text-ink-subtle">
              Business Portal
            </span>
          )}
        </span>
      )}
    </Link>
  );
}
