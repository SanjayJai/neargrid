'use client';

import type { ReactNode } from 'react';
import Link from 'next/link';
import { ArrowUpRight } from 'lucide-react';

interface AdminStatCardProps {
  label: string;
  value: string | number;
  sublabel?: string;
  delta?: { label: string; positive?: boolean };
  icon?: ReactNode;
  href?: string;
}

export function AdminStatCard({
  label,
  value,
  sublabel,
  delta,
  icon,
  href,
}: AdminStatCardProps) {
  const cardBody = (
    <div className="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition-all hover:border-brand-300 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-brand-700">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
            {label}
          </p>
          <p className="mt-2 text-2xl font-extrabold tracking-tight text-slate-950 dark:text-white">
            {value}
          </p>
        </div>
        {icon && (
          <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-950/60 dark:text-brand-400">
            {icon}
          </div>
        )}
      </div>

      {(sublabel || delta || href) && (
        <div className="mt-3 flex items-center justify-between gap-2 text-xs">
          <div className="flex items-center gap-2">
            {delta && (
              <span
                className={`rounded-md px-1.5 py-0.5 font-semibold ${
                  delta.positive
                    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
                    : 'bg-amber-50 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300'
                }`}
              >
                {delta.label}
              </span>
            )}
            {sublabel && (
              <span className="text-slate-500 dark:text-slate-400">{sublabel}</span>
            )}
          </div>
          {href && (
            <ArrowUpRight
              className="h-3.5 w-3.5 text-slate-400 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:text-brand-600"
              aria-hidden="true"
            />
          )}
        </div>
      )}
    </div>
  );

  if (href) {
    return <Link href={href}>{cardBody}</Link>;
  }

  return cardBody;
}
