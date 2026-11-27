'use client';

import { useMemo, useState } from 'react';
import Link from 'next/link';
import {
  ArrowRight,
  Building2,
  FolderTree,
  Globe,
  MessageSquare,
  ShieldCheck,
  Users,
} from 'lucide-react';
import type {
  AdminAuditLogEntryDto,
  AdminBootstrapDto,
} from '@/modules/admin/lib/admin-access';
import { AdminBreadcrumbs } from '@/modules/admin/ui/AdminBreadcrumbs';
import { AdminConfirmDialog } from '@/modules/admin/ui/AdminConfirmDialog';
import {
  AdminDataTable,
  type AdminTableColumn,
} from '@/modules/admin/ui/AdminDataTable';
import { AdminDrawer } from '@/modules/admin/ui/AdminDrawer';
import { AdminStatCard } from '@/modules/admin/ui/AdminStatCard';
import { AdminStatusBadge } from '@/modules/admin/ui/AdminStatusBadge';
import { AdminTimeline, type AdminTimelineItem } from '@/modules/admin/ui/AdminTimeline';

interface AdminOverviewViewProps {
  bootstrap: AdminBootstrapDto;
}

export function AdminOverviewView({ bootstrap }: AdminOverviewViewProps) {
  const [search, setSearch] = useState('');
  const [selectedRow, setSelectedRow] = useState<AdminAuditLogEntryDto | null>(null);
  const [selectedKeys, setSelectedKeys] = useState<string[]>([]);
  const [confirmOpen, setConfirmOpen] = useState(false);

  const filteredLogs = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return bootstrap.recent_activity;
    return bootstrap.recent_activity.filter(
      (log) =>
        log.action.toLowerCase().includes(q) ||
        log.auditable_type.toLowerCase().includes(q) ||
        log.actor_role.toLowerCase().includes(q)
    );
  }, [bootstrap.recent_activity, search]);

  const timelineItems: AdminTimelineItem[] = useMemo(
    () =>
      bootstrap.recent_activity.slice(0, 5).map((log) => ({
        id: String(log.id),
        title: log.action,
        description: `${log.auditable_type} #${log.auditable_id}`,
        actor: log.actor_role,
        timestamp: log.created_at ?? 'Recently',
        badge: log.actor_role,
        tone: 'info',
      })),
    [bootstrap.recent_activity]
  );

  const columns: AdminTableColumn<AdminAuditLogEntryDto>[] = [
    {
      key: 'action',
      label: 'Action',
      render: (row) => (
        <span className="font-semibold text-slate-900 dark:text-white">
          {row.action}
        </span>
      ),
    },
    {
      key: 'actor_role',
      label: 'Actor Role',
      render: (row) => <AdminStatusBadge label={row.actor_role} tone="info" />,
    },
    {
      key: 'auditable_type',
      label: 'Target',
      render: (row) => (
        <span className="text-slate-600 dark:text-slate-300">
          {row.auditable_type} (#{row.auditable_id})
        </span>
      ),
    },
    {
      key: 'created_at',
      label: 'Timestamp',
      align: 'right',
      render: (row) => (
        <span className="text-slate-500 dark:text-slate-400">
          {row.created_at ?? '—'}
        </span>
      ),
    },
  ];

  const { module_counts: counts } = bootstrap;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="space-y-1">
          <AdminBreadcrumbs items={[{ label: 'Control Centre' }]} />
          <h1 className="text-2xl font-extrabold tracking-tight text-slate-950 dark:text-white">
            Platform Control Centre
          </h1>
          <p className="text-sm text-slate-600 dark:text-slate-400">
            Live operational snapshot across NearGrid domains, directory, taxonomy, and governance.
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <Link
            href="/admin/analytics"
            className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-brand-700"
          >
            <span>Platform Analytics</span>
            <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
          </Link>
          <AdminStatusBadge
            label={`Role: ${bootstrap.actor.roles.join(', ') || 'super_admin'}`}
            tone="success"
          />
        </div>
      </div>

      {/* KPI Grid */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <AdminStatCard
          label="Total Businesses"
          value={counts.businesses_total}
          sublabel={`${counts.pending_verifications} pending KYC`}
          icon={<Building2 className="h-5 w-5" aria-hidden="true" />}
        />
        <AdminStatCard
          label="Open Enquiries"
          value={counts.open_enquiries}
          sublabel={`${counts.pending_reviews} reviews pending moderation`}
          icon={<MessageSquare className="h-5 w-5" aria-hidden="true" />}
        />
        <AdminStatCard
          label="Registered Users"
          value={counts.users_total}
          sublabel="Customer & owner accounts"
          icon={<Users className="h-5 w-5" aria-hidden="true" />}
        />
        <AdminStatCard
          label="Active & Draft Domains"
          value={counts.domains_total}
          sublabel="Multi-domain host registry"
          icon={<Globe className="h-5 w-5" aria-hidden="true" />}
        />
        <AdminStatCard
          label="Taxonomy Nodes"
          value={counts.categories_total}
          sublabel="5-level category tree"
          icon={<FolderTree className="h-5 w-5" aria-hidden="true" />}
        />
        <AdminStatCard
          label="Verification Queue"
          value={counts.pending_verifications}
          delta={{
            label: counts.pending_verifications > 0 ? 'Action needed' : 'Clear',
            positive: counts.pending_verifications === 0,
          }}
          icon={<ShieldCheck className="h-5 w-5" aria-hidden="true" />}
        />
      </div>

      {/* Recent Audit Activity + Timeline */}
      <div className="grid grid-cols-1 gap-6 xl:grid-cols-12">
        <div className="space-y-3 xl:col-span-8">
          <h2 className="text-sm font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Recent Platform Activity
          </h2>
          <AdminDataTable
            columns={columns}
            rows={filteredLogs}
            rowKey={(r) => String(r.id)}
            searchValue={search}
            searchPlaceholder="Filter recent audit events..."
            onSearchChange={setSearch}
            selectedKeys={selectedKeys}
            onSelectionChange={setSelectedKeys}
            bulkActions={[
              {
                key: 'inspect',
                label: 'Review Selected',
                allowed: true,
                onExecute: () => setConfirmOpen(true),
              },
            ]}
            onRowClick={(row) => setSelectedRow(row)}
            emptyTitle="No recent audit events"
            emptyDescription="Administrative and security events will appear here."
          />
        </div>

        <div className="space-y-3 xl:col-span-4">
          <h2 className="text-sm font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Activity Timeline
          </h2>
          <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <AdminTimeline items={timelineItems} />
          </div>
        </div>
      </div>

      {/* Record Inspection Drawer */}
      <AdminDrawer
        open={selectedRow !== null}
        title={selectedRow?.action ?? 'Audit Event'}
        subtitle={selectedRow ? `Event #${selectedRow.id}` : undefined}
        onClose={() => setSelectedRow(null)}
      >
        {selectedRow && (
          <dl className="space-y-3 text-xs">
            <div>
              <dt className="font-semibold text-slate-500">Actor Role</dt>
              <dd className="mt-0.5 font-medium text-slate-900 dark:text-white">
                {selectedRow.actor_role}
              </dd>
            </div>
            <div>
              <dt className="font-semibold text-slate-500">Target Entity</dt>
              <dd className="mt-0.5 font-medium text-slate-900 dark:text-white">
                {selectedRow.auditable_type} (#{selectedRow.auditable_id})
              </dd>
            </div>
            <div>
              <dt className="font-semibold text-slate-500">Timestamp</dt>
              <dd className="mt-0.5 font-medium text-slate-900 dark:text-white">
                {selectedRow.created_at ?? '—'}
              </dd>
            </div>
          </dl>
        )}
      </AdminDrawer>

      <AdminConfirmDialog
        open={confirmOpen}
        title="Acknowledge Selected Events"
        description={`Confirm review of ${selectedKeys.length} selected audit log item(s).`}
        confirmLabel="Acknowledge"
        variant="warning"
        onConfirm={() => {
          setConfirmOpen(false);
          setSelectedKeys([]);
        }}
        onCancel={() => setConfirmOpen(false)}
      />
    </div>
  );
}
