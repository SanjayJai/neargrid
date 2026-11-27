'use client';

import { useCallback, useEffect, useState } from 'react';
import { ShieldCheck, Sparkles } from 'lucide-react';
import { adminFetch, type AdminPaginatedMeta } from '@/modules/admin/lib/admin-api';
import { AdminConfirmDialog } from '@/modules/admin/ui/AdminConfirmDialog';
import { AdminDataTable, type AdminTableColumn } from '@/modules/admin/ui/AdminDataTable';
import { AdminDrawer } from '@/modules/admin/ui/AdminDrawer';
import { AdminStatusBadge } from '@/modules/admin/ui/AdminStatusBadge';

interface AdminBusinessRow {
  public_id: string;
  name: string;
  slug: string;
  status: string;
  is_claimed: boolean;
  is_verified: boolean;
  is_trusted: boolean;
  branch_type: string | null;
  franchise_code: string | null;
  primary_phone_e164: string | null;
  completeness_score: number;
  rating_avg: number;
  review_count: number;
  city: { public_id: string; name: string; slug: string } | null;
  primary_category: { public_id: string; name: string; slug: string } | null;
  organization: { public_id: string; name: string; slug: string } | null;
  owner: { public_id: string; name: string; email: string } | null;
  created_at: string | null;
}

