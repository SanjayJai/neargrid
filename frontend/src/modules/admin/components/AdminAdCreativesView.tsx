'use client';

import { useCallback, useEffect, useState } from 'react';
import { Archive, Eye, Pencil, Plus, Upload } from 'lucide-react';
import { adminFetch, type AdminPaginatedMeta } from '@/modules/admin/lib/admin-api';
import { AdminConfirmDialog } from '@/modules/admin/ui/AdminConfirmDialog';
import { AdminDataTable, type AdminTableColumn } from '@/modules/admin/ui/AdminDataTable';
import { AdminDrawer } from '@/modules/admin/ui/AdminDrawer';
import { AdminStatusBadge } from '@/modules/admin/ui/AdminStatusBadge';
import { FallbackImage } from '@/shared/ui/FallbackImage';

interface AdCreativeRow {
  public_id: string;
  title: string;
  subtitle: string | null;
  cta_label: string | null;
  destination_url: string;
  image_url: string | null;
  mobile_image_url: string | null;
  alt_text: string | null;
  locale: 'en' | 'ta';
  status: string;
  campaign: { public_id: string; name: string; status: string } | null;
}

export function AdminAdCreativesView() {
  const [rows, setRows] = useState<AdCreativeRow[]>([]);
  const [meta, setMeta] = useState<AdminPaginatedMeta>({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [localeFilter, setLocaleFilter] = useState('');
  const [page, setPage] = useState(1);

  const [preview, setPreview] = useState<AdCreativeRow | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [editingCreative, setEditingCreative] = useState<AdCreativeRow | null>(null);
  const [archivingCreative, setArchivingCreative] = useState<AdCreativeRow | null>(null);
  const [campaignId, setCampaignId] = useState('');
  const [title, setTitle] = useState('');
  const [subtitle, setSubtitle] = useState('');
  const [ctaLabel, setCtaLabel] = useState('Learn More');
  const [destinationUrl, setDestinationUrl] = useState('/chennai');
  const [imageUrl, setImageUrl] = useState('');
  const [mobileImageUrl, setMobileImageUrl] = useState('');
  const [altText, setAltText] = useState('');
  const [locale, setLocale] = useState<'en' | 'ta'>('en');
  const [status, setStatus] = useState<'active' | 'paused' | 'rejected'>('active');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const qs = new URLSearchParams({ page: String(page) });
      if (search.trim()) qs.set('q', search.trim());
      if (localeFilter) qs.set('locale', localeFilter);
      const res = await adminFetch<AdCreativeRow[]>(`advertising/creatives?${qs.toString()}`);
      setRows(Array.isArray(res.data) ? res.data : []);
      if (res.meta) setMeta(res.meta);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load creatives.');
    } finally {
      setLoading(false);
    }
  }, [page, search, localeFilter]);

  useEffect(() => {
    void load();
  }, [load]);

  async function handleUploadImage(file: File) {
    setBusy(true);
    setFormError(null);
    try {
      const fd = new FormData();
      fd.append('image', file);
      if (campaignId.trim()) fd.append('campaign_public_id', campaignId.trim());
      const res = await fetch('/api/admin/advertising/creatives/upload-image', {
        method: 'POST',
        body: fd,
      });
      const json = await res.json();
      if (!res.ok) throw new Error(json?.error?.message || 'Upload failed.');
      setImageUrl(json.data.image_url);
    } catch (e) {
      setFormError(e instanceof Error ? e.message : 'Image upload failed.');
    } finally {
      setBusy(false);
    }
  }

  function openCreateDrawer() {
    setEditingCreative(null);
    setFormError(null);
    setCampaignId('');
    setTitle('');
    setSubtitle('');
    setCtaLabel('Learn More');
    setDestinationUrl('/chennai');
    setImageUrl('');
    setMobileImageUrl('');
    setAltText('');
    setLocale('en');
    setStatus('active');
    setFormOpen(true);
  }

  function openEditDrawer(row: AdCreativeRow) {
    setEditingCreative(row);
    setFormError(null);
    setCampaignId(row.campaign?.public_id ?? '');
    setTitle(row.title);
    setSubtitle(row.subtitle ?? '');
    setCtaLabel(row.cta_label ?? '');
    setDestinationUrl(row.destination_url);
    setImageUrl(row.image_url ?? '');
    setMobileImageUrl(row.mobile_image_url ?? '');
    setAltText(row.alt_text ?? '');
    setLocale(row.locale);
    setStatus(row.status === 'paused' || row.status === 'rejected' ? row.status : 'active');
    setFormOpen(true);
  }

  async function handleSaveCreative() {
    setBusy(true);
    setFormError(null);
    try {
      const payload = {
        title: title.trim(),
        subtitle: subtitle.trim() || null,
        cta_label: ctaLabel.trim() || null,
        destination_url: destinationUrl.trim(),
        image_url: imageUrl.trim() || null,
        mobile_image_url: mobileImageUrl.trim() || null,
        alt_text: altText.trim() || null,
        locale,
        status,
      };

      if (editingCreative) {
        await adminFetch(`advertising/creatives/${editingCreative.public_id}`, {
          method: 'PATCH',
          body: payload,
        });
      } else {
        await adminFetch('advertising/creatives', {
          method: 'POST',
          body: {
            ...payload,
            ad_campaign_public_id: campaignId.trim(),
          },
        });
      }

      setFormOpen(false);
      setEditingCreative(null);
      await load();
    } catch (e) {
      setFormError(
        e instanceof Error ? e.message : editingCreative ? 'Failed to update creative.' : 'Failed to create creative.'
      );
    } finally {
      setBusy(false);
    }
  }

  async function handleArchiveCreative() {
    if (!archivingCreative) return;
    setBusy(true);
    try {
      await adminFetch(`advertising/creatives/${archivingCreative.public_id}`, {
        method: 'DELETE',
      });
      setArchivingCreative(null);
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to archive creative.');
      setArchivingCreative(null);
    } finally {
      setBusy(false);
    }
  }

  const columns: AdminTableColumn<AdCreativeRow>[] = [
    {
      key: 'title',
      label: 'Creative',
      render: (r) => (
        <div>
          <div className="font-semibold text-slate-900 dark:text-white">{r.title}</div>
          <div className="text-xs text-slate-500">
            {r.campaign?.name ?? '—'} · <span className="uppercase">{r.locale}</span>
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
      key: 'actions',
      label: 'Actions',
      align: 'right',
      render: (r) => (
        <div className="inline-flex flex-wrap items-center justify-end gap-1.5">
          <button
            type="button"
            onClick={() => setPreview(r)}
            className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
          >
            <Eye className="h-3.5 w-3.5" />
            Preview
          </button>
          <button
            type="button"
            onClick={() => openEditDrawer(r)}
            className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
          >
            <Pencil className="h-3.5 w-3.5" />
            Edit
          </button>
          {r.status !== 'archived' && (
            <button
              type="button"
              onClick={() => setArchivingCreative(r)}
              className="inline-flex items-center gap-1 rounded-lg border border-rose-200 px-2.5 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50 dark:border-rose-900/60 dark:text-rose-300 dark:hover:bg-rose-950/40"
            >
              <Archive className="h-3.5 w-3.5" />
              Archive
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
            Ad Creatives &amp; Localized Banners
          </h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Manage English &amp; Tamil creatives, validated destination URLs, and image assets.
          </p>
        </div>
        <button
          type="button"
          onClick={openCreateDrawer}
          className="inline-flex items-center gap-1.5 rounded-xl bg-slate-950 px-3.5 py-2 text-xs font-semibold text-white dark:bg-white dark:text-slate-950"
        >
          <Plus className="h-4 w-4" />
          New Creative
        </button>
      </div>

      <AdminDataTable
        columns={columns}
        rows={rows}
        rowKey={(r) => r.public_id}
        loading={loading}
        error={error}
        onRetry={() => void load()}
        searchValue={search}
        searchPlaceholder="Search creative title or URL..."
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
            value={localeFilter}
            onChange={(e) => {
              setLocaleFilter(e.target.value);
              setPage(1);
            }}
            aria-label="Filter creative locale"
            className="h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs dark:border-slate-700 dark:bg-slate-800"
          >
            <option value="">All locales</option>
            <option value="en">English (en)</option>
            <option value="ta">Tamil (ta)</option>
          </select>
        }
      />

      <AdminDrawer
        open={preview !== null}
        title="Creative Preview"
        subtitle={preview ? `${preview.locale.toUpperCase()} · ${preview.campaign?.name ?? ''}` : undefined}
        onClose={() => setPreview(null)}
      >
        {preview && (
          <div className="space-y-4">
            <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950">
              <div className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-slate-400">
                Sponsored Preview
              </div>
              {preview.image_url && (
                <div className="mb-3 h-40 w-full overflow-hidden rounded-xl">
                  <FallbackImage
                    src={preview.image_url}
                    alt={preview.alt_text ?? preview.title}
                    className="h-full w-full object-cover"
                    fallbackLabel="Creative preview unavailable"
                  />
                </div>
              )}
              <div className="text-base font-bold text-slate-950 dark:text-white">{preview.title}</div>
              {preview.subtitle && (
                <p className="mt-1 text-xs text-slate-600 dark:text-slate-300">{preview.subtitle}</p>
              )}
              <div className="mt-3 flex items-center justify-between">
                <span className="font-mono text-[11px] text-slate-500">{preview.destination_url}</span>
                {preview.cta_label && (
                  <span className="rounded-lg bg-slate-950 px-3 py-1 text-xs font-semibold text-white dark:bg-white dark:text-slate-950">
                    {preview.cta_label}
                  </span>
                )}
              </div>
            </div>
          </div>
        )}
      </AdminDrawer>

      <AdminDrawer
        open={formOpen}
        title={editingCreative ? 'Edit Ad Creative' : 'Create Ad Creative'}
        subtitle={editingCreative ? `${editingCreative.public_id} · ${editingCreative.campaign?.name ?? ''}` : undefined}
        onClose={() => {
          setFormOpen(false);
          setEditingCreative(null);
        }}
      >
        <div className="space-y-3 text-xs">
          {formError && (
            <div className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-rose-800">
              {formError}
            </div>
          )}
          {!editingCreative && (
            <div>
              <label className="block font-semibold">Campaign Public ID *</label>
              <input
                type="text"
                value={campaignId}
                onChange={(e) => setCampaignId(e.target.value)}
                placeholder="01J..."
                className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 font-mono dark:border-slate-700 dark:bg-slate-800"
              />
            </div>
          )}
          <div className="grid grid-cols-2 gap-2">
            <div>
              <label className="block font-semibold">Locale *</label>
              <select
                value={locale}
                onChange={(e) => setLocale(e.target.value as 'en' | 'ta')}
                aria-label="Creative locale"
                className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-2.5 dark:border-slate-700 dark:bg-slate-800"
              >
                <option value="en">English (en)</option>
                <option value="ta">Tamil (ta)</option>
              </select>
            </div>
            <div>
              <label className="block font-semibold">Status</label>
              <select
                value={status}
                onChange={(e) => setStatus(e.target.value as 'active' | 'paused' | 'rejected')}
                aria-label="Creative status"
                className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-2.5 dark:border-slate-700 dark:bg-slate-800"
              >
                <option value="active">Active</option>
                <option value="paused">Paused</option>
                {editingCreative && <option value="rejected">Rejected</option>}
              </select>
            </div>
          </div>
          <div>
            <label className="block font-semibold">Title *</label>
            <input
              type="text"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 dark:border-slate-700 dark:bg-slate-800"
            />
          </div>
          <div>
            <label className="block font-semibold">Subtitle</label>
            <input
              type="text"
              value={subtitle}
              onChange={(e) => setSubtitle(e.target.value)}
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 dark:border-slate-700 dark:bg-slate-800"
            />
          </div>
          <div className="grid grid-cols-2 gap-2">
            <div>
              <label className="block font-semibold">CTA Label</label>
              <input
                type="text"
                value={ctaLabel}
                onChange={(e) => setCtaLabel(e.target.value)}
                className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 dark:border-slate-700 dark:bg-slate-800"
              />
            </div>
            <div>
              <label className="block font-semibold">Destination URL *</label>
              <input
                type="text"
                value={destinationUrl}
                onChange={(e) => setDestinationUrl(e.target.value)}
                className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 font-mono dark:border-slate-700 dark:bg-slate-800"
              />
            </div>
          </div>
          <div>
            <label className="block font-semibold">Desktop Image Asset</label>
            <div className="mt-1 flex items-center gap-2">
              <input
                type="text"
                value={imageUrl}
                onChange={(e) => setImageUrl(e.target.value)}
                placeholder="/storage/ads/..."
                className="h-9 flex-1 rounded-lg border border-slate-200 px-3 font-mono dark:border-slate-700 dark:bg-slate-800"
              />
              <label className="inline-flex cursor-pointer items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 font-semibold hover:bg-slate-50 dark:border-slate-700">
                <Upload className="h-3.5 w-3.5" />
                Upload
                <input
                  type="file"
                  accept="image/png,image/jpeg,image/webp"
                  className="hidden"
                  onChange={(e) => {
                    const f = e.target.files?.[0];
                    if (f) void handleUploadImage(f);
                  }}
                />
              </label>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <div>
              <label className="block font-semibold">Mobile Image URL</label>
              <input
                type="text"
                value={mobileImageUrl}
                onChange={(e) => setMobileImageUrl(e.target.value)}
                placeholder="/storage/ads/..."
                className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 font-mono dark:border-slate-700 dark:bg-slate-800"
              />
            </div>
            <div>
              <label className="block font-semibold">Alt Text</label>
              <input
                type="text"
                value={altText}
                onChange={(e) => setAltText(e.target.value)}
                className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 dark:border-slate-700 dark:bg-slate-800"
              />
            </div>
          </div>
          <button
            type="button"
            disabled={busy || (!editingCreative && !campaignId.trim()) || title.trim().length < 2}
            onClick={() => void handleSaveCreative()}
            className="rounded-lg bg-slate-950 px-4 py-2 font-semibold text-white disabled:opacity-50 dark:bg-white dark:text-slate-950"
          >
            {busy ? 'Saving...' : editingCreative ? 'Update Creative' : 'Save Creative'}
          </button>
        </div>
      </AdminDrawer>

      <AdminConfirmDialog
        open={archivingCreative !== null}
        title="Archive Ad Creative"
        description={
          archivingCreative
            ? `Archiving "${archivingCreative.title}" soft-deletes this creative and records the action in the audit log.`
            : ''
        }
        confirmLabel="Archive Creative"
        loading={busy}
        onConfirm={() => void handleArchiveCreative()}
        onCancel={() => setArchivingCreative(null)}
      />
    </div>
  );
}
