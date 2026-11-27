'use client';

import { useCallback, useEffect, useState } from 'react';
import { adminFetch, type AdminPaginatedMeta } from '@/modules/admin/lib/admin-api';
import { AdminConfirmDialog } from '@/modules/admin/ui/AdminConfirmDialog';
import { AdminDataTable, type AdminTableColumn } from '@/modules/admin/ui/AdminDataTable';
import { AdminDrawer } from '@/modules/admin/ui/AdminDrawer';
import { AdminStatusBadge } from '@/modules/admin/ui/AdminStatusBadge';

interface AdminEnquiryRow {
  public_id: string;
  customer_name: string;
  phone_e164: string;
  requirement: string;
  urgency: string;
  enquiry_type: string;
  status: string;
  city?: { name: string } | null;
  category?: { name: string } | null;
  created_at: string | null;
}

export function AdminEnquiriesView() {
  const [rows, setRows] = useState<AdminEnquiryRow[]>([]);
  const [meta, setMeta] = useState<AdminPaginatedMeta>({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState('');
  const [typeFilter, setTypeFilter] = useState('');
  const [page, setPage] = useState(1);

  const [selected, setSelected] = useState<AdminEnquiryRow | null>(null);
  const [cancelReason, setCancelReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [confirmCancel, setConfirmCancel] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const qs = new URLSearchParams({ page: String(page) });
      if (statusFilter) qs.set('status', statusFilter);
      if (typeFilter) qs.set('enquiry_type', typeFilter);
      const res = await adminFetch<AdminEnquiryRow[]>(`enquiries?${qs.toString()}`);
      setRows(Array.isArray(res.data) ? res.data : []);
      if (res.meta) setMeta(res.meta);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load enquiries.');
    } finally {
      setLoading(false);
    }
  }, [page, statusFilter, typeFilter]);

  useEffect(() => {
    void load();
  }, [load]);

  async function handleCancelEnquiry() {
    if (!selected) return;
    setBusy(true);
    try {
      await adminFetch(`enquiries/${selected.public_id}/cancel`, {
        method: 'PATCH',
        body: { reason: cancelReason.trim() || 'Cancelled by Super Admin' },
      });
      setSelected(null);
      setConfirmCancel(false);
      setCancelReason('');
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Unable to cancel enquiry.');
    } finally {
      setBusy(false);
    }
  }

  const columns: AdminTableColumn<AdminEnquiryRow>[] = [
    {
      key: 'customer',
      label: 'Enquiry',
      render: (r) => (
        <div>
          <div className="font-semibold text-slate-900 dark:text-white">{r.customer_name}</div>
          <div className="line-clamp-1 text-xs text-slate-500">
            {r.category?.name ?? 'General'} · {r.city?.name ?? '—'}
          </div>
        </div>
      ),
    },
    {
      key: 'type',
      label: 'Type / Urgency',
      render: (r) => (
        <span className="text-xs">
          {r.enquiry_type} · {r.urgency}
        </span>
      ),
    },
    {
      key: 'status',
      label: 'Status',
      render: (r) => <AdminStatusBadge status={r.status} />,
    },
  ];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
          CRM &amp; Enquiries Operations
        </h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          Monitor lead routing, OTP verification states, and execute administrative cancellations.
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
          setCancelReason('');
        }}
        toolbarExtra={
          <div className="flex gap-2">
            <select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value);
                setPage(1);
              }}
              aria-label="Filter enquiry status"
              className="h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
            >
              <option value="">All statuses</option>
              <option value="pending_otp">Pending OTP</option>
              <option value="active">Active</option>
              <option value="cancelled">Cancelled</option>
              <option value="expired">Expired</option>
            </select>
            <select
              value={typeFilter}
              onChange={(e) => {
                setTypeFilter(e.target.value);
                setPage(1);
              }}
              aria-label="Filter enquiry type"
              className="h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
            >
              <option value="">All types</option>
              <option value="direct">Direct</option>
              <option value="broadcast">Broadcast</option>
            </select>
          </div>
        }
      />

      <AdminDrawer
        open={selected !== null}
        title={selected?.customer_name ?? 'Enquiry Details'}
        subtitle={selected ? `${selected.public_id} · ${selected.status}` : undefined}
        onClose={() => setSelected(null)}
      >
        {selected && (
          <div className="space-y-4 text-sm">
            <div className="rounded-xl border border-slate-200 p-4 text-xs dark:border-slate-800">
              <div className="font-semibold text-slate-900 dark:text-white">{selected.phone_e164}</div>
              <p className="mt-2 text-slate-600 dark:text-slate-300">{selected.requirement}</p>
            </div>
            {selected.status !== 'cancelled' && (
              <div className="space-y-3 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                <input
                  type="text"
                  value={cancelReason}
                  onChange={(e) => setCancelReason(e.target.value)}
                  placeholder="Cancellation reason"
                  className="h-9 w-full rounded-lg border border-slate-200 px-3 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                />
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => setConfirmCancel(true)}
                  className="rounded-lg bg-rose-600 px-3.5 py-2 text-xs font-semibold text-white"
                >
                  Cancel Enquiry
                </button>
              </div>
            )}
          </div>
        )}
      </AdminDrawer>

      <AdminConfirmDialog
        open={confirmCancel}
        title="Confirm Administrative Cancellation"
        description="Cancelling stops further lead dispatch and notifies affected recipients."
        confirmLabel="Cancel Enquiry"
        loading={busy}
        onConfirm={() => void handleCancelEnquiry()}
        onCancel={() => setConfirmCancel(false)}
      />
    </div>
  );
}
