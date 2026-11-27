'use client';

import { useEffect, useState } from 'react';
import { adminFetch } from '@/modules/admin/lib/admin-api';
import { AdminDataTable, type AdminTableColumn } from '@/modules/admin/ui/AdminDataTable';

interface PermissionRow {
  id: number;
  name: string;
  label: string;
  group_name: string;
  roles_count: number;
}

export function AdminPermissionsView() {
  const [rows, setRows] = useState<PermissionRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    adminFetch<PermissionRow[]>('permissions')
      .then((res) => setRows(Array.isArray(res.data) ? res.data : []))
      .catch((e) => setError(e instanceof Error ? e.message : 'Failed to load permissions.'))
      .finally(() => setLoading(false));
  }, []);

  const columns: AdminTableColumn<PermissionRow>[] = [
    {
      key: 'name',
      label: 'Permission Key',
      render: (r) => <span className="font-mono text-xs font-semibold">{r.name}</span>,
    },
    {
      key: 'label',
      label: 'Description',
      render: (r) => <span className="text-xs">{r.label}</span>,
    },
    {
      key: 'group_name',
      label: 'Group',
      render: (r) => (
        <span className="rounded bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">
          {r.group_name}
        </span>
      ),
    },
    {
      key: 'roles_count',
      label: 'Roles',
      align: 'right',
      render: (r) => <span className="font-mono text-xs">{r.roles_count}</span>,
    },
  ];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
          Permissions Registry
        </h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          Authoritative backend capability definitions enforced by Laravel Gates and policies.
        </p>
      </div>

      <AdminDataTable
        columns={columns}
        rows={rows}
        rowKey={(r) => String(r.id)}
        loading={loading}
        error={error}
      />
    </div>
  );
}
