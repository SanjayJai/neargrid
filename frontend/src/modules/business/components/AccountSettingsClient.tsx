'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { FormEvent, useCallback, useEffect, useState } from 'react';
import { Laptop, LogOut, ShieldCheck, Smartphone, Trash2 } from 'lucide-react';
import type { SessionTokenDto, UserDeviceDto, UserDto } from '@/lib/bff/types';
import { bffFetch } from '@/shared/api/bff-client';

interface AccountSettingsClientProps {
  initialUser: UserDto | null;
}

export function AccountSettingsClient({ initialUser }: AccountSettingsClientProps) {
  const router = useRouter();
  const [user, setUser] = useState<UserDto | null>(initialUser);
  const [name, setName] = useState(initialUser?.name ?? '');
  const [email, setEmail] = useState(initialUser?.email ?? '');
  const [devices, setDevices] = useState<UserDeviceDto[]>([]);
  const [sessions, setSessions] = useState<SessionTokenDto[]>([]);
  const [loading, setLoading] = useState(Boolean(initialUser));
  const [saving, setSaving] = useState(false);
  const [feedback, setFeedback] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const loadLiveSecurityState = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [devicesRes, sessionsRes] = await Promise.all([
        bffFetch<UserDeviceDto[]>('/api/auth/devices'),
        bffFetch<SessionTokenDto[]>('/api/auth/sessions'),
      ]);

      if (devicesRes.status === 401 || sessionsRes.status === 401) {
        setUser(null);
        return;
      }

      if (devicesRes.ok && devicesRes.payload?.status === 'success') {
        setDevices(devicesRes.payload.data);
      }
      if (sessionsRes.ok && sessionsRes.payload?.status === 'success') {
        setSessions(sessionsRes.payload.data);
      }
    } catch {
      setError('Unable to reach Next.js BFF or Laravel Identity API.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (initialUser) {
      void loadLiveSecurityState();
    }
  }, [initialUser, loadLiveSecurityState]);

  async function handleUpdateProfile(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setFeedback(null);
    setError(null);

    try {
      const res = await bffFetch<UserDto>('/api/auth/me', {
        method: 'PATCH',
        body: {
          name: name.trim(),
          email: email.trim() === '' ? null : email.trim(),
        },
      });

      if (!res.ok || res.payload?.status !== 'success') {
        setError(res.payload?.status === 'error' ? res.payload.error.message : 'Update failed.');
        return;
      }

      setUser(res.payload.data);
      setFeedback('Account profile saved via Laravel PATCH /api/v1/auth/me.');
      router.refresh();
    } finally {
      setSaving(false);
    }
  }

  async function handleRegisterBrowserDevice() {
    setFeedback(null);
    setError(null);
    const res = await bffFetch<UserDeviceDto>('/api/auth/devices', {
      method: 'POST',
      body: {
        platform: 'web',
        device_identifier: `web-${window.navigator.platform || 'browser'}`.slice(0, 60),
        app_version: '0.1.0',
        bind_current_session: true,
      },
    });

    if (res.ok) {
      setFeedback('Current browser device registered and linked to this session.');
      await loadLiveSecurityState();
    } else {
      setError(res.payload?.status === 'error' ? res.payload.error.message : 'Unable to register device.');
    }
  }

  async function handleRevokeDevice(publicId: string) {
    const res = await bffFetch(`/api/auth/devices/${encodeURIComponent(publicId)}`, {
      method: 'DELETE',
    });
    if (res.ok) {
      setFeedback('Device removed and associated Sanctum tokens revoked.');
      await loadLiveSecurityState();
    }
  }

  async function handleRevokeSession(sessionId: string) {
    const res = await bffFetch(`/api/auth/sessions/${encodeURIComponent(sessionId)}`, {
      method: 'DELETE',
    });
    if (res.ok) {
      setFeedback('Session revoked.');
      await loadLiveSecurityState();
      router.refresh();
    }
  }

  async function handleLogoutAll() {
    await bffFetch('/api/auth/logout-all', { method: 'POST' });
    router.push('/login');
    router.refresh();
  }

  if (!user) {
    return (
      <div className="rounded-2xl border border-zinc-200 bg-white p-8">
        <h2 className="text-lg font-semibold text-zinc-950">Sign in to manage live Account &amp; Devices</h2>
        <p className="mt-2 max-w-xl text-sm text-zinc-600">
          This page connects directly to the live Laravel 13 Identity API (`/api/v1/auth/me`,
          `/devices`, `/sessions`, `/logout-all`) through our Next.js BFF using an opaque HttpOnly
          session cookie. Authenticate with OTP first to view or manage your active sessions.
        </p>
        <Link
          href="/login"
          className="mt-5 inline-flex items-center rounded-xl bg-zinc-950 px-5 py-2.5 text-sm font-semibold text-white hover:bg-zinc-800"
        >
          Go to OTP Sign In
        </Link>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {feedback && (
        <div role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
          {feedback}
        </div>
      )}
      {error && (
        <div role="alert" className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
          {error}
        </div>
      )}

      {/* Account profile form (live PATCH /api/v1/auth/me) */}
      <section className="rounded-2xl border border-zinc-200 bg-white p-6">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 className="text-base font-semibold text-zinc-950">Account Identity</h2>
            <p className="text-xs text-zinc-500">
              Public ID: <code className="font-mono text-zinc-800">{user.public_id}</code> · Roles:{' '}
              {user.roles.join(', ')}
            </p>
          </div>
          <span className="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-800">
            <ShieldCheck className="h-3.5 w-3.5" aria-hidden="true" />
            Live BFF Connected
          </span>
        </div>

        <form onSubmit={handleUpdateProfile} className="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label htmlFor="acct-name" className="mb-1 block text-xs font-medium text-zinc-700">
              Full Name
            </label>
            <input
              id="acct-name"
              type="text"
              required
              value={name}
              onChange={(e) => setName(e.target.value)}
              className="w-full rounded-xl border border-zinc-300 px-3.5 py-2 text-sm text-zinc-950"
            />
          </div>

          <div>
            <label htmlFor="acct-email" className="mb-1 block text-xs font-medium text-zinc-700">
              Email Address
            </label>
            <input
              id="acct-email"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="name@example.com"
              className="w-full rounded-xl border border-zinc-300 px-3.5 py-2 text-sm text-zinc-950"
            />
          </div>

          <div className="sm:col-span-2 flex items-center justify-between pt-2">
            <span className="text-xs text-zinc-500">Verified Mobile: {user.phone_e164}</span>
            <button
              type="submit"
              disabled={saving}
              className="rounded-xl bg-zinc-950 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800 disabled:opacity-50"
            >
              {saving ? 'Saving...' : 'Save Account Profile'}
            </button>
          </div>
        </form>
      </section>

      {/* Registered Devices */}
      <section className="rounded-2xl border border-zinc-200 bg-white p-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="text-base font-semibold text-zinc-950">Registered Devices</h2>
            <p className="text-xs text-zinc-500">
              Removing a device revokes every Sanctum Bearer token bound to it.
            </p>
          </div>
          <button
            type="button"
            onClick={handleRegisterBrowserDevice}
            className="rounded-xl border border-zinc-300 px-3.5 py-2 text-xs font-semibold text-zinc-900 hover:bg-zinc-50"
          >
            Register Current Browser
          </button>
        </div>

        <div className="mt-4 divide-y divide-zinc-100">
          {loading ? (
            <p className="py-4 text-sm text-zinc-500">Loading devices...</p>
          ) : devices.length === 0 ? (
            <p className="py-4 text-sm text-zinc-500">No registered devices yet.</p>
          ) : (
            devices.map((device) => (
              <div key={device.public_id} className="flex items-center justify-between gap-4 py-3.5">
                <div className="flex items-center gap-3">
                  {device.platform === 'web' ? (
                    <Laptop className="h-4 w-4 text-zinc-600" aria-hidden="true" />
                  ) : (
                    <Smartphone className="h-4 w-4 text-zinc-600" aria-hidden="true" />
                  )}
                  <div>
                    <p className="text-sm font-medium text-zinc-950">
                      {device.device_identifier}{' '}
                      <span className="text-xs uppercase text-zinc-400">({device.platform})</span>
                    </p>
                    <p className="text-xs text-zinc-500">
                      Push token: {device.has_push_token ? 'Registered' : 'None'}
                    </p>
                  </div>
                </div>

                <button
                  type="button"
                  onClick={() => handleRevokeDevice(device.public_id)}
                  className="inline-flex items-center gap-1 rounded-lg border border-zinc-200 px-2.5 py-1.5 text-xs font-medium text-zinc-700 hover:border-red-300 hover:text-red-700"
                >
                  <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
                  Remove
                </button>
              </div>
            ))
          )}
        </div>
      </section>

      {/* Active Sessions */}
      <section className="rounded-2xl border border-zinc-200 bg-white p-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="text-base font-semibold text-zinc-950">Active Token Sessions</h2>
            <p className="text-xs text-zinc-500">
              Sanctum tokens remain server-side only; session IDs are non-sequential ULIDs.
            </p>
          </div>
          <button
            type="button"
            onClick={handleLogoutAll}
            className="inline-flex items-center gap-1.5 rounded-xl border border-red-200 bg-red-50 px-3.5 py-2 text-xs font-semibold text-red-800 hover:bg-red-100"
          >
            <LogOut className="h-3.5 w-3.5" aria-hidden="true" />
            Revoke All Sessions
          </button>
        </div>

        <div className="mt-4 divide-y divide-zinc-100">
          {loading ? (
            <p className="py-4 text-sm text-zinc-500">Loading active sessions...</p>
          ) : sessions.length === 0 ? (
            <p className="py-4 text-sm text-zinc-500">No active sessions returned.</p>
          ) : (
            sessions.map((session) => (
              <div key={session.session_id} className="flex items-center justify-between gap-4 py-3.5">
                <div>
                  <p className="text-sm font-medium text-zinc-950">
                    {session.name}{' '}
                    {session.is_current && (
                      <span className="ml-1.5 rounded-full bg-zinc-950 px-2 py-0.5 text-[10px] font-semibold text-white">
                        Current
                      </span>
                    )}
                  </p>
                  <p className="text-xs text-zinc-500">
                    Session ID: <code className="font-mono">{session.session_id}</code>
                  </p>
                </div>

                {!session.is_current && (
                  <button
                    type="button"
                    onClick={() => handleRevokeSession(session.session_id)}
                    className="rounded-lg border border-zinc-200 px-2.5 py-1.5 text-xs font-medium text-zinc-700 hover:border-red-300 hover:text-red-700"
                  >
                    Revoke
                  </button>
                )}
              </div>
            ))
          )}
        </div>
      </section>
    </div>
  );
}
