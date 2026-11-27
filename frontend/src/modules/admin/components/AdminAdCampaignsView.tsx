'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Plus } from 'lucide-react';
import { adminFetch, type AdminPaginatedMeta } from '@/modules/admin/lib/admin-api';
import { AdminDataTable, type AdminTableColumn } from '@/modules/admin/ui/AdminDataTable';
import { AdminStatusBadge } from '@/modules/admin/ui/AdminStatusBadge';

export interface AdCampaignSummary {
  public_id: string;
  name: string;
  description: string | null;
  campaign_type: string;
  status: string;
  priority: number;
  starts_at: string | null;
  ends_at: string | null;
  daily_impression_cap: number | null;
  total_impression_cap: number | null;
  rejection_reason: string | null;
  organization: { public_id: string; name: string; slug: string } | null;
  creatives_count: number;
  placements_count: number;
  businesses_count: number;
}

export function AdminAdCampaignsView() {
  const router = useRouter();
  const [rows, setRows] = useState<AdCampaignSummary[]>([]);
  const [meta, setMeta] = useState<AdminPaginatedMeta>({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [typeFilter, setTypeFilter] = useState('');
  const [sortBy, setSortBy] = useState('created_at');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');
  const [page, setPage] = useState(1);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const qs = new URLSearchParams({
        page: String(page),
        per_page: '20',
        sort_by: sortBy,
        sort_dir: sortDir,
      });
      if (search.trim()) qs.set('q', search.trim());
      if (statusFilter) qs.set('status', statusFilter);
      if (typeFilter) qs.set('campaign_type', typeFilter);

      const res = await adminFetch<AdCampaignSummary[]>(`advertising/campaigns?${qs.toString()}`);
      setRows(Array.isArray(res.data) ? res.data : []);
      if (res.meta) setMeta(res.meta);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load ad campaigns.');
    } finally {
      setLoading(false);
    }
  }, [page, search, statusFilter, typeFilter, sortBy, sortDir]);

  useEffect(() => {
    void load();
  }, [load]);

  const columns: AdminTableColumn<AdCampaignSummary>[] = [
    {
      key: 'name',
      label: 'Campaign',
      sortable: true,
      render: (r) => (
        <div>
          <div className="font-semibold text-slate-900 dark:text-white">{r.name}</div>
          <div className="text-xs text-slate-500">
            {r.campaign_type} · {r.organization?.name ?? 'Platform House'}
          </div>
        </div>
      ),
    },
    {
      key: 'status',
      label: 'Status',
      render: (r) => <AdminStatusBadge status={r.status} />,
    },
    {
      key: 'priority',
      label: 'Priority',
      sortable: true,
      align: 'right',
      render: (r) => <span className="font-mono text-xs">{r.priority}</span>,
    },
    {
      key: 'links',
      label: 'Slots / Creatives / Biz',
      align: 'right',
      render: (r) => (
        <span className="font-mono text-xs text-slate-600 dark:text-slate-300">
          {r.placements_count} · {r.creatives_count} · {r.businesses_count}
        </span>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
            Advertising Campaigns
          </h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Manage campaign lifecycle, approvals, schedules, placement slots, and advertiser businesses.
          </p>
        </div>
        <Link
          href="/admin/advertising/campaigns/create"
          className="inline-flex items-center gap-1.5 rounded-xl bg-slate-950 px-3.5 py-2 text-xs font-semibold text-white hover:bg-slate-800 dark:bg-white dark:text-slate-950"
        >
          <Plus className="h-4 w-4" />
          New Campaign
        </Link>
      </div>

      <AdminDataTable
        columns={columns}
        rows={rows}
        rowKey={(r) => r.public_id}
        loading={loading}
        error={error}
        onRetry={() => void load()}
        searchValue={search}
        searchPlaceholder="Search campaign name or description..."
        onSearchChange={(val) => {
          setSearch(val);
          setPage(1);
        }}
        sortBy={sortBy}
        sortDir={sortDir}
        onSortChange={(key) => {
          if (sortBy === key) {
            setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
          } else {
            setSortBy(key);
            setSortDir('asc');
          }
          setPage(1);
        }}
        page={meta.current_page}
        lastPage={meta.last_page}
        total={meta.total}
        onPageChange={setPage}
        onRowClick={(r) => router.push(`/admin/advertising/campaigns/${r.public_id}`)}
        toolbarExtra={
          <div className="flex flex-wrap gap-2">
            <select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value);
                setPage(1);
              }}
              aria-label="Filter campaign status"
              className="h-9 rounded-lg border border-slate-200 bg-white px-2.5 text-xs dark:border-slate-700 dark:bg-slate-800"
            >
              <option value="">All statuses</option>
              <option value="draft">Draft</option>
              <option value="pending_approval">Pending Approval</option>
              <option value="approved">Approved</option>
              <option value="scheduled">Scheduled</option>
              <option value="active">Active</option>
              <option value="paused">Paused</option>
              <option value="rejected">Rejected</option>
              <option value="completed">Completed</option>
              <option value="archived">Archived</option>
            </select>
            <select
              value={typeFilter}
              onChange={(e) => {
                setTypeFilter(e.target.value);
                setPage(1);
              }}
              aria-label="Filter campaign type"
              className="h-9 rounded-lg border border-slate-200 bg-white px-2.5 text-xs dark:border-slate-700 dark:bg-slate-800"
            >
              <option value="">All types</option>
              <option value="sponsored_banner">Sponsored Banner</option>
              <option value="featured_listing">Featured Listing</option>
              <option value="category_spotlight">Category Spotlight</option>
              <option value="city_takeover">City Takeover</option>
              <option value="house_promo">House Promo</option>
            </select>
          </div>
        }
      />
    </div>
  );
}
