'use client';

export type AdminBadgeTone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

interface AdminStatusBadgeProps {
  label: string;
  tone?: AdminBadgeTone;
}

const TONE_CLASSES: Record<AdminBadgeTone, string> = {
  success: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800',
  warning: 'bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800',
  danger: 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:border-rose-800',
  info: 'bg-sky-50 text-sky-700 border-sky-200 dark:bg-sky-950/60 dark:text-sky-300 dark:border-sky-800',
  neutral: 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700',
};

export function AdminStatusBadge({ label, tone = 'neutral' }: AdminStatusBadgeProps) {
  return (
    <span
      className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${TONE_CLASSES[tone]}`}
    >
      {label}
    </span>
  );
}
