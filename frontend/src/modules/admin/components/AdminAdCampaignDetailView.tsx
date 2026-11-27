'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Archive, ArrowLeft, CheckCircle2, Save, XCircle } from 'lucide-react';
import { adminFetch } from '@/modules/admin/lib/admin-api';
import { AdminConfirmDialog } from '@/modules/admin/ui/AdminConfirmDialog';
import { AdminStatusBadge } from '@/modules/admin/ui/AdminStatusBadge';
import type { AdCampaignSummary } from './AdminAdCampaignsView';

interface CampaignDetailPayload {
  campaign: AdCampaignSummary;
  creatives: Array<{
    public_id: string;
    title: string;
    subtitle: string | null;
    cta_label: string | null;
    destination_url: string;
    locale: string;
    status: string;
  }>;
  placements: Array<{
    public_id: string;
    key: string;
    name: string;
    page_type: string;
    weight: number;
    is_active: boolean;
  }>;
  businesses: Array<{
    public_id: string;
    name: string;
    slug: string;
    status: string;
    is_primary: boolean;
  }>;
  available_placements: Array<{
    public_id: string;
    key: string;
    name: string;
    page_type: string;
  }>;
}

export function AdminAdCampaignDetailView({ publicId }: { publicId: string }) {
  const router = useRouter();
  const [data, setData] = useState<CampaignDetailPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [feedback, setFeedback] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [editName, setEditName] = useState('');
  const [editDescription, setEditDescription] = useState('');
  const [editPriority, setEditPriority] = useState('100');
  const [editStartsAt, setEditStartsAt] = useState('');
  const [editEndsAt, setEditEndsAt] = useState('');
  const [editDailyCap, setEditDailyCap] = useState('');
  const [editTotalCap, setEditTotalCap] = useState('');

  const [rejectionReason, setRejectionReason] = useState('');
  const [selectedPlacementIds, setSelectedPlacementIds] = useState<string[]>([]);
  const [businessIdsInput, setBusinessIdsInput] = useState('');
  const [confirmReject, setConfirmReject] = useState(false);
  const [confirmArchive, setConfirmArchive] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await adminFetch<CampaignDetailPayload>(`advertising/campaigns/${publicId}`);
      setData(res.data);
      const c = res.data.campaign;
      setEditName(c.name);
      setEditDescription(c.description ?? '');
      setEditPriority(String(c.priority));
      setEditStartsAt(c.starts_at ? c.starts_at.slice(0, 16) : '');
      setEditEndsAt(c.ends_at ? c.ends_at.slice(0, 16) : '');
      setEditDailyCap(c.daily_impression_cap != null ? String(c.daily_impression_cap) : '');
      setEditTotalCap(c.total_impression_cap != null ? String(c.total_impression_cap) : '');
      setSelectedPlacementIds(res.data.placements.map((p) => p.public_id));
      setBusinessIdsInput(res.data.businesses.map((b) => b.public_id).join(', '));
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load campaign.');
    } finally {
      setLoading(false);
    }
  }, [publicId]);

  useEffect(() => {
    void load();
  }, [load]);

  async function approveCampaign() {
    setBusy(true);
    setFeedback(null);
    try {
      await adminFetch(`advertising/campaigns/${publicId}/approve`, {
        method: 'POST',
        body: { activate_immediately: true },
      });
      setFeedback('Campaign approved.');
      await load();
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Approval failed.');
    } finally {
      setBusy(false);
    }
  }

  async function rejectCampaign() {
    setBusy(true);
    setFeedback(null);
    try {
      await adminFetch(`advertising/campaigns/${publicId}/reject`, {
        method: 'POST',
        body: { rejection_reason: rejectionReason.trim() },
      });
      setConfirmReject(false);
      setRejectionReason('');
      setFeedback('Campaign rejected.');
      await load();
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Rejection failed.');
    } finally {
      setBusy(false);
    }
  }

  async function changeStatus(status: string) {
    setBusy(true);
    setFeedback(null);
    try {
      await adminFetch(`advertising/campaigns/${publicId}/status`, {
        method: 'PATCH',
        body: { status },
      });
      setFeedback(`Status updated to ${status}.`);
      await load();
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Status update failed.');
    } finally {
      setBusy(false);
    }
  }

  async function savePlacements() {
    setBusy(true);
    setFeedback(null);
    try {
      await adminFetch(`advertising/campaigns/${publicId}/placements`, {
        method: 'PUT',
        body: {
          placements: selectedPlacementIds.map((id) => ({
            placement_public_id: id,
            weight: 100,
            is_active: true,
          })),
        },
      });
      setFeedback('Placement assignments saved.');
      await load();
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Failed to sync placements.');
    } finally {
      setBusy(false);
    }
  }

  async function saveCampaignDetails() {
    setBusy(true);
    setFeedback(null);
    try {
      await adminFetch(`advertising/campaigns/${publicId}`, {
        method: 'PATCH',
        body: {
          name: editName.trim(),
          description: editDescription.trim() || null,
          priority: Number(editPriority) || 100,
          starts_at: editStartsAt || null,
          ends_at: editEndsAt || null,
          daily_impression_cap: editDailyCap ? Number(editDailyCap) : null,
          total_impression_cap: editTotalCap ? Number(editTotalCap) : null,
        },
      });
      setFeedback('Campaign details updated.');
      await load();
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Failed to update campaign.');
    } finally {
      setBusy(false);
    }
  }

  async function archiveCampaign() {
    setBusy(true);
    setFeedback(null);
    try {
      await adminFetch(`advertising/campaigns/${publicId}`, {
        method: 'DELETE',
      });
      setConfirmArchive(false);
      router.push('/admin/advertising/campaigns');
      router.refresh();
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Failed to archive campaign.');
      setBusy(false);
    }
  }

  async function saveBusinesses() {
    setBusy(true);
    setFeedback(null);
    try {
      const ids = businessIdsInput
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean);
      await adminFetch(`advertising/campaigns/${publicId}/businesses`, {
        method: 'PUT',
        body: { business_public_ids: ids },
      });
      setFeedback('Business assignments saved.');
      await load();
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Failed to sync businesses.');
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <div className="p-6 text-sm text-slate-500">Loading campaign...</div>;
  if (error || !data) {
    return (
      <div className="rounded-xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-800">
        {error ?? 'Campaign not found.'}
      </div>
    );
  }

  const { campaign, creatives, available_placements, businesses } = data;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <Link
            href="/admin/advertising/campaigns"
            className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-slate-900"
          >
            <ArrowLeft className="h-3.5 w-3.5" />
            Back to Campaigns
          </Link>
          <h1 className="mt-1 text-2xl font-bold text-slate-950 dark:text-white">
            {campaign.name}
          </h1>
          <p className="text-xs text-slate-500">
            {campaign.public_id} · {campaign.campaign_type} · {campaign.organization?.name ?? 'Platform House'}
          </p>
        </div>
        <AdminStatusBadge status={campaign.status} />
      </div>

      {feedback && (
        <div className="rounded-xl border border-sky-200 bg-sky-50 px-4 py-2.5 text-xs font-medium text-sky-900 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-200">
          {feedback}
        </div>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        <div className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
          <div className="text-xs font-semibold uppercase tracking-wider text-slate-500">
            Approval &amp; Lifecycle Controls
          </div>
          {campaign.rejection_reason && (
            <div className="rounded-lg bg-rose-50 p-3 text-xs text-rose-800">
              Rejection reason: {campaign.rejection_reason}
            </div>
          )}
          <div className="flex flex-wrap gap-2">
            {['draft', 'pending_approval', 'rejected'].includes(campaign.status) && (
              <button
                type="button"
                disabled={busy}
                onClick={() => void approveCampaign()}
                className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white"
              >
                <CheckCircle2 className="h-3.5 w-3.5" />
                Approve &amp; Activate
              </button>
            )}
            {campaign.status === 'active' && (
              <button
                type="button"
                disabled={busy}
                onClick={() => void changeStatus('paused')}
                className="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white"
              >
                Pause
              </button>
            )}
            {campaign.status === 'paused' && (
              <button
                type="button"
                disabled={busy}
                onClick={() => void changeStatus('active')}
                className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white"
              >
                Resume
              </button>
            )}
            {campaign.status !== 'archived' && (
              <button
                type="button"
                disabled={busy}
                onClick={() => setConfirmArchive(true)}
                className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300"
              >
                <Archive className="h-3.5 w-3.5" />
                Archive
              </button>
            )}
          </div>

          <div className="space-y-2 border-t border-slate-100 pt-3 dark:border-slate-800">
            <input
              type="text"
              value={rejectionReason}
              onChange={(e) => setRejectionReason(e.target.value)}
              placeholder="Mandatory rejection reason (min 5 chars)"
              className="h-9 w-full rounded-lg border border-slate-200 px-3 text-xs dark:border-slate-700 dark:bg-slate-800"
            />
            <button
              type="button"
              disabled={busy || rejectionReason.trim().length < 5}
              onClick={() => setConfirmReject(true)}
              className="inline-flex items-center gap-1.5 rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50"
            >
              <XCircle className="h-3.5 w-3.5" />
              Reject Campaign
            </button>
          </div>
        </div>

        <div className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
          <div className="text-xs font-semibold uppercase tracking-wider text-slate-500">
            Assigned Businesses ({businesses.length})
          </div>
          <input
            type="text"
            value={businessIdsInput}
            onChange={(e) => setBusinessIdsInput(e.target.value)}
            placeholder="Comma-separated business public_ids"
            className="h-9 w-full rounded-lg border border-slate-200 px-3 font-mono text-xs dark:border-slate-700 dark:bg-slate-800"
          />
          <button
            type="button"
            disabled={busy}
            onClick={() => void saveBusinesses()}
            className="rounded-lg bg-slate-950 px-3.5 py-1.5 text-xs font-semibold text-white dark:bg-white dark:text-slate-950"
          >
            Save Business Assignments
          </button>
        </div>
      </div>

      <div className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-center justify-between">
          <div className="text-xs font-semibold uppercase tracking-wider text-slate-500">
            Campaign Settings &amp; Schedule
          </div>
          <button
            type="button"
            disabled={busy || editName.trim().length < 2}
            onClick={() => void saveCampaignDetails()}
            className="inline-flex items-center gap-1.5 rounded-lg bg-slate-950 px-3.5 py-1.5 text-xs font-semibold text-white disabled:opacity-50 dark:bg-white dark:text-slate-950"
          >
            <Save className="h-3.5 w-3.5" />
            Save Details
          </button>
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <label className="block text-xs font-semibold text-slate-600 dark:text-slate-300">Name</label>
            <input
              type="text"
              value={editName}
              onChange={(e) => setEditName(e.target.value)}
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 text-xs dark:border-slate-700 dark:bg-slate-800"
            />
          </div>
          <div>
            <label className="block text-xs font-semibold text-slate-600 dark:text-slate-300">Priority (1–1000)</label>
            <input
              type="number"
              min={1}
              max={1000}
              value={editPriority}
              onChange={(e) => setEditPriority(e.target.value)}
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 text-xs dark:border-slate-700 dark:bg-slate-800"
            />
          </div>
          <div className="sm:col-span-2">
            <label className="block text-xs font-semibold text-slate-600 dark:text-slate-300">Description</label>
            <textarea
              rows={2}
              value={editDescription}
              onChange={(e) => setEditDescription(e.target.value)}
              className="mt-1 w-full rounded-lg border border-slate-200 p-2.5 text-xs dark:border-slate-700 dark:bg-slate-800"
            />
          </div>
          <div>
            <label className="block text-xs font-semibold text-slate-600 dark:text-slate-300">Starts At</label>
            <input
              type="datetime-local"
              value={editStartsAt}
              onChange={(e) => setEditStartsAt(e.target.value)}
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 text-xs dark:border-slate-700 dark:bg-slate-800"
            />
          </div>
          <div>
            <label className="block text-xs font-semibold text-slate-600 dark:text-slate-300">Ends At</label>
            <input
              type="datetime-local"
              value={editEndsAt}
              onChange={(e) => setEditEndsAt(e.target.value)}
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 text-xs dark:border-slate-700 dark:bg-slate-800"
            />
          </div>
          <div>
            <label className="block text-xs font-semibold text-slate-600 dark:text-slate-300">Daily Impression Cap</label>
            <input
              type="number"
              min={1}
              value={editDailyCap}
              onChange={(e) => setEditDailyCap(e.target.value)}
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 text-xs dark:border-slate-700 dark:bg-slate-800"
            />
          </div>
          <div>
            <label className="block text-xs font-semibold text-slate-600 dark:text-slate-300">Total Impression Cap</label>
            <input
              type="number"
              min={1}
              value={editTotalCap}
              onChange={(e) => setEditTotalCap(e.target.value)}
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 text-xs dark:border-slate-700 dark:bg-slate-800"
            />
          </div>
        </div>
      </div>

      <div className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-center justify-between">
          <div className="text-xs font-semibold uppercase tracking-wider text-slate-500">
            Placement Assignments ({selectedPlacementIds.length} selected)
          </div>
          <button
            type="button"
            disabled={busy}
            onClick={() => void savePlacements()}
            className="rounded-lg bg-slate-950 px-3.5 py-1.5 text-xs font-semibold text-white dark:bg-white dark:text-slate-950"
          >
            Save Placements
          </button>
        </div>
        <div className="grid max-h-64 gap-2 overflow-y-auto sm:grid-cols-2 lg:grid-cols-3">
          {available_placements.map((pl) => {
            const checked = selectedPlacementIds.includes(pl.public_id);
            return (
              <label
                key={pl.public_id}
                className="flex items-start gap-2 rounded-lg border border-slate-100 p-2 text-xs dark:border-slate-800"
              >
                <input
                  type="checkbox"
                  checked={checked}
                  onChange={(e) => {
                    if (e.target.checked) {
                      setSelectedPlacementIds((prev) => [...prev, pl.public_id]);
                    } else {
                      setSelectedPlacementIds((prev) => prev.filter((id) => id !== pl.public_id));
                    }
                  }}
                />
                <div>
                  <div className="font-semibold">{pl.name}</div>
                  <div className="font-mono text-[10px] text-slate-500">{pl.key}</div>
                </div>
              </label>
            );
          })}
        </div>
      </div>

      <div className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-center justify-between">
          <div className="text-xs font-semibold uppercase tracking-wider text-slate-500">
            Campaign Creatives ({creatives.length})
          </div>
          <Link
            href="/admin/advertising/creatives"
            className="text-xs font-semibold text-sky-600 hover:underline"
          >
            Manage Creatives →
          </Link>
        </div>
        <div className="grid gap-3 md:grid-cols-2">
          {creatives.map((cr) => (
            <div key={cr.public_id} className="rounded-xl border border-slate-100 p-3 text-xs dark:border-slate-800">
              <div className="flex items-center justify-between">
                <span className="font-semibold">{cr.title}</span>
                <span className="font-mono uppercase text-slate-500">{cr.locale}</span>
              </div>
              {cr.subtitle && <p className="mt-1 text-slate-500">{cr.subtitle}</p>}
              <div className="mt-2 font-mono text-[11px] text-sky-600">{cr.destination_url}</div>
            </div>
          ))}
        </div>
      </div>

      <AdminConfirmDialog
        open={confirmReject}
        title="Confirm Campaign Rejection"
        description="Rejecting transitions this campaign to rejected and records your mandatory reason in the audit log."
        confirmLabel="Reject Campaign"
        loading={busy}
        onConfirm={() => void rejectCampaign()}
        onCancel={() => setConfirmReject(false)}
      />

      <AdminConfirmDialog
        open={confirmArchive}
        title="Archive Campaign"
        description="Archiving soft-deletes this campaign and records the action in the audit log."
        confirmLabel="Archive Campaign"
        loading={busy}
        onConfirm={() => void archiveCampaign()}
        onCancel={() => setConfirmArchive(false)}
      />
    </div>
  );
}
