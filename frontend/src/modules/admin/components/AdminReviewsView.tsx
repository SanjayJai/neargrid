'use client';

import { useCallback, useEffect, useState } from 'react';
import { adminFetch, type AdminPaginatedMeta } from '@/modules/admin/lib/admin-api';
import { AdminConfirmDialog } from '@/modules/admin/ui/AdminConfirmDialog';
import { AdminDataTable, type AdminTableColumn } from '@/modules/admin/ui/AdminDataTable';
import { AdminDrawer } from '@/modules/admin/ui/AdminDrawer';
import { AdminStatusBadge } from '@/modules/admin/ui/AdminStatusBadge';

interface AdminReviewRow {
  public_id: string;
  rating: number;
  title: string | null;
  body: string;
  status: string;
  moderation_status: string;
  business?: { public_id: string; name: string } | null;
  reports_count?: number;
  created_at: string | null;
}

export function AdminReviewsView() {
  const [rows, setRows] = useState<AdminReviewRow[]>([]);
  const [meta, setMeta] = useState<AdminPaginatedMeta>({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [modFilter, setModFilter] = useState('');
  const [flaggedOnly, setFlaggedOnly] = useState(false);
  const [page, setPage] = useState(1);

  const [selected, setSelected] = useState<AdminReviewRow | null>(null);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [confirmReject, setConfirmReject] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const qs = new URLSearchParams({ page: String(page) });
      if (modFilter) qs.set('moderation_status', modFilter);
      if (flaggedOnly) qs.set('flagged_only', '1');
      const res = await adminFetch<AdminReviewRow[]>(`reviews?${qs.toString()}`);
      setRows(Array.isArray(res.data) ? res.data : []);
      if (res.meta) setMeta(res.meta);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load reviews.');
    } finally {
      setLoading(false);
    }
  }, [page, modFilter, flaggedOnly]);

  useEffect(() => {
    void load();
  }, [load]);

  async function moderate(status: 'approved' | 'flagged' | 'rejected') {
    if (!selected) return;
    setBusy(true);
    try {
      await adminFetch(`reviews/${selected.public_id}/moderation`, {
        method: 'PATCH',
        body: { status, reason: reason.trim() || undefined },
      });
      setSelected(null);
      setConfirmReject(false);
      setReason('');
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Moderation failed.');
    } finally {
      setBusy(false);
    }
  }

  const columns: AdminTableColumn<AdminReviewRow>[] = [
    {
      key: 'business',
      label: 'Review',
      render: (r) => (
        <div>
          <div className="font-semibold text-slate-900 dark:text-white">
            {r.business?.name ?? 'Business'} · {r.rating}★
          </div>
          <div className="line-clamp-1 text-xs text-slate-500">{r.title ?? r.body}</div>
        </div>
      ),
    },
    {
      key: 'moderation_status',
      label: 'Moderation',
      render: (r) => <AdminStatusBadge status={r.moderation_status} />,
    },
    {
      key: 'status',
      label: 'Visibility',
      render: (r) => <AdminStatusBadge status={r.status} />,
    },
  ];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
          Reviews &amp; Disputes Moderation
        </h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          Moderate customer reviews, inspect flagged abuse reports, and enforce trust policies.
        </p>
      </div>

      <AdminDataTable
        columns={columns}
        rows={rows}
        rowKey={(r) => r.public_id}
        loading={loading}
        error={error}
        onRetry={() => void load()}
        page={meta.current_page}
        lastPage={meta.last_page}
        total={meta.total}
        onPageChange={setPage}
        onRowClick={(r) => {
          setSelected(r);
          setReason('');
        }}
        toolbarExtra={
          <div className="flex items-center gap-2">
            <select
              value={modFilter}
              onChange={(e) => {
                setModFilter(e.target.value);
                setPage(1);
              }}
              aria-label="Filter moderation status"
              className="h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
            >
              <option value="">All</option>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="flagged">Flagged</option>
              <option value="rejected">Rejected</option>
            </select>
            <label className="flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300">
              <input
                type="checkbox"
                checked={flaggedOnly}
                onChange={(e) => {
                  setFlaggedOnly(e.target.checked);
                  setPage(1);
                }}
              />
              Flagged only
            </label>
          </div>
        }
      />

      <AdminDrawer
        open={selected !== null}
        title={selected?.business?.name ?? 'Review Moderation'}
        subtitle={selected ? `${selected.rating}★ · ${selected.moderation_status}` : undefined}
        onClose={() => setSelected(null)}
      >
        {selected && (
          <div className="space-y-4 text-sm">
            <div className="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
              {selected.title && <div className="font-semibold">{selected.title}</div>}
              <p className="mt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-300">
                {selected.body}
              </p>
            </div>
            <textarea
              rows={3}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="Moderation note / policy reason"
              className="w-full rounded-lg border border-slate-200 p-2.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
            <div className="flex flex-wrap gap-2">
              <button
                type="button"
                disabled={busy}
                onClick={() => void moderate('approved')}
                className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white"
              >
                Approve
              </button>
              <button
                type="button"
                disabled={busy}
                onClick={() => void moderate('flagged')}
                className="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white"
              >
                Flag
              </button>
              <button
                type="button"
                disabled={busy}
                onClick={() => setConfirmReject(true)}
                className="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white"
              >
                Reject
              </button>
            </div>
          </div>
        )}
      </AdminDrawer>

      <AdminConfirmDialog
        open={confirmReject}
        title="Confirm Review Rejection"
        description="Rejecting hides this review from public business pages and logs the moderation action."
        confirmLabel="Reject Review"
        loading={busy}
        onConfirm={() => void moderate('rejected')}
        onCancel={() => setConfirmReject(false)}
      />
    </div>
  );
}
