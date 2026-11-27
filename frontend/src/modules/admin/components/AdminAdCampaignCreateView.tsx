'use client';

import { FormEvent, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ArrowLeft } from 'lucide-react';
import { adminFetch } from '@/modules/admin/lib/admin-api';
import type { AdCampaignSummary } from './AdminAdCampaignsView';

export function AdminAdCampaignCreateView() {
  const router = useRouter();
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [organizationPublicId, setOrganizationPublicId] = useState('');
  const [campaignType, setCampaignType] = useState('sponsored_banner');
  const [status, setStatus] = useState<'draft' | 'pending_approval'>('draft');
  const [priority, setPriority] = useState('100');
  const [startsAt, setStartsAt] = useState('');
  const [endsAt, setEndsAt] = useState('');
  const [dailyCap, setDailyCap] = useState('');
  const [totalCap, setTotalCap] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const res = await adminFetch<AdCampaignSummary>('advertising/campaigns', {
        method: 'POST',
        body: {
          name: name.trim(),
          description: description.trim() || null,
          organization_public_id: organizationPublicId.trim() || null,
          campaign_type: campaignType,
          status,
          priority: Number(priority) || 100,
          starts_at: startsAt || null,
          ends_at: endsAt || null,
          daily_impression_cap: dailyCap ? Number(dailyCap) : null,
          total_impression_cap: totalCap ? Number(totalCap) : null,
        },
      });
      router.push(`/admin/advertising/campaigns/${res.data.public_id}`);
      router.refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create campaign.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <div>
        <Link
          href="/admin/advertising/campaigns"
          className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-slate-900"
        >
          <ArrowLeft className="h-3.5 w-3.5" />
          Back to Campaigns
        </Link>
        <h1 className="mt-1 text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
          Create Ad Campaign
        </h1>
      </div>

      {error && (
        <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-800">
          {error}
        </div>
      )}

      <form
        onSubmit={handleSubmit}
        className="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-xs dark:border-slate-800 dark:bg-slate-900"
      >
        <div>
          <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
            Campaign Name *
          </label>
          <input
            type="text"
            required
            value={name}
            onChange={(e) => setName(e.target.value)}
            className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 text-xs dark:border-slate-700 dark:bg-slate-800"
          />
        </div>

        <div>
          <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
            Description
          </label>
          <textarea
            rows={3}
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            className="mt-1 w-full rounded-lg border border-slate-200 p-2.5 text-xs dark:border-slate-700 dark:bg-slate-800"
          />
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
              Campaign Type
            </label>
            <select
              value={campaignType}
              onChange={(e) => setCampaignType(e.target.value)}
              aria-label="Campaign type"
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-2.5 text-xs dark:border-slate-700 dark:bg-slate-800"
            >
              <option value="sponsored_banner">Sponsored Banner</option>
              <option value="featured_listing">Featured Listing</option>
              <option value="category_spotlight">Category Spotlight</option>
              <option value="city_takeover">City Takeover</option>
              <option value="house_promo">House Promo</option>
            </select>
          </div>
          <div>
            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
              Initial State
            </label>
            <select
              value={status}
              onChange={(e) => setStatus(e.target.value as 'draft' | 'pending_approval')}
              aria-label="Initial campaign status"
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-2.5 text-xs dark:border-slate-700 dark:bg-slate-800"
            >
              <option value="draft">Draft</option>
              <option value="pending_approval">Pending Approval</option>
            </select>
          </div>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
              Organization Public ID (optional)
            </label>
            <input
              type="text"
              value={organizationPublicId}
              onChange={(e) => setOrganizationPublicId(e.target.value)}
              placeholder="01J..."
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 font-mono text-xs dark:border-slate-700 dark:bg-slate-800"
            />
          </div>
          <div>
            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
              Priority (1–1000)
            </label>
            <input
              type="number"
              min={1}
              max={1000}
              value={priority}
              onChange={(e) => setPriority(e.target.value)}
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 text-xs dark:border-slate-700 dark:bg-slate-800"
            />
          </div>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
              Starts At
            </label>
            <input
              type="datetime-local"
              value={startsAt}
              onChange={(e) => setStartsAt(e.target.value)}
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 text-xs dark:border-slate-700 dark:bg-slate-800"
            />
          </div>
          <div>
            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
              Ends At
            </label>
            <input
              type="datetime-local"
              value={endsAt}
              onChange={(e) => setEndsAt(e.target.value)}
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 text-xs dark:border-slate-700 dark:bg-slate-800"
            />
          </div>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
              Daily Impression Cap
            </label>
            <input
              type="number"
              min={1}
              value={dailyCap}
              onChange={(e) => setDailyCap(e.target.value)}
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 text-xs dark:border-slate-700 dark:bg-slate-800"
            />
          </div>
          <div>
            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
              Total Impression Cap
            </label>
            <input
              type="number"
              min={1}
              value={totalCap}
              onChange={(e) => setTotalCap(e.target.value)}
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 text-xs dark:border-slate-700 dark:bg-slate-800"
            />
          </div>
        </div>

        <button
          type="submit"
          disabled={busy || name.trim().length < 2}
          className="rounded-xl bg-slate-950 px-4 py-2 text-xs font-semibold text-white disabled:opacity-50 dark:bg-white dark:text-slate-950"
        >
          {busy ? 'Creating...' : 'Create Campaign'}
        </button>
      </form>
    </div>
  );
}
