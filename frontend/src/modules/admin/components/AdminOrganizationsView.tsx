'use client';

import { useCallback, useEffect, useState } from 'react';
import { Building2, Plus } from 'lucide-react';
import { adminFetch, type AdminPaginatedMeta } from '@/modules/admin/lib/admin-api';
import { AdminConfirmDialog } from '@/modules/admin/ui/AdminConfirmDialog';
import { AdminDataTable, type AdminTableColumn } from '@/modules/admin/ui/AdminDataTable';
import { AdminDrawer } from '@/modules/admin/ui/AdminDrawer';
import { AdminStatusBadge } from '@/modules/admin/ui/AdminStatusBadge';

interface OrgSummary {
  public_id: string;
  name: string;
  slug: string;
  status: string;
  owner: { public_id: string; name: string; email: string } | null;
  branches_count: number;
  members_count: number;
  domains_count: number;
}

interface OrgBranch {
  public_id: string;
  name: string;
  slug: string;
  status: string;
  branch_type: string | null;
  franchise_code: string | null;
  allow_hq_lead_access: boolean;
  city_name: string | null;
  category_name: string | null;
}

interface OrgMember {
  public_id: string;
  role: string;
  scope_type: string;
  status: string;
  user: { public_id: string; name: string; email: string } | null;
}

interface OrgDetail {
  public_id: string;
  name: string;
  slug: string;
  status: string;
  owner: { public_id: string; name: string; email: string } | null;
  branches: OrgBranch[];
  members: OrgMember[];
  domains: Array<{ public_id: string; hostname: string; status: string; is_primary: boolean }>;
}

