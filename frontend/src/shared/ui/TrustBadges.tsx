import { CheckCircle2, Clock, Megaphone, ShieldCheck } from 'lucide-react';

export function VerifiedBadge() {
  return (
    <span className="inline-flex items-center gap-1 rounded-md border border-sky-200 bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-sky-800">
      <CheckCircle2 className="h-3 w-3 text-sky-600" aria-hidden="true" />
      Verified
    </span>
  );
}

export function TrustedBadge() {
  return (
    <span className="inline-flex items-center gap-1 rounded-md border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-800">
      <ShieldCheck className="h-3 w-3 text-indigo-600" aria-hidden="true" />
      Trusted
    </span>
  );
}

export function SponsoredBadge({ label = 'Sponsored' }: { label?: string | null }) {
  return (
    <span
      title="Paid placement relevant to your search"
      className="inline-flex items-center gap-1 rounded-md border border-amber-300 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-amber-900"
    >
      <Megaphone className="h-3 w-3 text-amber-700" aria-hidden="true" />
      {label || 'Sponsored'}
    </span>
  );
}

export function OpenStatusBadge({ isOpenNow }: { isOpenNow: boolean }) {
  return (
    <span
      className={`inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-semibold ${
        isOpenNow
          ? 'bg-trust-50 text-trust-700'
          : 'bg-gray-100 text-ink-subtle'
      }`}
    >
      <Clock className="h-3 w-3" aria-hidden="true" />
      {isOpenNow ? 'Open Now' : 'Closed'}
    </span>
  );
}
