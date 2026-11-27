'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { adminFetch, type AdminPaginatedMeta } from '@/modules/admin/lib/admin-api';
import { AdminDataTable, type AdminTableColumn } from '@/modules/admin/ui/AdminDataTable';
import { AdminStatusBadge } from '@/modules/admin/ui/AdminStatusBadge';

export interface AdminUserSummary {
  public_id: string;
  name: string | null;
  phone_e164: string | null;
  email: string | null;
  status: string;
  phone_verified_at: string | null;
  email_verified_at: string | null;
  totp_enabled: boolean;
  last_login_at: string | null;
  roles: string[];
  owned_businesses_count: number;
  active_tokens_count: number;
  devices_count: number;
  created_at: string | null;
}

export function AdminUsersView() {
  const router = useRouter();
  const [rows, setRows] = useState<AdminUserSummary[]>([]);
  const [meta, setMeta] = useState<AdminPaginatedMeta>({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [roleFilter, setRoleFilter] = useState('');
  const [verifiedFilter, setVerifiedFilter] = useState('');
  const [sortBy, setSortBy] = useState('created_at');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');
  const [page, setPage] = useState(1);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const qs = new URLSearchParams({
        page: String(page),
        per_page: '25',
        sort_by: sortBy,
        sort_dir: sortDir,
      });
      if (search.trim()) qs.set('search', search.trim());
      if (statusFilter) qs.set('status', statusFilter);
      if (roleFilter) qs.set('role', roleFilter);
      if (verifiedFilter) qs.set('verified', verifiedFilter);

      const res = await adminFetch<AdminUserSummary[]>(`users?${qs.toString()}`);
      setRows(Array.isArray(res.data) ? res.data : []);
      if (res.meta) setMeta(res.meta);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load users.');
    } finally {
      setLoading(false);
    }
  }, [page, search, statusFilter, roleFilter, verifiedFilter, sortBy, sortDir]);

  useEffect(() => {
    void load();
  }, [load]);

  const columns: AdminTableColumn<AdminUserSummary>[] = [
    {
      key: 'name',
      label: 'User',
      sortable: true,
      render: (r) => (
        <div>
          <div className="font-semibold text-slate-900 dark:text-white">{r.name ?? 'Unnamed User'}</div>
          <div className="text-xs text-slate-500">
            {r.email ?? r.phone_e164 ?? r.public_id}
          </div>
        </div>
      ),
    },
    {
      key: 'status',
      label: 'Status',
      sortable: true,
      render: (r) => <AdminStatusBadge status={r.status} />,
    },
    {
      key: 'roles',
      label: 'Roles',
      render: (r) => (
        <div className="flex flex-wrap gap-1">
          {r.roles.map((role) => (
            <span
              key={role}
              className="rounded-md bg-slate-100 px-2 py-0.5 font-mono text-[11px] text-slate-700 dark:bg-slate-800 dark:text-slate-300"
            >
              {role}
            </span>
          ))}
        </div>
      ),
    },
    {
      key: 'verified',
      label: 'Verification',
      render: (r) => (
        <div className="flex flex-wrap gap-1">
          {r.phone_verified_at && <AdminStatusBadge label="Phone" tone="success" />}
          {r.email_verified_at && <AdminStatusBadge label="Email" tone="info" />}
          {!r.phone_verified_at && !r.email_verified_at && (
            <AdminStatusBadge label="Unverified" tone="warning" />
          )}
        </div>
      ),
    },
    {
      key: 'last_login_at',
      label: 'Sessions / Biz',
      sortable: true,
      align: 'right',
      render: (r) => (
        <span className="font-mono text-xs text-slate-600 dark:text-slate-300">
          {r.active_tokens_count} sess · {r.owned_businesses_count} biz
        </span>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
          Users &amp; Accounts
        </h1>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
          Manage platform accounts, verification status, role assignments, and session security.
        </p>
      </div>

      <AdminDataTable
        columns={columns}
        rows={rows}
        rowKey={(r) => r.public_id}
        loading={loading}
        error={error}
        onRetry={() => void load()}
        searchValue={search}
        searchPlaceholder="Search name, email, or phone..."
        onSearchChange={(val) => {
          setSearch(val);
          setPage(1);
        }}
        sortBy={sortBy}
        sortDir={sortDir}
        onSortChange={(key) => {
          if (sortBy === key) {
            setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
          } else {
            setSortBy(key);
            setSortDir('asc');
          }
          setPage(1);
        }}
        page={meta.current_page}
        lastPage={meta.last_page}
        total={meta.total}
        onPageChange={setPage}
        onRowClick={(r) => router.push(`/admin/users/${r.public_id}`)}
        toolbarExtra={
          <div className="flex flex-wrap gap-2">
            <select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value);
                setPage(1);
              }}
              aria-label="Filter user status"
              className="h-9 rounded-lg border border-slate-200 bg-white px-2.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
            >
              <option value="">All statuses</option>
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
              <option value="erased">Erased</option>
            </select>
            <select
              value={roleFilter}
              onChange={(e) => {
                setRoleFilter(e.target.value);
                setPage(1);
              }}
              aria-label="Filter user role"
              className="h-9 rounded-lg border border-slate-200 bg-white px-2.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
            >
              <option value="">All roles</option>
              <option value="customer">customer</option>
              <option value="business_owner">business_owner</option>
              <option value="kyc_verifier">kyc_verifier</option>
              <option value="moderator">moderator</option>
              <option value="support">support</option>
              <option value="super_admin">super_admin</option>
            </select>
            <select
              value={verifiedFilter}
              onChange={(e) => {
                setVerifiedFilter(e.target.value);
                setPage(1);
              }}
              aria-label="Filter verification state"
              className="h-9 rounded-lg border border-slate-200 bg-white px-2.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
            >
              <option value="">All verification</option>
              <option value="phone">Phone verified</option>
              <option value="email">Email verified</option>
              <option value="unverified">Unverified</option>
            </select>
          </div>
        }
      />
    </div>
  );
}
