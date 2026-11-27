'use client';

import { useCallback, useEffect, useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { adminFetch, type AdminPaginatedMeta } from '@/modules/admin/lib/admin-api';
import { AdminConfirmDialog } from '@/modules/admin/ui/AdminConfirmDialog';
import { AdminDataTable, type AdminTableColumn } from '@/modules/admin/ui/AdminDataTable';
import { AdminDrawer } from '@/modules/admin/ui/AdminDrawer';
import { AdminStatusBadge } from '@/modules/admin/ui/AdminStatusBadge';

interface SeoMetaRow {
  public_id: string;
  entity_type: string;
  entity_id: number;
  scope_key: string;
  locale: string;
  meta_title: string | null;
  meta_description: string | null;
  robots_index: boolean;
  robots_follow: boolean;
}

interface RedirectRow {
  id: number;
  scope_key: string;
  source_path: string;
  target_path: string;
  http_status: number;
  is_active: boolean;
  domain_hostname: string | null;
}

export function AdminSeoView() {
  const [tab, setTab] = useState<'metadata' | 'redirects'>('metadata');
  const [metadata, setMetadata] = useState<SeoMetaRow[]>([]);
  const [redirects, setRedirects] = useState<RedirectRow[]>([]);
  const [meta, setMeta] = useState<AdminPaginatedMeta>({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);

  const [createOpen, setCreateOpen] = useState(false);
  const [sourcePath, setSourcePath] = useState('');
  const [targetPath, setTargetPath] = useState('');
  const [httpStatus, setHttpStatus] = useState('301');
  const [busy, setBusy] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<RedirectRow | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const qs = new URLSearchParams({ page: String(page) });
      if (search.trim()) qs.set('q', search.trim());

      if (tab === 'metadata') {
        const res = await adminFetch<SeoMetaRow[]>(`seo/metadata?${qs.toString()}`);
        setMetadata(Array.isArray(res.data) ? res.data : []);
        if (res.meta) setMeta(res.meta);
      } else {
        const res = await adminFetch<RedirectRow[]>(`seo/redirects?${qs.toString()}`);
        setRedirects(Array.isArray(res.data) ? res.data : []);
        if (res.meta) setMeta(res.meta);
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load SEO records.');
    } finally {
      setLoading(false);
    }
  }, [tab, page, search]);

  useEffect(() => {
    void load();
  }, [load]);

  async function handleCreateRedirect() {
    setBusy(true);
    setError(null);
    try {
      await adminFetch('seo/redirects', {
        method: 'POST',
        body: {
          source_path: sourcePath.trim(),
          target_path: targetPath.trim(),
          http_status: Number(httpStatus),
        },
      });
      setCreateOpen(false);
      setSourcePath('');
      setTargetPath('');
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Redirect creation failed.');
    } finally {
      setBusy(false);
    }
  }

  async function toggleRedirect(r: RedirectRow) {
    setBusy(true);
    try {
      await adminFetch(`seo/redirects/${r.id}`, {
        method: 'PATCH',
        body: { is_active: !r.is_active },
      });
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to toggle redirect.');
    } finally {
      setBusy(false);
    }
  }

  async function confirmDeleteRedirect() {
    if (!deleteTarget) return;
    setBusy(true);
    try {
      await adminFetch(`seo/redirects/${deleteTarget.id}`, { method: 'DELETE' });
      setDeleteTarget(null);
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to delete redirect.');
    } finally {
      setBusy(false);
    }
  }

  const metaCols: AdminTableColumn<SeoMetaRow>[] = [
    {
      key: 'entity',
      label: 'Entity / Title',
      render: (r) => (
        <div>
          <div className="font-semibold text-slate-900 dark:text-white">
            {r.meta_title || `${r.entity_type} #${r.entity_id}`}
          </div>
          <div className="text-xs text-slate-500">
            {r.entity_type} · {r.locale} · {r.scope_key}
          </div>
        </div>
      ),
    },
    {
      key: 'robots',
      label: 'Indexability',
      render: (r) => (
        <span className="font-mono text-xs">
          {r.robots_index ? 'index' : 'noindex'}, {r.robots_follow ? 'follow' : 'nofollow'}
        </span>
      ),
    },
  ];

  const redirectCols: AdminTableColumn<RedirectRow>[] = [
    {
      key: 'paths',
      label: 'Redirect Rule',
      render: (r) => (
        <div>
          <div className="font-mono text-xs font-semibold text-slate-900 dark:text-white">
            {r.source_path} → {r.target_path}
          </div>
          <div className="text-[11px] text-slate-500">
            HTTP {r.http_status} · {r.domain_hostname ?? r.scope_key}
          </div>
        </div>
      ),
    },
    {
      key: 'status',
      label: 'Status',
      render: (r) => <AdminStatusBadge status={r.is_active ? 'active' : 'suspended'} />,
    },
    {
      key: 'actions',
      label: 'Actions',
      align: 'right',
      render: (r) => (
        <div className="flex justify-end gap-2">
          <button
            type="button"
            disabled={busy}
            onClick={() => void toggleRedirect(r)}
            className="rounded-md border border-slate-200 px-2 py-1 text-[11px] font-semibold dark:border-slate-700"
          >
            {r.is_active ? 'Disable' : 'Enable'}
          </button>
          <button
            type="button"
            disabled={busy}
            onClick={() => setDeleteTarget(r)}
            aria-label={`Delete redirect ${r.source_path}`}
            className="rounded-md border border-rose-200 p-1 text-rose-600 hover:bg-rose-50 dark:border-rose-900"
          >
            <Trash2 className="h-3.5 w-3.5" />
          </button>
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
            SEO Metadata &amp; Redirects
          </h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Inspect entity SEO metadata overrides, indexability directives, and 301/302 redirect rules.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <div className="flex rounded-xl border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900">
            <button
              type="button"
              onClick={() => {
                setTab('metadata');
                setPage(1);
              }}
              className={`rounded-lg px-3 py-1.5 text-xs font-semibold ${
                tab === 'metadata' ? 'bg-slate-950 text-white dark:bg-white dark:text-slate-950' : 'text-slate-600'
              }`}
            >
              SEO Metadata
            </button>
            <button
              type="button"
              onClick={() => {
                setTab('redirects');
                setPage(1);
              }}
              className={`rounded-lg px-3 py-1.5 text-xs font-semibold ${
                tab === 'redirects' ? 'bg-slate-950 text-white dark:bg-white dark:text-slate-950' : 'text-slate-600'
              }`}
            >
              Redirects
            </button>
          </div>

          {tab === 'redirects' && (
            <button
              type="button"
              onClick={() => setCreateOpen(true)}
              className="inline-flex items-center gap-1.5 rounded-xl bg-slate-950 px-3 py-2 text-xs font-semibold text-white dark:bg-white dark:text-slate-950"
            >
              <Plus className="h-3.5 w-3.5" />
              Add Redirect
            </button>
          )}
        </div>
      </div>

      {tab === 'metadata' ? (
        <AdminDataTable
          columns={metaCols}
          rows={metadata}
          rowKey={(r) => r.public_id}
          loading={loading}
          error={error}
          onRetry={() => void load()}
          searchValue={search}
          searchPlaceholder="Search meta title or canonical..."
          onSearchChange={(val) => {
            setSearch(val);
            setPage(1);
          }}
          page={meta.current_page}
          lastPage={meta.last_page}
          total={meta.total}
          onPageChange={setPage}
        />
      ) : (
        <AdminDataTable
          columns={redirectCols}
          rows={redirects}
          rowKey={(r) => String(r.id)}
          loading={loading}
          error={error}
          onRetry={() => void load()}
          searchValue={search}
          searchPlaceholder="Search source or target path..."
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

      <AdminDrawer
        open={createOpen}
        title="Create URL Redirect"
        onClose={() => setCreateOpen(false)}
      >
        <div className="space-y-4 text-sm">
          <div>
            <label className="block text-xs font-medium">Source Path</label>
            <input
              type="text"
              value={sourcePath}
              onChange={(e) => setSourcePath(e.target.value)}
              placeholder="/old-path"
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 font-mono text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
          </div>
          <div>
            <label className="block text-xs font-medium">Target Path</label>
            <input
              type="text"
              value={targetPath}
              onChange={(e) => setTargetPath(e.target.value)}
              placeholder="/new-path"
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 font-mono text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
          </div>
          <div>
            <label className="block text-xs font-medium">HTTP Status</label>
            <select
              value={httpStatus}
              onChange={(e) => setHttpStatus(e.target.value)}
              aria-label="HTTP redirect status"
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            >
              <option value="301">301 Permanent</option>
              <option value="302">302 Temporary</option>
              <option value="307">307 Temporary Preserve Method</option>
              <option value="308">308 Permanent Preserve Method</option>
            </select>
          </div>
          <button
            type="button"
            disabled={busy || !sourcePath.trim() || !targetPath.trim()}
            onClick={() => void handleCreateRedirect()}
            className="rounded-lg bg-slate-950 px-4 py-2 text-xs font-semibold text-white disabled:opacity-50 dark:bg-white dark:text-slate-950"
          >
            Save Redirect
          </button>
        </div>
      </AdminDrawer>

      <AdminConfirmDialog
        open={deleteTarget !== null}
        title="Delete Redirect Rule"
        description={`Remove redirect from ${deleteTarget?.source_path ?? ''} to ${deleteTarget?.target_path ?? ''}?`}
        confirmLabel="Delete Redirect"
        loading={busy}
        onConfirm={() => void confirmDeleteRedirect()}
        onCancel={() => setDeleteTarget(null)}
      />
    </div>
  );
}
