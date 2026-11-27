'use client';

import { useCallback, useEffect, useState } from 'react';
import { Globe, ShieldCheck } from 'lucide-react';
import { adminFetch, type AdminPaginatedMeta } from '@/modules/admin/lib/admin-api';
import { AdminConfirmDialog } from '@/modules/admin/ui/AdminConfirmDialog';
import { AdminDataTable, type AdminTableColumn } from '@/modules/admin/ui/AdminDataTable';
import { AdminDrawer } from '@/modules/admin/ui/AdminDrawer';
import { AdminStatusBadge } from '@/modules/admin/ui/AdminStatusBadge';

interface DomainRow {
  public_id: string;
  hostname: string;
  domain_type: string;
  status: string;
  verification_status: string;
  ssl_status: string;
  is_primary: boolean;
}

export function AdminDomainsView() {
  const [rows, setRows] = useState<DomainRow[]>([]);
  const [meta, setMeta] = useState<AdminPaginatedMeta>({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);

  const [selected, setSelected] = useState<DomainRow | null>(null);
  const [feedback, setFeedback] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [confirmSuspend, setConfirmSuspend] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await adminFetch<DomainRow[]>(`domains?page=${page}`);
      setRows(Array.isArray(res.data) ? res.data : []);
      if (res.meta) setMeta(res.meta);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load domains.');
    } finally {
      setLoading(false);
    }
  }, [page]);

  useEffect(() => {
    void load();
  }, [load]);

  async function runAction(action: 'challenge' | 'verify' | 'ssl' | 'promote-primary' | 'suspend') {
    if (!selected) return;
    setBusy(true);
    setFeedback(null);
    try {
      const res = await adminFetch<DomainRow>(`domains/${selected.public_id}/${action}`, {
        method: 'POST',
      });
      if (res.data?.public_id) {
        setSelected(res.data);
      }
      setConfirmSuspend(false);
      setFeedback(`Action "${action}" completed.`);
      await load();
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : `Domain ${action} failed.`);
    } finally {
      setBusy(false);
    }
  }

  const columns: AdminTableColumn<DomainRow>[] = [
    {
      key: 'hostname',
      label: 'Hostname',
      render: (r) => (
        <div className="flex items-center gap-2">
          <Globe className="h-4 w-4 text-slate-400" />
          <div>
            <div className="font-semibold text-slate-900 dark:text-white">
              {r.hostname} {r.is_primary ? '★' : ''}
            </div>
            <div className="text-xs text-slate-500">{r.domain_type}</div>
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
      key: 'verification',
      label: 'DNS / SSL',
      render: (r) => (
        <div className="flex gap-1.5">
          <AdminStatusBadge status={r.verification_status} />
          <AdminStatusBadge status={r.ssl_status} />
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
          Domains &amp; SSL Registry
        </h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          Manage multi-domain hosts, DNS TXT verification challenges, SSL checks, and primary host promotion.
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
          setFeedback(null);
        }}
      />

      <AdminDrawer
        open={selected !== null}
        title={selected?.hostname ?? 'Domain'}
        subtitle={selected ? `${selected.domain_type} · ${selected.status}` : undefined}
        onClose={() => setSelected(null)}
      >
        {selected && (
          <div className="space-y-4 text-sm">
            {feedback && (
              <div className="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-200">
                {feedback}
              </div>
            )}
            <div className="flex flex-wrap gap-2">
              <button
                type="button"
                disabled={busy}
                onClick={() => void runAction('challenge')}
                className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold dark:border-slate-700"
              >
                Issue Challenge
              </button>
              <button
                type="button"
                disabled={busy}
                onClick={() => void runAction('verify')}
                className="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white"
              >
                <ShieldCheck className="h-3.5 w-3.5" />
                Verify DNS
              </button>
              <button
                type="button"
                disabled={busy}
                onClick={() => void runAction('ssl')}
                className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold dark:border-slate-700"
              >
                Check SSL
              </button>
              <button
                type="button"
                disabled={busy || selected.is_primary}
                onClick={() => void runAction('promote-primary')}
                className="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50 dark:bg-white dark:text-slate-950"
              >
                Promote Primary
              </button>
              <button
                type="button"
                disabled={busy || selected.status === 'suspended'}
                onClick={() => setConfirmSuspend(true)}
                className="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50"
              >
                Suspend
              </button>
            </div>
          </div>
        )}
      </AdminDrawer>

      <AdminConfirmDialog
        open={confirmSuspend}
        title="Confirm Domain Suspension"
        description="Suspending a domain disables public storefront routing for this hostname."
        confirmLabel="Suspend Domain"
        loading={busy}
        onConfirm={() => void runAction('suspend')}
        onCancel={() => setConfirmSuspend(false)}
      />
    </div>
  );
}
