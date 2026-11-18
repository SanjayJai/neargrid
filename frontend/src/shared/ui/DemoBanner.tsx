import { Info } from 'lucide-react';

interface DemoBannerProps {
  title?: string;
  description: string;
}

export function DemoBanner({
  title = 'Visual Foundation — Demo Data',
  description,
}: DemoBannerProps) {
  return (
    <div
      role="status"
      aria-live="polite"
      className="flex items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-700"
    >
      <Info className="mt-0.5 h-4 w-4 shrink-0 text-zinc-900" aria-hidden="true" />
      <p>
        <span className="font-semibold text-zinc-950">{title}: </span>
        {description}
      </p>
    </div>
  );
}
