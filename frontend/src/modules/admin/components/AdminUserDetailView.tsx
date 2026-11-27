'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { ArrowLeft, KeyRound, ShieldAlert, Trash2, UserCheck } from 'lucide-react';
import { adminFetch } from '@/modules/admin/lib/admin-api';
import { AdminConfirmDialog } from '@/modules/admin/ui/AdminConfirmDialog';
import { AdminStatusBadge } from '@/modules/admin/ui/AdminStatusBadge';
import type { AdminUserSummary } from './AdminUsersView';

interface UserDetailPayload {
  user: AdminUserSummary & { permissions: string[] };
  sessions: Array<{
    public_id: string;
    name: string;
    device_public_id: string | null;
    last_used_at: string | null;
    expires_at: string | null;
  }>;
  devices: Array<{
    public_id: string;
    platform: string;
    app_version: string | null;
    last_active_at: string | null;
  }>;
  security_logs: Array<{
    id: number;
    actor_role: string | null;
    action: string;
    created_at: string | null;
  }>;
  erasure_requests: Array<{
    public_id: string;
    status: string;
    reason: string | null;
    retention_snapshot: Record<string, number> | null;
  }>;
}

interface RoleItem {
  id: number;
  name: string;
  label: string;
}

export function AdminUserDetailView({ publicId }: { publicId: string }) {
  const [data, setData] = useState<UserDetailPayload | null>(null);
  const [roles, setRoles] = useState<RoleItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [feedback, setFeedback] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [statusReason, setStatusReason] = useState('');
  const [selectedRole, setSelectedRole] = useState('moderator');
  const [erasureReason, setErasureReason] = useState('');
  const [confirmAction, setConfirmAction] = useState<
    | { type: 'status'; target: 'active' | 'suspended' }
    | { type: 'revoke_all' }
    | { type: 'remove_role'; role: RoleItem }
    | { type: 'erasure' }
    | null
  >(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [userRes, rolesRes] = await Promise.all([
        adminFetch<UserDetailPayload>(`users/${publicId}`),
        adminFetch<RoleItem[]>('roles'),
      ]);
      setData(userRes.data);
      setRoles(Array.isArray(rolesRes.data) ? rolesRes.data : []);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load user details.');
    } finally {
      setLoading(false);
    }
  }, [publicId]);

  useEffect(() => {
    void load();
  }, [load]);

  async function executeStatus(target: 'active' | 'suspended') {
    setBusy(true);
    setFeedback(null);
    try {
      await adminFetch(`users/${publicId}/status`, {
        method: 'PATCH',
        body: { status: target, reason: statusReason.trim() || undefined },
      });
      setConfirmAction(null);
      setStatusReason('');
      setFeedback(`Account status updated to ${target}.`);
      await load();
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Failed to update status.');
    } finally {
      setBusy(false);
    }
  }

  async function verifyChannel(channel: 'phone' | 'email' | 'both') {
    setBusy(true);
    setFeedback(null);
    try {
      await adminFetch(`users/${publicId}/verify`, {
        method: 'POST',
        body: { channel },
      });
      setFeedback('Verification status updated.');
      await load();
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Verification failed.');
    } finally {
      setBusy(false);
    }
  }

  async function assignRole() {
    setBusy(true);
    setFeedback(null);
    try {
      await adminFetch(`users/${publicId}/roles`, {
        method: 'POST',
        body: { role: selectedRole },
      });
      setFeedback(`Role ${selectedRole} assigned.`);
      await load();
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Role assignment failed.');
    } finally {
      setBusy(false);
    }
  }

  async function removeRole(role: RoleItem) {
    setBusy(true);
    setFeedback(null);
    try {
      await adminFetch(`users/${publicId}/roles/${role.id}`, { method: 'DELETE' });
      setConfirmAction(null);
      setFeedback(`Role ${role.name} removed.`);
      await load();
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Role removal failed.');
    } finally {
      setBusy(false);
    }
  }

  async function revokeAllSessions() {
    setBusy(true);
    setFeedback(null);
    try {
      await adminFetch(`users/${publicId}/revoke-sessions`, { method: 'POST' });
      setConfirmAction(null);
      setFeedback('All active sessions and tokens revoked.');
      await load();
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Session revocation failed.');
    } finally {
      setBusy(false);
    }
  }

  async function submitErasureRequest() {
    setBusy(true);
    setFeedback(null);
    try {
      await adminFetch(`users/${publicId}/erasure-requests`, {
        method: 'POST',
        body: { reason: erasureReason.trim() },
      });
      setConfirmAction(null);
      setErasureReason('');
      setFeedback('Account erasure request queued for retention review.');
      await load();
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Erasure request failed.');
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return <div className="p-6 text-sm text-slate-500">Loading user profile...</div>;
  }
  if (error || !data) {
    return (
      <div className="rounded-xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-800">
        {error ?? 'User not found.'}
      </div>
    );
  }

  const { user, sessions, devices, security_logs, erasure_requests } = data;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <Link
            href="/admin/users"
            className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-slate-900"
          >
            <ArrowLeft className="h-3.5 w-3.5" />
            Back to Users
          </Link>
          <h1 className="mt-1 text-2xl font-bold text-slate-950 dark:text-white">
            {user.name ?? 'Unnamed User'}
          </h1>
          <p className="text-xs text-slate-500">
            {user.public_id} · {user.email ?? 'No email'} · {user.phone_e164 ?? 'No phone'}
          </p>
        </div>
        <AdminStatusBadge status={user.status} />
      </div>

      {feedback && (
        <div className="rounded-xl border border-sky-200 bg-sky-50 px-4 py-2.5 text-xs font-medium text-sky-900 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-200">
          {feedback}
        </div>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        <div className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
          <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500">
            <UserCheck className="h-4 w-4" />
            Account Controls &amp; Verification
          </div>
          <div className="grid grid-cols-3 gap-3 text-xs">
            <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800">
              <div className="text-slate-500">Businesses</div>
              <div className="mt-1 text-base font-bold">{user.owned_businesses_count}</div>
            </div>
            <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800">
              <div className="text-slate-500">Sessions</div>
              <div className="mt-1 text-base font-bold">{sessions.length}</div>
            </div>
            <div className="rounded-lg bg-slate-50 p-3 dark:bg-slate-800">
              <div className="text-slate-500">Devices</div>
              <div className="mt-1 text-base font-bold">{devices.length}</div>
            </div>
          </div>

          <input
            type="text"
            value={statusReason}
            onChange={(e) => setStatusReason(e.target.value)}
            placeholder="Audit reason for status change"
            className="h-9 w-full rounded-lg border border-slate-200 px-3 text-xs dark:border-slate-700 dark:bg-slate-800"
          />

          <div className="flex flex-wrap gap-2">
            {user.status !== 'suspended' && user.status !== 'erased' && (
              <button
                type="button"
                disabled={busy}
                onClick={() => setConfirmAction({ type: 'status', target: 'suspended' })}
                className="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white"
              >
                Suspend Account
              </button>
            )}
            {user.status === 'suspended' && (
              <button
                type="button"
                disabled={busy}
                onClick={() => setConfirmAction({ type: 'status', target: 'active' })}
                className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white"
              >
                Restore Account
              </button>
            )}
            <button
              type="button"
              disabled={busy}
              onClick={() => void verifyChannel('phone')}
              className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold dark:border-slate-700"
            >
              Mark Phone Verified
            </button>
            <button
              type="button"
              disabled={busy}
              onClick={() => setConfirmAction({ type: 'revoke_all' })}
              className="inline-flex items-center gap-1 rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white"
            >
              <KeyRound className="h-3.5 w-3.5" />
              Revoke All Sessions
            </button>
          </div>
        </div>

        <div className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
          <div className="text-xs font-semibold uppercase tracking-wider text-slate-500">
            Assigned Roles &amp; Permissions
          </div>
          <div className="flex flex-wrap gap-2">
            {user.roles.map((rName) => {
              const roleObj = roles.find((r) => r.name === rName);
              return (
                <span
                  key={rName}
                  className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 px-3 py-1 font-mono text-xs dark:border-slate-700"
                >
                  {rName}
                  {roleObj && (
                    <button
                      type="button"
                      onClick={() => setConfirmAction({ type: 'remove_role', role: roleObj })}
                      aria-label={`Remove ${rName}`}
                      className="text-rose-600 hover:text-rose-800"
                    >
                      ×
                    </button>
                  )}
                </span>
              );
            })}
          </div>
          <div className="flex gap-2">
            <select
              value={selectedRole}
              onChange={(e) => setSelectedRole(e.target.value)}
              aria-label="Select role to assign"
              className="h-9 flex-1 rounded-lg border border-slate-200 px-3 text-xs dark:border-slate-700 dark:bg-slate-800"
            >
              {roles.map((r) => (
                <option key={r.id} value={r.name}>
                  {r.label} ({r.name})
                </option>
              ))}
            </select>
            <button
              type="button"
              disabled={busy}
              onClick={() => void assignRole()}
              className="rounded-lg bg-slate-950 px-3.5 py-1.5 text-xs font-semibold text-white dark:bg-white dark:text-slate-950"
            >
              Assign Role
            </button>
          </div>
          <div className="text-[11px] text-slate-500">
            Effective permissions: {user.permissions.join(', ') || 'None'}
          </div>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <div className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
          <div className="text-xs font-semibold uppercase tracking-wider text-slate-500">
            Recent Security Audit Trail
          </div>
          {security_logs.length === 0 ? (
            <p className="text-xs text-slate-500">No security events recorded.</p>
          ) : (
            <div className="space-y-2 text-xs">
              {security_logs.map((log) => (
                <div key={log.id} className="flex items-center justify-between border-b border-slate-100 pb-1.5 dark:border-slate-800">
                  <span className="font-mono font-semibold">{log.action}</span>
                  <span className="text-slate-500">{log.created_at ?? '—'}</span>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
          <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500">
            <ShieldAlert className="h-4 w-4 text-rose-500" />
            Controlled Account Erasure
          </div>
          <input
            type="text"
            value={erasureReason}
            onChange={(e) => setErasureReason(e.target.value)}
            placeholder="Erasure request justification (min 5 chars)"
            className="h-9 w-full rounded-lg border border-slate-200 px-3 text-xs dark:border-slate-700 dark:bg-slate-800"
          />
          <button
            type="button"
            disabled={busy || erasureReason.trim().length < 5 || user.status === 'erased'}
            onClick={() => setConfirmAction({ type: 'erasure' })}
            className="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 disabled:opacity-50"
          >
            <Trash2 className="h-3.5 w-3.5" />
            Queue Erasure Request
          </button>
          {erasure_requests.length > 0 && (
            <div className="space-y-1 text-xs text-slate-600">
              {erasure_requests.map((er) => (
                <div key={er.public_id} className="flex items-center justify-between">
                  <span className="font-mono">{er.public_id}</span>
                  <AdminStatusBadge status={er.status} />
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      <AdminConfirmDialog
        open={confirmAction !== null}
        title="Confirm Security Action"
        description="This privileged identity action is recorded in the immutable audit trail."
        confirmLabel="Confirm"
        loading={busy}
        onConfirm={() => {
          if (!confirmAction) return;
          if (confirmAction.type === 'status') void executeStatus(confirmAction.target);
          if (confirmAction.type === 'revoke_all') void revokeAllSessions();
          if (confirmAction.type === 'remove_role') void removeRole(confirmAction.role);
          if (confirmAction.type === 'erasure') void submitErasureRequest();
        }}
        onCancel={() => setConfirmAction(null)}
      />
    </div>
  );
}
