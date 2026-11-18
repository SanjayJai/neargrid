import type { ReactNode } from 'react';

type BadgeVariant = 'neutral' | 'verified' | 'trusted' | 'sponsored' | 'demo' | 'open';

const VARIANT_CLASSES: Record<BadgeVariant, string> = {
  neutral: 'border-zinc-200 bg-zinc-50 text-zinc-700',
  verified: 'border-emerald-200 bg-emerald-50 text-emerald-800',
  trusted: 'border-zinc-900 bg-zinc-900 text-white',
  sponsored: 'border-amber-200 bg-amber-50 text-amber-900',
  demo: 'border-zinc-300 bg-white text-zinc-600 border-dashed',
  open: 'border-emerald-200 bg-white text-emerald-700',
};

export function Badge({
  variant = 'neutral',
  children,
}: {
  variant?: BadgeVariant;
  children: ReactNode;
}) {
  return (
    <span
      className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium tracking-tight ${VARIANT_CLASSES[variant]}`}
    >
      {children}
    </span>
  );
}
