'use client';

import { useCallback, useEffect, useState } from 'react';
import { adminFetch, type AdminPaginatedMeta } from '@/modules/admin/lib/admin-api';
import { AdminConfirmDialog } from '@/modules/admin/ui/AdminConfirmDialog';
import { AdminDataTable, type AdminTableColumn } from '@/modules/admin/ui/AdminDataTable';
import { AdminDrawer } from '@/modules/admin/ui/AdminDrawer';
import { AdminStatusBadge } from '@/modules/admin/ui/AdminStatusBadge';

interface CategoryRow {
  public_id: string;
  name: string;
  slug: string;
  depth?: number;
  lifecycle_status: string;
  is_assignable?: boolean;
}

interface CollectionRow {
  public_id: string;
  name: string;
  slug: string;
  is_active?: boolean;
}

export function AdminCategoriesView() {
  const [tab, setTab] = useState<'categories' | 'collections'>('categories');
  const [categories, setCategories] = useState<CategoryRow[]>([]);
  const [collections, setCollections] = useState<CollectionRow[]>([]);
  const [meta, setMeta] = useState<AdminPaginatedMeta>({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [page, setPage] = useState(1);

  const [selected, setSelected] = useState<CategoryRow | null>(null);
  const [busy, setBusy] = useState(false);
  const [confirmArchive, setConfirmArchive] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const qs = new URLSearchParams({ page: String(page) });
      if (search.trim()) qs.set('q', search.trim());
      if (tab === 'categories') {
        if (statusFilter) qs.set('lifecycle_status', statusFilter);
        const res = await adminFetch<CategoryRow[]>(`categories?${qs.toString()}`);
        setCategories(Array.isArray(res.data) ? res.data : []);
        if (res.meta) setMeta(res.meta);
      } else {
        const res = await adminFetch<CollectionRow[]>(`category-collections?${qs.toString()}`);
        setCollections(Array.isArray(res.data) ? res.data : []);
        if (res.meta) setMeta(res.meta);
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load taxonomy.');
    } finally {
      setLoading(false);
    }
  }, [tab, page, search, statusFilter]);

  useEffect(() => {
    void load();
  }, [load]);

  async function runCategoryAction(action: 'publish' | 'archive' | 'restore') {
    if (!selected) return;
    setBusy(true);
    try {
      await adminFetch(`categories/${selected.public_id}/${action}`, { method: 'POST' });
      setSelected(null);
      setConfirmArchive(false);
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : `Failed to ${action} category.`);
    } finally {
      setBusy(false);
    }
  }

  const catCols: AdminTableColumn<CategoryRow>[] = [
    {
      key: 'name',
      label: 'Category',
      render: (r) => (
        <div>
          <div className="font-semibold text-slate-900 dark:text-white">{r.name}</div>
          <div className="text-xs text-slate-500">
            /{r.slug} {r.depth !== undefined ? `· Level ${r.depth}` : ''}
          </div>
        </div>
      ),
    },
    {
      key: 'lifecycle_status',
      label: 'Lifecycle',
      render: (r) => <AdminStatusBadge status={r.lifecycle_status} />,
    },
    {
      key: 'assignable',
      label: 'Assignable',
      render: (r) => <span className="text-xs">{r.is_assignable ? 'Yes' : 'No'}</span>,
    },
  ];

  const colCols: AdminTableColumn<CollectionRow>[] = [
    {
      key: 'name',
      label: 'Collection',
      render: (r) => (
        <div>
          <div className="font-semibold text-slate-900 dark:text-white">{r.name}</div>
          <div className="text-xs text-slate-500">{r.slug}</div>
        </div>
      ),
    },
    {
      key: 'active',
      label: 'Status',
      render: (r) => <AdminStatusBadge status={r.is_active === false ? 'archived' : 'active'} />,
    },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
            Categories, Schemas &amp; Collections
          </h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Manage 5-level taxonomy hierarchy, lifecycle publishing, and curated category collections.
          </p>
        </div>

        <div className="flex rounded-xl border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900">
          <button
            type="button"
            onClick={() => {
              setTab('categories');
              setPage(1);
            }}
            className={`rounded-lg px-3 py-1.5 text-xs font-semibold ${
              tab === 'categories' ? 'bg-slate-950 text-white dark:bg-white dark:text-slate-950' : 'text-slate-600'
            }`}
          >
            Categories
          </button>
          <button
            type="button"
            onClick={() => {
              setTab('collections');
              setPage(1);
            }}
            className={`rounded-lg px-3 py-1.5 text-xs font-semibold ${
              tab === 'collections' ? 'bg-slate-950 text-white dark:bg-white dark:text-slate-950' : 'text-slate-600'
            }`}
          >
            Collections
          </button>
        </div>
      </div>

      {tab === 'categories' ? (
        <AdminDataTable
          columns={catCols}
          rows={categories}
          rowKey={(r) => r.public_id}
          loading={loading}
          error={error}
          onRetry={() => void load()}
          searchValue={search}
          searchPlaceholder="Search categories..."
          onSearchChange={(val) => {
            setSearch(val);
            setPage(1);
          }}
          page={meta.current_page}
          lastPage={meta.last_page}
          total={meta.total}
          onPageChange={setPage}
          onRowClick={(r) => setSelected(r)}
          toolbarExtra={
            <select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value);
                setPage(1);
              }}
              aria-label="Filter category lifecycle"
              className="h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
            >
              <option value="">All statuses</option>
              <option value="published">Published</option>
              <option value="draft">Draft</option>
              <option value="archived">Archived</option>
            </select>
          }
        />
      ) : (
        <AdminDataTable
          columns={colCols}
          rows={collections}
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

      <AdminDrawer
        open={selected !== null}
        title={selected?.name ?? 'Category'}
        subtitle={selected ? `${selected.public_id} · ${selected.lifecycle_status}` : undefined}
        onClose={() => setSelected(null)}
      >
        {selected && (
          <div className="space-y-4 text-sm">
            <div className="flex flex-wrap gap-2">
              <button
                type="button"
                disabled={busy || selected.lifecycle_status === 'published'}
                onClick={() => void runCategoryAction('publish')}
                className="rounded-lg bg-emerald-600 px-3.5 py-2 text-xs font-semibold text-white disabled:opacity-50"
              >
                Publish
              </button>
              <button
                type="button"
                disabled={busy || selected.lifecycle_status === 'archived'}
                onClick={() => setConfirmArchive(true)}
                className="rounded-lg bg-amber-600 px-3.5 py-2 text-xs font-semibold text-white disabled:opacity-50"
              >
                Archive
              </button>
              <button
                type="button"
                disabled={busy}
                onClick={() => void runCategoryAction('restore')}
                className="rounded-lg border border-slate-200 px-3.5 py-2 text-xs font-semibold dark:border-slate-700"
              >
                Restore
              </button>
            </div>
          </div>
        )}
      </AdminDrawer>

      <AdminConfirmDialog
        open={confirmArchive}
        title="Confirm Category Archive"
        description="Archiving removes this category from active directory navigation."
        confirmLabel="Archive Category"
        variant="warning"
        loading={busy}
        onConfirm={() => void runCategoryAction('archive')}
        onCancel={() => setConfirmArchive(false)}
      />
    </div>
  );
}