export function AdminBusinessesView() {
  const [rows, setRows] = useState<AdminBusinessRow[]>([]);
  const [meta, setMeta] = useState<AdminPaginatedMeta>({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [sortBy, setSortBy] = useState('created_at');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');
  const [page, setPage] = useState(1);

  const [selected, setSelected] = useState<AdminBusinessRow | null>(null);
  const [reason, setReason] = useState('');
  const [actionLoading, setActionLoading] = useState(false);
  const [feedback, setFeedback] = useState<string | null>(null);
  const [confirmState, setConfirmState] = useState<{ open: boolean; targetStatus: string } | null>(null);

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

      const res = await adminFetch<AdminBusinessRow[]>(`businesses?${qs.toString()}`);
      setRows(Array.isArray(res.data) ? res.data : []);
      if (res.meta) setMeta(res.meta);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load businesses.');
    } finally {
      setLoading(false);
    }
  }, [page, search, statusFilter, sortBy, sortDir]);

  useEffect(() => {
    void load();
  }, [load]);

  async function applyStatusChange(targetStatus: string) {
    if (!selected) return;
    setActionLoading(true);
    setFeedback(null);
    try {
      const res = await adminFetch<AdminBusinessRow>(`businesses/${selected.public_id}/status`, {
        method: 'PATCH',
        body: { status: targetStatus, reason: reason.trim() || undefined },
      });
      setSelected(res.data);
      setReason('');
      setFeedback(`Status updated to ${targetStatus}.`);
      setConfirmState(null);
      await load();
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Status update failed.');
    } finally {
      setActionLoading(false);
    }
  }

  async function toggleBadge(field: 'is_verified' | 'is_trusted', value: boolean) {
    if (!selected) return;
    setActionLoading(true);
    setFeedback(null);
    try {
      const res = await adminFetch<AdminBusinessRow>(`businesses/${selected.public_id}/badges`, {
        method: 'PATCH',
        body: { [field]: value },
      });
      setSelected(res.data);
      setFeedback('Trust badges updated.');
      await load();
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Badge update failed.');
    } finally {
      setActionLoading(false);
    }
  }

  const columns: AdminTableColumn<AdminBusinessRow>[] = [
    {
      key: 'name',
      label: 'Business',
      sortable: true,
      render: (r) => (
        <div>
          <div className="font-semibold text-slate-900 dark:text-white">{r.name}</div>
          <div className="text-xs text-slate-500">
            {r.primary_category?.name ?? 'Uncategorized'} · {r.city?.name ?? '—'}
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
      key: 'trust',
      label: 'Trust & Claim',
      render: (r) => (
        <div className="flex flex-wrap gap-1.5">
          {r.is_verified && <AdminStatusBadge status="verified" />}
          {r.is_trusted && (
            <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">
              Trusted
            </span>
          )}
          {!r.is_claimed && <AdminStatusBadge status="unclaimed" />}
        </div>
      ),
    },
    {
      key: 'completeness_score',
      label: 'Score',
      sortable: true,
      align: 'right',
      render: (r) => <span className="font-mono text-xs">{r.completeness_score}%</span>,
    },
    {
      key: 'rating_avg',
      label: 'Rating',
      sortable: true,
      align: 'right',
      render: (r) => (
        <span className="text-xs">
          {r.rating_avg.toFixed(1)} ({r.review_count})
        </span>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
          Businesses Administration
        </h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          Review listings, moderate visibility status, and manage verified &amp; trusted badges.
        </p>
      </div>

      <AdminDataTable
        columns={columns}
        rows={rows}
        rowKey={(r) => r.public_id}
        loading={loading}
        error={error}
        onRetry={() => void load()}
        searchValue={search}
        searchPlaceholder="Search by name, slug, or phone..."
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
        onRowClick={(r) => {
          setSelected(r);
          setFeedback(null);
        }}
        toolbarExtra={
          <select
            value={statusFilter}
            onChange={(e) => {
              setStatusFilter(e.target.value);
              setPage(1);
            }}
            aria-label="Filter business status"
            className="h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs font-medium text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
          >
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="pending_review">Pending Review</option>
            <option value="draft">Draft</option>
            <option value="suspended">Suspended</option>
            <option value="rejected">Rejected</option>
          </select>
        }
      />

      <AdminDrawer
        open={selected !== null}
        title={selected?.name ?? 'Business Details'}
        subtitle={selected ? `${selected.public_id} · ${selected.slug}` : undefined}
        onClose={() => setSelected(null)}
      >
        {selected && (
          <div className="space-y-5 text-sm">
            {feedback && (
              <div className="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-200">
                {feedback}
              </div>
            )}

            <div className="grid grid-cols-2 gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
              <div>
                <div className="text-xs text-slate-500">Status</div>
                <div className="mt-1">
                  <AdminStatusBadge status={selected.status} />
                </div>
              </div>
              <div>
                <div className="text-xs text-slate-500">City / Category</div>
                <div className="mt-1 font-medium text-slate-900 dark:text-white">
                  {selected.city?.name ?? '—'} · {selected.primary_category?.name ?? '—'}
                </div>
              </div>
              <div>
                <div className="text-xs text-slate-500">Owner</div>
                <div className="mt-1 text-slate-800 dark:text-slate-200">
                  {selected.owner ? `${selected.owner.name} (${selected.owner.email})` : 'Unclaimed'}
                </div>
              </div>
              <div>
                <div className="text-xs text-slate-500">Organization / Branch</div>
                <div className="mt-1 text-slate-800 dark:text-slate-200">
                  {selected.organization ? `${selected.organization.name} (${selected.branch_type ?? 'branch'})` : 'Independent'}
                </div>
              </div>
            </div>

            <div className="space-y-2 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
              <div className="text-xs font-semibold uppercase tracking-wider text-slate-500">
                Trust Badges
              </div>
              <div className="flex flex-wrap gap-2">
                <button
                  type="button"
                  disabled={actionLoading}
                  onClick={() => void toggleBadge('is_verified', !selected.is_verified)}
                  className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-800 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                  <ShieldCheck className="h-3.5 w-3.5 text-emerald-600" />
                  {selected.is_verified ? 'Revoke Verified' : 'Grant Verified'}
                </button>
                <button
                  type="button"
                  disabled={actionLoading}
                  onClick={() => void toggleBadge('is_trusted', !selected.is_trusted)}
                  className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-800 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                  <Sparkles className="h-3.5 w-3.5 text-amber-500" />
                  {selected.is_trusted ? 'Revoke Trusted' : 'Grant Trusted'}
                </button>
              </div>
            </div>

            <div className="space-y-3 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
              <div className="text-xs font-semibold uppercase tracking-wider text-slate-500">
                Moderation &amp; Visibility
              </div>
              <input
                type="text"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder="Audit reason (recommended for suspend/reject)"
                className="h-9 w-full rounded-lg border border-slate-200 bg-white px-3 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
              />
              <div className="flex flex-wrap gap-2">
                <button
                  type="button"
                  disabled={actionLoading || selected.status === 'active'}
                  onClick={() => void applyStatusChange('active')}
                  className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                >
                  Activate
                </button>
                <button
                  type="button"
                  disabled={actionLoading || selected.status === 'suspended'}
                  onClick={() => setConfirmState({ open: true, targetStatus: 'suspended' })}
                  className="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700 disabled:opacity-50"
                >
                  Suspend
                </button>
                <button
                  type="button"
                  disabled={actionLoading || selected.status === 'rejected'}
                  onClick={() => setConfirmState({ open: true, targetStatus: 'rejected' })}
                  className="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-700 disabled:opacity-50"
                >
                  Reject
                </button>
              </div>
            </div>
          </div>
        )}
      </AdminDrawer>

      <AdminConfirmDialog
        open={Boolean(confirmState?.open)}
        title={`Confirm business ${confirmState?.targetStatus ?? ''}`}
        description="This action updates public visibility, dispatches cache revalidation, and records an immutable audit entry."
        confirmLabel={`Mark ${confirmState?.targetStatus ?? ''}`}
        loading={actionLoading}
        onConfirm={() => {
          if (confirmState) void applyStatusChange(confirmState.targetStatus);
        }}
        onCancel={() => setConfirmState(null)}
      />
    </div>
  );
}