export function AdminOrganizationsView() {
  const [rows, setRows] = useState<OrgSummary[]>([]);
  const [meta, setMeta] = useState<AdminPaginatedMeta>({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [page, setPage] = useState(1);

  const [detail, setDetail] = useState<OrgDetail | null>(null);
  const [feedback, setFeedback] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [createOpen, setCreateOpen] = useState(false);
  const [newName, setNewName] = useState('');
  const [newOwnerId, setNewOwnerId] = useState('');

  const [attachBizId, setAttachBizId] = useState('');
  const [attachType, setAttachType] = useState<'headquarters' | 'company_owned' | 'franchise'>('company_owned');
  const [attachCode, setAttachCode] = useState('');

  const [pendingHqToggle, setPendingHqToggle] = useState<{ branch: OrgBranch; allow: boolean } | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const qs = new URLSearchParams({ page: String(page), per_page: '20' });
      if (search.trim()) qs.set('q', search.trim());
      if (statusFilter) qs.set('status', statusFilter);
      const res = await adminFetch<OrgSummary[]>(`organizations?${qs.toString()}`);
      setRows(Array.isArray(res.data) ? res.data : []);
      if (res.meta) setMeta(res.meta);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load organizations.');
    } finally {
      setLoading(false);
    }
  }, [page, search, statusFilter]);

  useEffect(() => {
    void load();
  }, [load]);

  async function openDetail(publicId: string) {
    setFeedback(null);
    try {
      const res = await adminFetch<OrgDetail>(`organizations/${publicId}`);
      setDetail(res.data);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load organization details.');
    }
  }

  async function handleCreateOrg() {
    setBusy(true);
    setFeedback(null);
    try {
      await adminFetch('organizations', {
        method: 'POST',
        body: { name: newName.trim(), owner_public_id: newOwnerId.trim() },
      });
      setCreateOpen(false);
      setNewName('');
      setNewOwnerId('');
      await load();
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Create failed.');
    } finally {
      setBusy(false);
    }
  }

  async function handleAttachBranch() {
    if (!detail) return;
    setBusy(true);
    setFeedback(null);
    try {
      await adminFetch(`organizations/${detail.public_id}/branches`, {
        method: 'POST',
        body: {
          business_public_id: attachBizId.trim(),
          branch_type: attachType,
          franchise_code: attachType === 'franchise' ? attachCode.trim() || null : null,
        },
      });
      setAttachBizId('');
      setAttachCode('');
      setFeedback('Branch attached.');
      await openDetail(detail.public_id);
      await load();
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Branch attach failed.');
    } finally {
      setBusy(false);
    }
  }

  async function handleToggleHqAccess(branch: OrgBranch, allow: boolean) {
    if (!detail) return;
    setBusy(true);
    setFeedback(null);
    try {
      await adminFetch(`organizations/${detail.public_id}/branches/${branch.public_id}/hq-lead-access`, {
        method: 'PATCH',
        body: { allow_hq_lead_access: allow },
      });
      setPendingHqToggle(null);
      setFeedback('Franchise HQ lead access updated.');
      await openDetail(detail.public_id);
    } catch (e) {
      setFeedback(e instanceof Error ? e.message : 'Failed to update franchise lead access.');
    } finally {
      setBusy(false);
    }
  }

  const columns: AdminTableColumn<OrgSummary>[] = [
    {
      key: 'name',
      label: 'Organization',
      render: (r) => (
        <div>
          <div className="font-semibold text-slate-900 dark:text-white">{r.name}</div>
          <div className="text-xs text-slate-500">{r.slug}</div>
        </div>
      ),
    },
    {
      key: 'status',
      label: 'Status',
      render: (r) => <AdminStatusBadge status={r.status} />,
    },
    {
      key: 'owner',
      label: 'Owner',
      render: (r) => <span className="text-xs">{r.owner?.name ?? '—'}</span>,
    },
    {
      key: 'branches',
      label: 'Branches',
      align: 'right',
      render: (r) => <span className="font-mono text-xs">{r.branches_count}</span>,
    },
    {
      key: 'members',
      label: 'Members',
      align: 'right',
      render: (r) => <span className="font-mono text-xs">{r.members_count}</span>,
    },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
            Organizations, Branches &amp; Franchises
          </h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Manage multi-branch HQ entities, company-owned outlets, franchise isolation, and regional roles.
          </p>
        </div>
        <button
          type="button"
          onClick={() => {
            setFeedback(null);
            setCreateOpen(true);
          }}
          className="inline-flex items-center gap-1.5 rounded-xl bg-slate-950 px-3.5 py-2 text-xs font-semibold text-white hover:bg-slate-800 dark:bg-white dark:text-slate-950"
        >
          <Plus className="h-4 w-4" />
          New Organization
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
        searchPlaceholder="Search organizations..."
        onSearchChange={(val) => {
          setSearch(val);
          setPage(1);
        }}
        page={meta.current_page}
        lastPage={meta.last_page}
        total={meta.total}
        onPageChange={setPage}
        onRowClick={(r) => void openDetail(r.public_id)}
        toolbarExtra={
          <select
            value={statusFilter}
            onChange={(e) => {
              setStatusFilter(e.target.value);
              setPage(1);
            }}
            aria-label="Filter organization status"
            className="h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
          >
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
          </select>
        }
      />

      <AdminDrawer
        open={createOpen}
        title="Create Organization"
        onClose={() => setCreateOpen(false)}
      >
        <div className="space-y-4 text-sm">
          {feedback && (
            <div className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">
              {feedback}
            </div>
          )}
          <div>
            <label className="block text-xs font-medium text-slate-600 dark:text-slate-300">
              Organization Name
            </label>
            <input
              type="text"
              value={newName}
              onChange={(e) => setNewName(e.target.value)}
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
          </div>
          <div>
            <label className="block text-xs font-medium text-slate-600 dark:text-slate-300">
              Owner User Public ID
            </label>
            <input
              type="text"
              value={newOwnerId}
              onChange={(e) => setNewOwnerId(e.target.value)}
              placeholder="01J..."
              className="mt-1 h-9 w-full rounded-lg border border-slate-200 px-3 font-mono text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
          </div>
          <button
            type="button"
            disabled={busy || !newName.trim() || !newOwnerId.trim()}
            onClick={() => void handleCreateOrg()}
            className="rounded-lg bg-slate-950 px-4 py-2 text-xs font-semibold text-white disabled:opacity-50 dark:bg-white dark:text-slate-950"
          >
            Create Organization
          </button>
        </div>
      </AdminDrawer>

      <AdminDrawer
        open={detail !== null}
        title={detail?.name ?? 'Organization'}
        subtitle={detail ? `${detail.public_id} · ${detail.status}` : undefined}
        onClose={() => setDetail(null)}
      >
        {detail && (
          <div className="space-y-5 text-sm">
            {feedback && (
              <div className="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-200">
                {feedback}
              </div>
            )}

            <div className="space-y-2 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
              <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500">
                <Building2 className="h-3.5 w-3.5" />
                Branches &amp; Franchises ({detail.branches.length})
              </div>
              {detail.branches.length === 0 ? (
                <p className="text-xs text-slate-500">No branches attached yet.</p>
              ) : (
                <div className="space-y-2">
                  {detail.branches.map((b) => (
                    <div
                      key={b.public_id}
                      className="flex items-center justify-between gap-2 rounded-lg border border-slate-100 p-2.5 dark:border-slate-800"
                    >
                      <div>
                        <div className="text-xs font-semibold text-slate-900 dark:text-white">
                          {b.name}
                        </div>
                        <div className="text-[11px] text-slate-500">
                          {b.branch_type ?? 'branch'}
                          {b.franchise_code ? ` · ${b.franchise_code}` : ''}
                          {b.city_name ? ` · ${b.city_name}` : ''}
                        </div>
                      </div>
                      {b.branch_type === 'franchise' && (
                        <button
                          type="button"
                          onClick={() =>
                            setPendingHqToggle({ branch: b, allow: !b.allow_hq_lead_access })
                          }
                          className="rounded-md border border-slate-200 px-2.5 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200"
                        >
                          {b.allow_hq_lead_access ? 'Revoke HQ Leads' : 'Grant HQ Leads'}
                        </button>
                      )}
                    </div>
                  ))}
                </div>
              )}

              <div className="mt-3 space-y-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                <div className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                  Attach Business Branch
                </div>
                <div className="grid grid-cols-2 gap-2">
                  <input
                    type="text"
                    value={attachBizId}
                    onChange={(e) => setAttachBizId(e.target.value)}
                    placeholder="Business public_id"
                    className="h-8 rounded-lg border border-slate-200 px-2.5 font-mono text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  />
                  <select
                    value={attachType}
                    onChange={(e) =>
                      setAttachType(e.target.value as 'headquarters' | 'company_owned' | 'franchise')
                    }
                    aria-label="Branch type"
                    className="h-8 rounded-lg border border-slate-200 px-2 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  >
                    <option value="headquarters">Headquarters</option>
                    <option value="company_owned">Company Owned</option>
                    <option value="franchise">Franchise</option>
                  </select>
                </div>
                {attachType === 'franchise' && (
                  <input
                    type="text"
                    value={attachCode}
                    onChange={(e) => setAttachCode(e.target.value)}
                    placeholder="Franchise code (optional)"
                    className="h-8 w-full rounded-lg border border-slate-200 px-2.5 text-xs dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                  />
                )}
                <button
                  type="button"
                  disabled={busy || !attachBizId.trim()}
                  onClick={() => void handleAttachBranch()}
                  className="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50 dark:bg-white dark:text-slate-950"
                >
                  Attach Branch
                </button>
              </div>
            </div>

            <div className="space-y-2 rounded-xl border border-slate-200 p-4 dark:border-slate-800">
              <div className="text-xs font-semibold uppercase tracking-wider text-slate-500">
                Corporate &amp; Regional Members ({detail.members.length})
              </div>
              {detail.members.map((m) => (
                <div key={m.public_id} className="flex items-center justify-between text-xs">
                  <span>{m.user?.name ?? m.public_id}</span>
                  <span className="font-mono text-[11px] text-slate-500">
                    {m.role} · {m.scope_type}
                  </span>
                </div>
              ))}
            </div>
          </div>
        )}
      </AdminDrawer>

      <AdminConfirmDialog
        open={pendingHqToggle !== null}
        title="Confirm Franchise HQ Lead Access"
        description={
          pendingHqToggle?.allow
            ? 'Granting HQ lead access allows corporate admins to view leads for this franchise branch.'
            : 'Revoking HQ lead access restores strict franchise-only lead isolation.'
        }
        confirmLabel={pendingHqToggle?.allow ? 'Grant HQ Access' : 'Revoke HQ Access'}
        variant="warning"
        loading={busy}
        onConfirm={() => {
          if (pendingHqToggle) void handleToggleHqAccess(pendingHqToggle.branch, pendingHqToggle.allow);
        }}
        onCancel={() => setPendingHqToggle(null)}
      />
    </div>
  );
}
