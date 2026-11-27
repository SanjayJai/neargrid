'use client';

import { useCallback, useEffect, useState } from 'react';
import { adminFetch, type AdminPaginatedMeta } from '@/modules/admin/lib/admin-api';
import { AdminConfirmDialog } from '@/modules/admin/ui/AdminConfirmDialog';
import { AdminDataTable, type AdminTableColumn } from '@/modules/admin/ui/AdminDataTable';
import { AdminStatusBadge } from '@/modules/admin/ui/AdminStatusBadge';

interface SessionRow {
  public_id: string;
  name: string;
  device_public_id: string | null;
  last_used_at: string | null;
  expires_at: string | null;
  user: { public_id: string; name: string; email: string | null; status: string } | null;
}

interface SecurityEventRow {
  id: number;
  actor_role: string | null;
  action: string;
  auditable_type: string | null;
  auditable_id: number | null;
  created_at: string | null;
}

interface ErasureRow {
  public_id: string;
  status: string;
  reason: string | null;
  user: { public_id: string; name: string; email: string | null; status: string } | null;
  created_at: string | null;
}

export function AdminSecurityView() {
  const [tab, setTab] = useState<'sessions' | 'events' | 'erasure'>('sessions');
  const [sessions, setSessions] = useState<SessionRow[]>([]);
  const [events, setEvents] = useState<SecurityEventRow[]>([]);
  const [erasures, setErasures] = useState<ErasureRow[]>([]);
  const [meta, setMeta] = useState<AdminPaginatedMeta>({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [busy, setBusy] = useState(false);

  const [confirmRevoke, setConfirmRevoke] = useState<SessionRow | null>(null);
  const [confirmErasure, setConfirmErasure] = useState<{ row: ErasureRow; decision: 'approved' | 'rejected' } | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const qs = new URLSearchParams({ page: String(page) });
      if (tab === 'sessions') {
        if (search.trim()) qs.set('q', search.trim());
        const res = await adminFetch<SessionRow[]>(`security/sessions?${qs.toString()}`);
        setSessions(Array.isArray(res.data) ? res.data : []);
        if (res.meta) setMeta(res.meta);
      } else if (tab === 'events') {
        if (search.trim()) qs.set('action', search.trim());
        const res = await adminFetch<SecurityEventRow[]>(`security/events?${qs.toString()}`);
        setEvents(Array.isArray(res.data) ? res.data : []);
        if (res.meta) setMeta(res.meta);
      } else {
        const res = await adminFetch<ErasureRow[]>(`erasure-requests?${qs.toString()}`);
        setErasures(Array.isArray(res.data) ? res.data : []);
        if (res.meta) setMeta(res.meta);
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load security data.');
    } finally {
      setLoading(false);
    }
  }, [tab, page, search]);

  useEffect(() => {
    void load();
  }, [load]);

  async function revokeSession(row: SessionRow) {
    setBusy(true);
    try {
      await adminFetch(`security/sessions/${row.public_id}`, { method: 'DELETE' });
      setConfirmRevoke(null);
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Revoke failed.');
    } finally {
      setBusy(false);
    }
  }

  async function reviewErasure(row: ErasureRow, decision: 'approved' | 'rejected') {
    setBusy(true);
    try {
      await adminFetch(`erasure-requests/${row.public_id}/review`, {
        method: 'POST',
        body: { decision },
      });
      setConfirmErasure(null);
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erasure review failed.');
    } finally {
      setBusy(false);
    }
  }

  const sessionCols: AdminTableColumn<SessionRow>[] = [
    {
      key: 'user',
      label: 'User & Token',
      render: (r) => (
        <div>
          <div className="font-semibold text-slate-900 dark:text-white">
            {r.user?.name ?? 'Unknown'} · {r.name}
          </div>
          <div className="font-mono text-xs text-slate-500">
            {r.public_id} · last used {r.last_used_at ?? 'never'}
          </div>
        </div>
      ),
    },
    {
      key: 'action',
      label: 'Action',
      align: 'right',
      render: (r) => (
        <button
          type="button"
          onClick={() => setConfirmRevoke(r)}
          className="rounded-lg border border-rose-200 px-2.5 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50"
        >
          Revoke
        </button>
      ),
    },
  ];

  const eventCols: AdminTableColumn<SecurityEventRow>[] = [
    {
      key: 'action',
      label: 'Security Event',
      render: (r) => <span className="font-mono text-xs font-semibold">{r.action}</span>,
    },
    {
      key: 'actor_role',
      label: 'Actor Role',
      render: (r) => <span className="text-xs">{r.actor_role ?? 'system'}</span>,
    },
    {
      key: 'created_at',
      label: 'Timestamp',
      align: 'right',
      render: (r) => <span className="text-xs text-slate-500">{r.created_at ?? '—'}</span>,
    },
  ];

  const erasureCols: AdminTableColumn<ErasureRow>[] = [
    {
      key: 'user',
      label: 'Account',
      render: (r) => (
        <div>
          <div className="font-semibold">{r.user?.name ?? r.public_id}</div>
          <div className="text-xs text-slate-500">{r.reason ?? '—'}</div>
        </div>
      ),
    },
    {
      key: 'status',
      label: 'Status',
      render: (r) => <AdminStatusBadge status={r.status} />,
    },
    {
      key: 'actions',
      label: 'Review',
      align: 'right',
      render: (r) =>
        r.status === 'pending' ? (
          <div className="flex justify-end gap-2">
            <button
              type="button"
              onClick={() => setConfirmErasure({ row: r, decision: 'approved' })}
              className="rounded-md bg-rose-600 px-2.5 py-1 text-xs font-semibold text-white"
            >
              Approve Anonymization
            </button>
            <button
              type="button"
              onClick={() => setConfirmErasure({ row: r, decision: 'rejected' })}
              className="rounded-md border border-slate-200 px-2.5 py-1 text-xs font-semibold"
            >
              Reject
            </button>
          </div>
        ) : (
          <span className="text-xs text-slate-500">Processed</span>
        ),
    },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
            Sessions &amp; Security Operations
          </h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Inspect active Sanctum sessions, security audit events, and controlled erasure requests.
          </p>
        </div>

        <div className="flex rounded-xl border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900">
          {(['sessions', 'events', 'erasure'] as const).map((t) => (
            <button
              key={t}
              type="button"
              onClick={() => {
                setTab(t);
                setSearch('');
                setPage(1);
              }}
              className={`rounded-lg px-3 py-1.5 text-xs font-semibold capitalize ${
                tab === t ? 'bg-slate-950 text-white dark:bg-white dark:text-slate-950' : 'text-slate-600'
              }`}
            >
              {t === 'erasure' ? 'Erasure Queue' : t}
            </button>
          ))}
        </div>
      </div>

      {tab === 'sessions' && (
        <AdminDataTable
          columns={sessionCols}
          rows={sessions}
          rowKey={(r) => r.public_id}
          loading={loading}
          error={error}
          onRetry={() => void load()}
          searchValue={search}
          searchPlaceholder="Filter by user or session name..."
          onSearchChange={(val) => {
            setSearch(val);
            setPage(1);
          }}
          page={meta.current_page}
          lastPage={meta.last_page}
          total={meta.total}
          onPageChange={setPage}
        />
      )}

      {tab === 'events' && (
        <AdminDataTable
          columns={eventCols}
          rows={events}
          rowKey={(r) => String(r.id)}
          loading={loading}
          error={error}
          onRetry={() => void load()}
          searchValue={search}
          searchPlaceholder="Filter action (e.g. role, session)..."
          onSearchChange={(val) => {
            setSearch(val);
            setPage(1);
          }}
          page={meta.current_page}
          lastPage={meta.last_page}
          total={meta.total}
          onPageChange={setPage}
        />
      )}

      {tab === 'erasure' && (
        <AdminDataTable
          columns={erasureCols}
          rows={erasures}
          rowKey={(r) => r.public_id}
          loading={loading}
          error={error}
          onRetry={() => void load()}
          page={meta.current_page}
          lastPage={meta.last_page}
          total={meta.total}
          onPageChange={setPage}
        />
      )}

      <AdminConfirmDialog
        open={confirmRevoke !== null}
        title="Revoke Active Session"
        description={`Immediately revoke session ${confirmRevoke?.public_id ?? ''}?`}
        confirmLabel="Revoke Session"
        loading={busy}
        onConfirm={() => {
          if (confirmRevoke) void revokeSession(confirmRevoke);
        }}
        onCancel={() => setConfirmRevoke(null)}
      />

      <AdminConfirmDialog
        open={confirmErasure !== null}
        title={
          confirmErasure?.decision === 'approved'
            ? 'Approve Account Anonymization'
            : 'Reject Erasure Request'
        }
        description={
          confirmErasure?.decision === 'approved'
            ? 'Approved anonymization irreversibly scrubs PII, revokes all sessions, and soft-deletes the user while preserving retention-safe audit history.'
            : 'Rejecting closes this erasure request without altering the user account.'
        }
        confirmLabel={confirmErasure?.decision === 'approved' ? 'Anonymize Account' : 'Reject Request'}
        loading={busy}
        onConfirm={() => {
          if (confirmErasure) void reviewErasure(confirmErasure.row, confirmErasure.decision);
        }}
        onCancel={() => setConfirmErasure(null)}
      />
    </div>
  );
}
