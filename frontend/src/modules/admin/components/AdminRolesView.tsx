'use client';

import { useCallback, useEffect, useState } from 'react';
import { ShieldCheck } from 'lucide-react';
import { adminFetch } from '@/modules/admin/lib/admin-api';
import { AdminConfirmDialog } from '@/modules/admin/ui/AdminConfirmDialog';
import { AdminDrawer } from '@/modules/admin/ui/AdminDrawer';

interface RoleRecord {
  id: number;
  name: string;
  label: string;
  description: string | null;
  users_count: number;
  permissions: string[];
}

interface PermissionRecord {
  id: number;
  name: string;
  label: string;
  group_name: string;
}

export function AdminRolesView() {
  const [roles, setRoles] = useState<RoleRecord[]>([]);
  const [permissions, setPermissions] = useState<PermissionRecord[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [selectedRole, setSelectedRole] = useState<RoleRecord | null>(null);
  const [draftPerms, setDraftPerms] = useState<string[]>([]);
  const [confirmSave, setConfirmSave] = useState(false);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [rRes, pRes] = await Promise.all([
        adminFetch<RoleRecord[]>('roles'),
        adminFetch<PermissionRecord[]>('permissions'),
      ]);
      setRoles(Array.isArray(rRes.data) ? rRes.data : []);
      setPermissions(Array.isArray(pRes.data) ? pRes.data : []);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load roles.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function savePermissions() {
    if (!selectedRole) return;
    setBusy(true);
    try {
      await adminFetch(`roles/${selectedRole.id}/permissions`, {
        method: 'PUT',
        body: { permissions: draftPerms },
      });
      setConfirmSave(false);
      setSelectedRole(null);
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Permission update failed.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
          Roles &amp; Access Matrix
        </h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          Review platform RBAC roles and configure permission grants with escalation safeguards.
        </p>
      </div>

      {error && (
        <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-800">
          {error}
        </div>
      )}

      {loading ? (
        <div className="text-sm text-slate-500">Loading roles...</div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {roles.map((role) => (
            <div
              key={role.id}
              className="flex flex-col justify-between rounded-2xl border border-slate-200 bg-white p-5 shadow-xs dark:border-slate-800 dark:bg-slate-900"
            >
              <div>
                <div className="flex items-center justify-between">
                  <span className="font-mono text-xs font-semibold text-sky-600">{role.name}</span>
                  <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                    {role.users_count} users
                  </span>
                </div>
                <h2 className="mt-1.5 text-base font-bold text-slate-950 dark:text-white">
                  {role.label}
                </h2>
                <p className="mt-1 text-xs text-slate-500">{role.description}</p>
                <div className="mt-3 flex flex-wrap gap-1">
                  {role.permissions.map((p) => (
                    <span
                      key={p}
                      className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] text-slate-700 dark:bg-slate-800 dark:text-slate-300"
                    >
                      {p}
                    </span>
                  ))}
                </div>
              </div>
              <button
                type="button"
                onClick={() => {
                  setSelectedRole(role);
                  setDraftPerms(role.permissions);
                }}
                className="mt-4 inline-flex items-center justify-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
              >
                <ShieldCheck className="h-3.5 w-3.5" />
                Edit Permissions
              </button>
            </div>
          ))}
        </div>
      )}

      <AdminDrawer
        open={selectedRole !== null}
        title={`Edit Permissions: ${selectedRole?.label ?? ''}`}
        subtitle={selectedRole?.name}
        onClose={() => setSelectedRole(null)}
      >
        {selectedRole && (
          <div className="space-y-4">
            <div className="space-y-2">
              {permissions.map((perm) => {
                const checked = draftPerms.includes(perm.name);
                return (
                  <label
                    key={perm.id}
                    className="flex items-start gap-2.5 rounded-lg border border-slate-100 p-2.5 text-xs dark:border-slate-800"
                  >
                    <input
                      type="checkbox"
                      checked={checked}
                      onChange={(e) => {
                        if (e.target.checked) {
                          setDraftPerms((prev) => [...prev, perm.name]);
                        } else {
                          setDraftPerms((prev) => prev.filter((x) => x !== perm.name));
                        }
                      }}
                    />
                    <div>
                      <div className="font-mono font-semibold">{perm.name}</div>
                      <div className="text-slate-500">
                        {perm.label} ({perm.group_name})
                      </div>
                    </div>
                  </label>
                );
              })}
            </div>
            <button
              type="button"
              disabled={busy}
              onClick={() => setConfirmSave(true)}
              className="rounded-lg bg-slate-950 px-4 py-2 text-xs font-semibold text-white dark:bg-white dark:text-slate-950"
            >
              Save Role Permissions
            </button>
          </div>
        )}
      </AdminDrawer>

      <AdminConfirmDialog
        open={confirmSave}
        title="Confirm Permission Matrix Update"
        description="Changing role permissions immediately affects all users holding this role."
        confirmLabel="Save Permissions"
        variant="warning"
        loading={busy}
        onConfirm={() => void savePermissions()}
        onCancel={() => setConfirmSave(false)}
      />
    </div>
  );
}
