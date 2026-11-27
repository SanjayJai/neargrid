'use client';

import { FormEvent, useState } from 'react';
import Link from 'next/link';
import {
  Bell,
  Bookmark,
  CheckCircle2,
  MessageSquare,
  Save,
  User as UserIcon,
} from 'lucide-react';
import type { UserDto } from '@/lib/bff/types';
import { bffFetch } from '@/shared/api/bff-client';

interface CustomerProfileViewProps {
  initialUser: UserDto;
}

export function CustomerProfileView({ initialUser }: CustomerProfileViewProps) {
  const [name, setName] = useState(initialUser.name ?? '');
  const [email, setEmail] = useState(initialUser.email ?? '');
  const [saving, setSaving] = useState(false);
  const [status, setStatus] = useState<{ type: 'success' | 'error'; text: string } | null>(
    null
  );

  const hasBusinessAccess =
    initialUser.roles?.some((r) =>
      ['business_owner', 'business_staff', 'super_admin'].includes(r)
    ) ?? false;

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setStatus(null);
    try {
      const res = await bffFetch<UserDto>('/api/auth/me', {
        method: 'PATCH',
        body: JSON.stringify({
          name: name.trim() || null,
          email: email.trim() || null,
        }),
      });
      if (!res.ok || !res.payload || !('data' in res.payload)) {
        const msg =
          res.payload && 'error' in res.payload
            ? res.payload.error.message
            : 'Unable to update your profile.';
        setStatus({ type: 'error', text: msg });
        return;
      }
      setName(res.payload.data.name ?? '');
      setEmail(res.payload.data.email ?? '');
      setStatus({ type: 'success', text: 'Your profile has been updated.' });
    } catch {
      setStatus({ type: 'error', text: 'Network error while saving your profile.' });
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-extrabold tracking-tight text-ink">
          My Account
        </h1>
        <p className="mt-1 text-sm text-ink-muted">
          Manage your NearGrid profile and jump to your saved businesses and enquiries.
        </p>
      </div>

      {/* Quick Account Shortcuts */}
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <Link
          href="/saved"
          className="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm hover:border-brand-300"
        >
          <Bookmark className="h-5 w-5 text-brand-600" aria-hidden="true" />
          <div>
            <p className="text-sm font-bold text-ink">Saved Businesses</p>
            <p className="text-xs text-ink-muted">Your bookmarked listings</p>
          </div>
        </Link>
        <Link
          href="/account/enquiries"
          className="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm hover:border-brand-300"
        >
          <MessageSquare className="h-5 w-5 text-brand-600" aria-hidden="true" />
          <div>
            <p className="text-sm font-bold text-ink">My Enquiries</p>
            <p className="text-xs text-ink-muted">Track sent enquiries</p>
          </div>
        </Link>
        <Link
          href="/notifications"
          className="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm hover:border-brand-300"
        >
          <Bell className="h-5 w-5 text-brand-600" aria-hidden="true" />
          <div>
            <p className="text-sm font-bold text-ink">Notifications</p>
            <p className="text-xs text-ink-muted">Alerts &amp; updates</p>
          </div>
        </Link>
      </div>

      {/* Profile Form */}
      <form
        onSubmit={handleSubmit}
        className="space-y-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm"
      >
        <h2 className="flex items-center gap-2 text-base font-bold text-ink">
          <UserIcon className="h-4 w-4 text-brand-600" aria-hidden="true" />
          Personal Details
        </h2>

        {status && (
          <div
            role="status"
            className={`flex items-center gap-2 rounded-xl border px-4 py-3 text-sm ${
              status.type === 'success'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
                : 'border-rose-200 bg-rose-50 text-rose-900'
            }`}
          >
            {status.type === 'success' && (
              <CheckCircle2 className="h-4 w-4 shrink-0" aria-hidden="true" />
            )}
            <span>{status.text}</span>
          </div>
        )}

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label htmlFor="cust-name" className="mb-1 block text-xs font-semibold text-ink">
              Full Name
            </label>
            <input
              id="cust-name"
              type="text"
              maxLength={120}
              value={name}
              onChange={(e) => setName(e.target.value)}
              className="w-full rounded-xl border border-gray-300 px-3.5 py-2 text-sm text-ink focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
            />
          </div>

          <div>
            <label htmlFor="cust-email" className="mb-1 block text-xs font-semibold text-ink">
              Email Address
            </label>
            <input
              id="cust-email"
              type="email"
              maxLength={180}
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className="w-full rounded-xl border border-gray-300 px-3.5 py-2 text-sm text-ink focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600"
            />
          </div>

          <div>
            <label htmlFor="cust-phone" className="mb-1 block text-xs font-semibold text-ink">
              Verified Phone
            </label>
            <input
              id="cust-phone"
              type="text"
              disabled
              value={initialUser.phone_e164 ?? ''}
              className="w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2 text-sm text-ink-muted"
            />
          </div>
        </div>

        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4">
          {hasBusinessAccess ? (
            <Link
              href="/dashboard/settings"
              className="text-xs font-semibold text-brand-600 hover:text-brand-700"
            >
              Go to Business Owner Settings →
            </Link>
          ) : (
            <span />
          )}

          <button
            type="submit"
            disabled={saving}
            className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
          >
            <Save className="h-4 w-4" aria-hidden="true" />
            <span>{saving ? 'Saving...' : 'Save Profile'}</span>
          </button>
        </div>
      </form>
    </div>
  );
}
