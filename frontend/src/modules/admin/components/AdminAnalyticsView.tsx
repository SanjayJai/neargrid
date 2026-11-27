'use client';

import { useState } from 'react';
import {
  Activity,
  AlertCircle,
  Building2,
  Calendar,
  FolderTree,
  Globe,
  MessageSquare,
  Search,
  ShieldCheck,
  Star,
  Users,
} from 'lucide-react';
import type { AdminAnalyticsDto } from '@/modules/admin/lib/admin-analytics-types';
import { AdminBreadcrumbs } from '@/modules/admin/ui/AdminBreadcrumbs';
import { AdminDataTable, type AdminTableColumn } from '@/modules/admin/ui/AdminDataTable';
import { AdminStatCard } from '@/modules/admin/ui/AdminStatCard';
import { AdminStatusBadge } from '@/modules/admin/ui/AdminStatusBadge';
import { AdminTimeline } from '@/modules/admin/ui/AdminTimeline';
import { AdminTrendBarChart } from '@/modules/admin/ui/AdminTrendBarChart';
import { bffFetch } from '@/shared/api/bff-client';

interface AdminAnalyticsViewProps {
  initialData: AdminAnalyticsDto;
}

export function AdminAnalyticsView({ initialData }: AdminAnalyticsViewProps) {
  const [data, setData] = useState<AdminAnalyticsDto>(initialData);
  const [range, setRange] = useState<'7d' | '30d' | '90d' | 'custom'>(initialData.period.range);
  const [fromDate, setFromDate] = useState(initialData.period.from);
  const [toDate, setToDate] = useState(initialData.period.to);
  const [trendMetric, setTrendMetric] = useState<'enquiries' | 'businesses' | 'users' | 'reviews' | 'searches'>('enquiries');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function loadRange(
    nextRange: '7d' | '30d' | '90d' | 'custom',
    customFrom?: string,
    customTo?: string
  ) {
    setLoading(true);
    setError(null);
    const qs = new URLSearchParams({ range: nextRange });
    if (nextRange === 'custom' && customFrom && customTo) {
      qs.set('from', customFrom);
      qs.set('to', customTo);
    }

    try {
      const res = await bffFetch<AdminAnalyticsDto>(`/api/admin/analytics?${qs.toString()}`);
      if (!res.ok || !res.payload || !('data' in res.payload)) {
        const msg =
          res.payload && 'error' in res.payload
            ? res.payload.error.message
            : 'Unable to load analytics for the selected range.';
        setError(msg);
        return;
      }
      setData(res.payload.data);
      setRange(res.payload.data.period.range);
      setFromDate(res.payload.data.period.from);
      setToDate(res.payload.data.period.to);
    } catch {
      setError('Network error while refreshing platform analytics.');
    } finally {
      setLoading(false);
    }
  }

  function formatDelta(deltaCount: number, deltaPercent: number | null) {
    const sign = deltaCount > 0 ? '+' : '';
    const pct = deltaPercent !== null ? ` (${sign}${deltaPercent}%)` : '';
    return {
      label: `${sign}${deltaCount}${pct} vs prev`,
      positive: deltaCount >= 0,
    };
  }

  const { summary } = data;

  const queryCols: AdminTableColumn<AdminAnalyticsDto['top_queries'][number]>[] = [
    {
      key: 'query',
      label: 'Search Query',
      render: (r) => <span className="font-semibold text-slate-900 dark:text-white">{r.query}</span>,
    },
    {
      key: 'search_count',
      label: 'Searches',
      align: 'right',
      render: (r) => <span>{r.search_count}</span>,
    },
    {
      key: 'avg_results',
      label: 'Avg Results',
      align: 'right',
      render: (r) => <span>{r.avg_results}</span>,
    },
  ];

  const cityCols: AdminTableColumn<AdminAnalyticsDto['top_cities'][number]>[] = [
    {
      key: 'city_slug',
      label: 'City',
      render: (r) => <span className="font-semibold capitalize text-slate-900 dark:text-white">{r.city_slug}</span>,
    },
    {
      key: 'search_count',
      label: 'Searches',
      align: 'right',
      render: (r) => <span>{r.search_count}</span>,
    },
  ];

  return (
    <div className="space-y-6">
      {/* Header + Date Range Filter */}
      <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
        <div className="space-y-1">
          <AdminBreadcrumbs
            items={[
              { label: 'Control Centre', href: '/admin' },
              { label: 'Platform Analytics' },
            ]}
          />
          <h1 className="text-2xl font-extrabold tracking-tight text-slate-950 dark:text-white">
            Platform Analytics
          </h1>
          <p className="text-xs text-slate-500 dark:text-slate-400">
            Comparing {data.period.from} – {data.period.to} against {data.period.previous_from} – {data.period.previous_to}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {(['7d', '30d', '90d'] as const).map((preset) => (
            <button
              key={preset}
              type="button"
              disabled={loading}
              onClick={() => loadRange(preset)}
              className={`rounded-xl px-3 py-1.5 text-xs font-semibold transition-colors ${
                range === preset
                  ? 'bg-brand-600 text-white'
                  : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200'
              }`}
            >
              Last {preset}
            </button>
          ))}

          <div className="flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-2.5 py-1 dark:border-slate-700 dark:bg-slate-900">
            <Calendar className="h-3.5 w-3.5 text-slate-400" aria-hidden="true" />
            <input
              type="date"
              aria-label="From date"
              value={fromDate}
              onChange={(e) => setFromDate(e.target.value)}
              className="bg-transparent text-xs text-slate-800 focus:outline-none dark:text-white"
            />
            <span className="text-xs text-slate-400">–</span>
            <input
              type="date"
              aria-label="To date"
              value={toDate}
              onChange={(e) => setToDate(e.target.value)}
              className="bg-transparent text-xs text-slate-800 focus:outline-none dark:text-white"
            />
            <button
              type="button"
              disabled={loading}
              onClick={() => loadRange('custom', fromDate, toDate)}
              className="rounded-lg bg-slate-900 px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-slate-800 dark:bg-brand-600"
            >
              Apply
            </button>
          </div>
        </div>
      </div>

      {error && (
        <div className="flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-semibold text-rose-800">
          <AlertCircle className="h-4 w-4 shrink-0" aria-hidden="true" />
          <span>{error}</span>
        </div>
      )}

      {/* Primary KPI Grid */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <AdminStatCard
          label="New Businesses"
          value={summary.businesses.comparison.current}
          delta={formatDelta(summary.businesses.comparison.delta_count, summary.businesses.comparison.delta_percent)}
          sublabel={`${summary.businesses.total} total (${summary.businesses.verified} verified)`}
          icon={<Building2 className="h-5 w-5" aria-hidden="true" />}
        />
        <AdminStatCard
          label="New Users"
          value={summary.users.comparison.current}
          delta={formatDelta(summary.users.comparison.delta_count, summary.users.comparison.delta_percent)}
          sublabel={`${summary.users.total} total (${summary.users.suspended} suspended)`}
          icon={<Users className="h-5 w-5" aria-hidden="true" />}
        />
        <AdminStatCard
          label="New Enquiries"
          value={summary.enquiries.comparison.current}
          delta={formatDelta(summary.enquiries.comparison.delta_count, summary.enquiries.comparison.delta_percent)}
          sublabel={`${summary.enquiries.dispatch_rate_percent}% dispatched`}
          icon={<MessageSquare className="h-5 w-5" aria-hidden="true" />}
        />
        <AdminStatCard
          label="Search Queries"
          value={summary.search.comparison.current}
          delta={formatDelta(summary.search.comparison.delta_count, summary.search.comparison.delta_percent)}
          sublabel={`${summary.search.avg_duration_ms}ms avg • ${summary.search.zero_result_searches} zero-result`}
          icon={<Search className="h-5 w-5" aria-hidden="true" />}
        />
        <AdminStatCard
          label="Reviews"
          value={summary.reviews.total}
          delta={formatDelta(summary.reviews.comparison.delta_count, summary.reviews.comparison.delta_percent)}
          sublabel={
            summary.reviews.avg_rating !== null
              ? `Avg ${summary.reviews.avg_rating} ★ • ${summary.reviews.open_reports} open reports`
              : `${summary.reviews.pending_moderation} pending moderation`
          }
          icon={<Star className="h-5 w-5" aria-hidden="true" />}
        />
        <AdminStatCard
          label="KYC & Claims Queue"
          value={summary.kyc_and_claims.pending_verifications}
          sublabel={`${summary.kyc_and_claims.pending_claims} pending claims`}
          icon={<ShieldCheck className="h-5 w-5" aria-hidden="true" />}
        />
        <AdminStatCard
          label="Domains & SSL"
          value={`${summary.domains.active} / ${summary.domains.total}`}
          sublabel={`${summary.domains.verified} verified • ${summary.domains.ssl_active} SSL active`}
          icon={<Globe className="h-5 w-5" aria-hidden="true" />}
        />
        <AdminStatCard
          label="Categories"
          value={`${summary.categories.published} / ${summary.categories.total}`}
          sublabel={`${summary.categories.collections} curated collections`}
          icon={<FolderTree className="h-5 w-5" aria-hidden="true" />}
        />
      </div>

      {/* Daily Trend Chart */}
      <div className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="flex items-center gap-1.5 text-sm font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            <Activity className="h-4 w-4" aria-hidden="true" />
            Daily Platform Trends
          </h2>
          <div className="flex flex-wrap gap-1.5">
            {(['enquiries', 'businesses', 'users', 'reviews', 'searches'] as const).map((m) => (
              <button
                key={m}
                type="button"
                onClick={() => setTrendMetric(m)}
                className={`rounded-lg px-2.5 py-1 text-xs font-semibold capitalize ${
                  trendMetric === m
                    ? 'bg-slate-900 text-white dark:bg-brand-600'
                    : 'bg-white text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700'
                }`}
              >
                {m}
              </button>
            ))}
          </div>
        </div>
        <AdminTrendBarChart
          points={data.daily_trends}
          metric={trendMetric}
          title={`Daily ${trendMetric} (${data.period.days} days)`}
        />
      </div>

      {/* Search Breakdown + Safe Recent Activity */}
      <div className="grid grid-cols-1 gap-6 xl:grid-cols-12">
        <div className="space-y-3 xl:col-span-5">
          <h2 className="text-sm font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Top Search Queries
          </h2>
          <AdminDataTable
            columns={queryCols}
            rows={data.top_queries}
            rowKey={(r) => r.query}
            emptyTitle="No search queries logged"
            emptyDescription="Real search logs will appear here as searches occur."
          />
        </div>

        <div className="space-y-3 xl:col-span-3">
          <h2 className="text-sm font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Top Searched Cities
          </h2>
          <AdminDataTable
            columns={cityCols}
            rows={data.top_cities}
            rowKey={(r) => r.city_slug}
            emptyTitle="No city search data"
            emptyDescription="City-scoped search counts will appear here."
          />
        </div>

        <div className="space-y-3 xl:col-span-4">
          <h2 className="text-sm font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Recent Platform Activity
          </h2>
          <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <AdminTimeline
              items={data.recent_activity.map((log) => ({
                id: String(log.id),
                title: log.action,
                description: `${log.auditable_type} #${log.auditable_id}`,
                actor: log.actor_role,
                timestamp: log.created_at ?? 'Recently',
                badge: log.actor_role,
                tone: 'info',
              }))}
            />
          </div>
        </div>
      </div>

      {/* Unavailable External / Later-Milestone Analytics (Distinct from 0 values) */}
      <div className="space-y-3">
        <h2 className="text-sm font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
          External &amp; Upcoming Analytics Integrations
        </h2>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          {data.unavailable_integrations.map((item) => (
            <div
              key={item.key}
              className="rounded-2xl border border-dashed border-slate-300 bg-white/70 p-4 dark:border-slate-700 dark:bg-slate-900/60"
            >
              <div className="flex items-start justify-between gap-2">
                <p className="text-xs font-bold text-slate-900 dark:text-white">
                  {item.label}
                </p>
                <AdminStatusBadge
                  label={item.status === 'not_configured' ? 'Not Configured' : 'Upcoming'}
                  tone="warning"
                />
              </div>
              <p className="mt-1.5 text-xs text-slate-500 dark:text-slate-400">
                {item.reason}
              </p>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
