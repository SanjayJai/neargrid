'use client';

import type { AdminDailyTrendPointDto } from '@/modules/admin/lib/admin-analytics-types';

type MetricKey = 'businesses' | 'users' | 'enquiries' | 'reviews' | 'searches';

interface AdminTrendBarChartProps {
  points: AdminDailyTrendPointDto[];
  metric: MetricKey;
  title: string;
}

export function AdminTrendBarChart({ points, metric, title }: AdminTrendBarChartProps) {
  const maxVal = Math.max(1, ...points.map((p) => p[metric]));
  const totalInPeriod = points.reduce((acc, p) => acc + p[metric], 0);

  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
      <div className="mb-4 flex items-center justify-between gap-2">
        <div>
          <h3 className="text-sm font-bold text-slate-950 dark:text-white">{title}</h3>
          <p className="text-xs text-slate-500 dark:text-slate-400">
            Total in window: <strong className="text-slate-900 dark:text-white">{totalInPeriod}</strong>
          </p>
        </div>
        <span className="text-[11px] font-medium text-slate-400">
          {points[0]?.date ?? ''} – {points[points.length - 1]?.date ?? ''}
        </span>
      </div>

      {points.length === 0 ? (
        <p className="py-8 text-center text-xs text-slate-500">No trend days in range.</p>
      ) : (
        <div
          className="flex h-36 items-end gap-1 border-b border-slate-200 pb-1 dark:border-slate-800"
          role="img"
          aria-label={`${title} daily trend bar chart`}
        >
          {points.map((point) => {
            const val = point[metric];
            const heightPct = val > 0 ? Math.max(8, Math.round((val / maxVal) * 100)) : 2;
            return (
              <div
                key={point.date}
                title={`${point.date}: ${val}`}
                className="group relative flex flex-1 flex-col items-center justify-end h-full"
              >
                <div
                  style={{ height: `${heightPct}%` }}
                  className={`w-full rounded-t transition-colors ${
                    val > 0
                      ? 'bg-brand-600 group-hover:bg-brand-500 dark:bg-brand-500'
                      : 'bg-slate-200 dark:bg-slate-800'
                  }`}
                />
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
