'use client';

import { useCallback, useEffect, useState } from 'react';
import { adminFetch, type AdminPaginatedMeta } from '@/modules/admin/lib/admin-api';
import { AdminConfirmDialog } from '@/modules/admin/ui/AdminConfirmDialog';
import { AdminDataTable, type AdminTableColumn } from '@/modules/admin/ui/AdminDataTable';
import { AdminDrawer } from '@/modules/admin/ui/AdminDrawer';
import { AdminStatusBadge } from '@/modules/admin/ui/AdminStatusBadge';

interface LocationRow {
  public_id: string;
  name: string;
  slug: string;
  status?: string;
  is_active?: boolean;
  country_code?: string;
}

export function AdminLocationsView() {
  const [tab, setTab] = useState<'states' | 'cities' | 'localities'>('cities');
  const [rows, setRows] = useState<LocationRow[]>([]);
  const [meta, setMeta] = useState<AdminPaginatedMeta>({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);

  const [selected, setSelected] = useState<LocationRow | null>(null);
  const [busy, setBusy] = useState(false);
  const [confirmArchive, setConfirmArchive] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const qs = new URLSearchParams({ page: String(page) });
      if (search.trim()) qs.set('q', search.trim());
      const res = await adminFetch<LocationRow[]>(`locations/${tab}?${qs.toString()}`);
      setRows(Array.isArray(res.data) ? res.data : []);
      if (res.meta) setMeta(res.meta);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load locations.');
    } finally {
      setLoading(false);
    }
  }, [tab, page, search]);

  useEffect(() => {
    void load();
  }, [load]);

  async function archiveSelected() {
    if (!selected) return;
    setBusy(true);
    try {
      await adminFetch(`locations/${tab}/${selected.public_id}/archive`, { method: 'POST' });
      setSelected(null);
      setConfirmArchive(false);
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Archive failed.');
    } finally {
      setBusy(false);
    }
  }

  const columns: AdminTableColumn<LocationRow>[] = [
    {
      key: 'name',
      label: 'Location',
      render: (r) => (
        <div>
          <div className="font-semibold text-slate-900 dark:text-white">{r.name}</div>
          <div className="text-xs text-slate-500">/{r.slug}</div>
        </div>
      ),
    },
    {
      key: 'status',
      label: 'Status',
      render: (r) => (
        <AdminStatusBadge status={r.status ?? (r.is_active === false ? 'archived' : 'active')} />
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
            Locations &amp; Geography
          </h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Manage states, cities, localities, and spatial directory coverage.
          </p>
        </div>

        <div className="flex rounded-xl border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900">
          {(['states', 'cities', 'localities'] as const).map((level) => (
            <button
              key={level}
              type="button"
              onClick={() => {
                setTab(level);
                setPage(1);
              }}
              className={`rounded-lg px-3 py-1.5 text-xs font-semibold capitalize ${
                tab === level ? 'bg-slate-950 text-white dark:bg-white dark:text-slate-950' : 'text-slate-600'
              }`}
            >
              {level}
            </button>
          ))}
        </div>
      </div>

      <AdminDataTable
        columns={columns}
        rows={rows}
        rowKey={(r) => r.public_id}
        loading={loading}
        error={error}
        onRetry={() => void load()}
        searchValue={search}
        searchPlaceholder={`Search ${tab}...`}
        onSearchChange={(val) => {
          setSearch(val);
          setPage(1);
        }}
        page={meta.current_page}
        lastPage={meta.last_page}
        total={meta.total}
        onPageChange={setPage}
        onRowClick={(r) => setSelected(r)}
      />

      <AdminDrawer
        open={selected !== null}
        title={selected?.name ?? 'Location'}
        subtitle={selected ? `${selected.public_id} · /${selected.slug}` : undefined}
        onClose={() => setSelected(null)}
      >
        {selected && (
          <div className="space-y-4 text-sm">
            <button
              type="button"
              disabled={busy}
              onClick={() => setConfirmArchive(true)}
              className="rounded-lg bg-amber-600 px-3.5 py-2 text-xs font-semibold text-white"
            >
              Archive Location
            </button>
          </div>
        )}
      </AdminDrawer>

      <AdminConfirmDialog
        open={confirmArchive}
        title="Confirm Location Archive"
        description="Archiving hides this location from public city/locality selectors."
        confirmLabel="Archive"
        variant="warning"
        loading={busy}
        onConfirm={() => void archiveSelected()}
        onCancel={() => setConfirmArchive(false)}
      />
    </div>
  );
}
