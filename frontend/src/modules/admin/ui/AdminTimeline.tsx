'use client';

import { Activity } from 'lucide-react';
import { AdminStatusBadge, type AdminBadgeTone } from './AdminStatusBadge';

export interface AdminTimelineItem {
  id: string;
  title: string;
  actor?: string;
  timestamp: string;
  description?: string;
  tone?: AdminBadgeTone;
  badge?: string;
}

interface AdminTimelineProps {
  items: AdminTimelineItem[];
  emptyMessage?: string;
}

export function AdminTimeline({
  items,
  emptyMessage = 'No activity recorded yet.',
}: AdminTimelineProps) {
  if (items.length === 0) {
    return (
      <p className="py-6 text-center text-sm text-slate-500 dark:text-slate-400">
        {emptyMessage}
      </p>
    );
  }

  return (
    <ol className="relative space-y-4 border-l border-slate-200 pl-5 dark:border-slate-800">
      {items.map((item) => (
        <li key={item.id} className="relative">
          <span className="absolute -left-[27px] top-1 flex h-4 w-4 items-center justify-center rounded-full border border-slate-200 bg-white text-brand-600 dark:border-slate-700 dark:bg-slate-900 dark:text-brand-400">
            <Activity className="h-2.5 w-2.5" aria-hidden="true" />
          </span>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-xs font-bold text-slate-900 dark:text-white">
              {item.title}
            </p>
            {item.badge && (
              <AdminStatusBadge label={item.badge} tone={item.tone ?? 'neutral'} />
            )}
          </div>
          {item.description && (
            <p className="mt-0.5 text-xs text-slate-600 dark:text-slate-300">
              {item.description}
            </p>
          )}
          <p className="mt-1 text-[11px] text-slate-400 dark:text-slate-500">
            {item.actor ? `${item.actor} • ` : ''}
            {item.timestamp}
          </p>
        </li>
      ))}
    </ol>
  );
}
