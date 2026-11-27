'use client';

import { useCallback, useEffect, useState } from 'react';
import { AlertTriangle, Settings2 } from 'lucide-react';
import { adminFetch, type AdminPaginatedMeta } from '@/modules/admin/lib/admin-api';
import { AdminConfirmDialog } from '@/modules/admin/ui/AdminConfirmDialog';
import { AdminDataTable, type AdminTableColumn } from '@/modules/admin/ui/AdminDataTable';
import { AdminDrawer } from '@/modules/admin/ui/AdminDrawer';
import { AdminStatusBadge } from '@/modules/admin/ui/AdminStatusBadge';

export interface AdPlacementRow {
  public_id: string;
  key: string;
  name: string;
  description: string | null;
  page_type: string;
  route_pattern: string;
  slot_position: string;
  render_format: string;
  device_scope: string;
  audience_scope: string;
  max_ads: number;
  width_px: number | null;
  height_px: number | null;
  supports_category_targeting: boolean;
  supports_location_targeting: boolean;
  supports_domain_targeting: boolean;
  is_active: boolean;
  sort_order: number;
  campaigns_count: number;
}

interface PlacementConflict {
  page_type: string;
  slot_position: string;
  reason: string;
}

export function AdminAdPlacementsView() {
  const [rows, setRows] = useState<AdPlacementRow[]>([]);
  const [conflicts, setConflicts] = useState<PlacementConflict[]>([]);
  const [meta, setMeta] = useState<AdminPaginatedMeta>({ current_page: 1, last_page: 1, per_page: 50, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [pageTypeFilter, setPageTypeFilter] = useState('');
  const [page, setPage] = useState(1);

  const [editing, setEditing] = useState<AdPlacementRow | null>(null);
  const [toggling, setToggling] = useState<AdPlacementRow | null>(null);
  const [busy, setBusy] = useState(false);

  const [name, setName] = useState('');
  const [maxAds, setMaxAds] = useState('1');
  const [deviceScope, setDeviceScope] = useState('all');
  const [audienceScope, setAudienceScope] = useState('all');
  const [renderFormat, setRenderFormat] = useState('banner');
  const [widthPx, setWidthPx] = useState('');
  const [heightPx, setHeightPx] = useState('');
  const [isActive, setIsActive] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const qs = new URLSearchParams({ page: String(page), per_page: '50' });
      if (search.trim()) qs.set('q', search.trim());
      if (pageTypeFilter) qs.set('page_type', pageTypeFilter);
      const res = await adminFetch<AdPlacementRow[]>(`advertising/placements?${qs.toString()}`);
      setRows(Array.isArray(res.data) ? res.data : []);
      if (res.meta) setMeta(res.meta);
      const extraConflicts = (res as { meta?: { conflicts?: PlacementConflict[] } }).meta?.conflicts;
      setConflicts(Array.isArray(extraConflicts) ? extraConflicts : []);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load ad placements.');
    } finally {
      setLoading(false);
    }
  }, [page, search, pageTypeFilter]);

  useEffect(() => {
    void load();
  }, [load]);

  function openConfig(row: AdPlacementRow) {
    setEditing(row);
    setName(row.name);
    setMaxAds(String(row.max_ads));
    setDeviceScope(row.device_scope);
    setAudienceScope(row.audience_scope);
    setRenderFormat(row.render_format);
    setWidthPx(row.width_px != null ? String(row.width_px) : '');
    setHeightPx(row.height_px != null ? String(row.height_px) : '');
    setIsActive(row.is_active);
  }

  async function saveConfig() {
    if (!editing) return;
    setBusy(true);
    try {
      await adminFetch(`advertising/placements/${editing.public_id}`, {
        method: 'PATCH',
        body: {
          name: name.trim(),
          max_ads: Number(maxAds) || 1,
          device_scope: deviceScope,
          audience_scope: audienceScope,
          render_format: renderFormat,
          width_px: widthPx ? Number(widthPx) : null,
          height_px: heightPx ? Number(heightPx) : null,
          is_active: isActive,
        },
      });
      setEditing(null);
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to update placement.');
    } finally {
      setBusy(false);
    }
  }

  async function confirmToggle() {
    if (!toggling) return;
    setBusy(true);
    try {
      await adminFetch(`advertising/placements/${toggling.public_id}`, {
        method: 'PATCH',
        body: { is_active: !toggling.is_active },
      });
      setToggling(null);
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to toggle placement.');
    } finally {
      setBusy(false);
    }
  }

  const columns: AdminTableColumn<AdPlacementRow>[] = [
    {
      key: 'name',
      label: 'Placement',
      render: (r) => (
        <div>
          <div className="font-semibold text-slate-900 dark:text-white">{r.name}</div>
          <div className="font-mono text-[11px] text-slate-500">
            {r.key} · {r.route_pattern}
          </div>
        </div>
      ),
    },
    {
      key: 'scope',
      label: 'Format & Scope',
      render: (r) => (
        <div className="text-xs text-slate-600 dark:text-slate-300">
          <span className="font-semibold">{r.render_format}</span> · {r.device_scope} · {r.audience_scope}
          <div className="text-[11px] text-slate-500">
            max {r.max_ads} {r.width_px && r.height_px ? `· ${r.width_px}×${r.height_px}px` : ''}
          </div>
        </div>
      ),
    },
    {
      key: 'status',
      label: 'Status',
      render: (r) => <AdminStatusBadge status={r.is_active ? 'active' : 'paused'} />,
    },
    {
      key: 'actions',
      label: 'Actions',
      align: 'right',
      render: (r) => (
        <div className="inline-flex items-center gap-1.5">
          <button
            type="button"
            onClick={() => openConfig(r)}
            className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
          >
            <Settings2 className="h-3.5 w-3.5" />
            Configure
          </button>
          <button
            type="button"
            onClick={() => setToggling(r)}
            className="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
          >
            {r.is_active ? 'Disable' : 'Enable'}
          </button>
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
          Ad Placement Registry (30+ Slots)
        </h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          Data-driven placement configuration, device/audience visibility, render formats, and slot conflict detection.
        </p>
      </div>

      {conflicts.length > 0 && (
        <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200">
          <div className="flex items-center gap-2 font-semibold">
            <AlertTriangle className="h-4 w-4" />
            {conflicts.length} Placement Conflict(s) Detected
          </div>
          <ul className="mt-2 list-disc space-y-1 pl-5">
            {conflicts.map((c, idx) => (
              <li key={idx}>{c.reason}</li>
            ))}
          </ul>
        </div>
      )}

      <AdminDataTable
        columns={columns}
        rows={rows}
        rowKey={(r) => r.public_id}
        loading={loading}
        error={error}
        onRetry={() => void load()}
        searchValue={search}
        searchPlaceholder="Search placement key, name or route..."
        onSearchChange={(val) => {
          setSearch(val);
          setPage(1);
        }}
        page={meta.current_page}
        lastPage={meta.last_page}
        total={meta.total}
        onPageChange={setPage}
        toolbarExtra={
          <select
            value={pageTypeFilter}
            onChange={(e) => {
              setPageTypeFilter(e.target.value);
              setPage(1);
            }}
            aria-label="Filter page type"
            className="h-9 rounded-lg border border-slate-200 bg-white px-2.5 text-xs dark:border-slate-700 dark:bg-slate-800"
          >
            <option value="">All page types</option>
            <option value="home">Home</option>
            <option value="search">Search</option>
            <option value="category">Category</option>
            <option value="city_hub">City Hub</option>
            <option value="business_profile">Business Profile</option>
            <option value="account">Account</option>
            <option value="global">Global</option>
          </select>
        }
      />

      <AdminDrawer
        open={editing !== null}
        title={editing ? `Configure: ${editing.key}` : 'Configure Placement'}
        subtitle={editing ? `${editing.page_type} · ${editing.slot_position}` : undefined}
        onClose={() => setEditing(null)}
      >
        {editing && (
          <div className="space-y-4 text-xs">
            <div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-950">
              <div className="text-[10px] font-semibold uppercase tracking-wider text-slate-400">
                Data-Driven Slot Preview ({renderFormat} · {deviceScope})
              </div>
              <div className="mt-2 flex items-center justify-center rounded-lg border border-slate-200 bg-white p-3 text-center text-[11px] font-medium text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
                {name || editing.name} — up to {maxAds} ad(s)
                {widthPx && heightPx ? ` (${widthPx}×${heightPx}px)` : ''}
              </div>
            </div>

            <div>
              <label className="block font-semibold">Placement Name</label>
              <input
                type="text"
                value={name}
                onChange={(e) => setName(e.target.value)}
                className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 dark:border-slate-700 dark:bg-slate-800"
              />
            </div>

            <div className="grid grid-cols-2 gap-2">
              <div>
                <label className="block font-semibold">Render Format</label>
                <select
                  value={renderFormat}
                  onChange={(e) => setRenderFormat(e.target.value)}
                  className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-2.5 dark:border-slate-700 dark:bg-slate-800"
                >
                  <option value="banner">Banner</option>
                  <option value="card">Card</option>
                  <option value="native_listing">Native Listing</option>
                  <option value="strip">Strip</option>
                  <option value="carousel">Carousel</option>
                  <option value="text_link">Text Link</option>
                </select>
              </div>
              <div>
                <label className="block font-semibold">Max Ads (1–20)</label>
                <input
                  type="number"
                  min={1}
                  max={20}
                  value={maxAds}
                  onChange={(e) => setMaxAds(e.target.value)}
                  className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 dark:border-slate-700 dark:bg-slate-800"
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-2">
              <div>
                <label className="block font-semibold">Device Scope</label>
                <select
                  value={deviceScope}
                  onChange={(e) => setDeviceScope(e.target.value)}
                  className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-2.5 dark:border-slate-700 dark:bg-slate-800"
                >
                  <option value="all">All Devices</option>
                  <option value="desktop">Desktop</option>
                  <option value="tablet">Tablet</option>
                  <option value="mobile">Mobile</option>
                </select>
              </div>
              <div>
                <label className="block font-semibold">Audience Scope</label>
                <select
                  value={audienceScope}
                  onChange={(e) => setAudienceScope(e.target.value)}
                  className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-2.5 dark:border-slate-700 dark:bg-slate-800"
                >
                  <option value="all">All Audiences</option>
                  <option value="guest">Guest</option>
                  <option value="customer">Customer</option>
                  <option value="merchant">Merchant</option>
                </select>
              </div>
            </div>

            <div className="grid grid-cols-2 gap-2">
              <div>
                <label className="block font-semibold">Width (px)</label>
                <input
                  type="number"
                  min={50}
                  max={2400}
                  value={widthPx}
                  onChange={(e) => setWidthPx(e.target.value)}
                  className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 dark:border-slate-700 dark:bg-slate-800"
                />
              </div>
              <div>
                <label className="block font-semibold">Height (px)</label>
                <input
                  type="number"
                  min={20}
                  max={1200}
                  value={heightPx}
                  onChange={(e) => setHeightPx(e.target.value)}
                  className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 dark:border-slate-700 dark:bg-slate-800"
                />
              </div>
            </div>

            <label className="flex items-center gap-2 font-semibold">
              <input
                type="checkbox"
                checked={isActive}
                onChange={(e) => setIsActive(e.target.checked)}
              />
              Placement Enabled
            </label>

            <button
              type="button"
              disabled={busy || name.trim().length < 2}
              onClick={() => void saveConfig()}
              className="rounded-lg bg-slate-950 px-4 py-2 font-semibold text-white disabled:opacity-50 dark:bg-white dark:text-slate-950"
            >
              {busy ? 'Saving...' : 'Save Placement'}
            </button>
          </div>
        )}
      </AdminDrawer>

      <AdminConfirmDialog
        open={toggling !== null}
        title={toggling?.is_active ? 'Disable Ad Placement' : 'Enable Ad Placement'}
        description={
          toggling
            ? `Are you sure you want to ${toggling.is_active ? 'disable' : 'enable'} "${toggling.name}" (${toggling.key})?`
            : ''
        }
        confirmLabel={toggling?.is_active ? 'Disable' : 'Enable'}
        loading={busy}
        onConfirm={() => void confirmToggle()}
        onCancel={() => setToggling(null)}
      />
    </div>
  );
}
