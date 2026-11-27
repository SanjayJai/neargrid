import { notFound } from 'next/navigation';
import { callLaravelApi } from '@/lib/bff/laravel-client';
import { readCurrentServerSession } from '@/lib/bff/session-cookie';
import { AdminAnalyticsView } from '@/modules/admin/components/AdminAnalyticsView';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';
import type { AdminAnalyticsDto } from '@/modules/admin/lib/admin-analytics-types';

export const dynamic = 'force-dynamic';

export default async function AdminAnalyticsPage() {
  const access = await authorizeAdminAccess('/admin/analytics');
  if (access.status !== 'allowed') {
    notFound();
  }

  const session = await readCurrentServerSession();
  if (!session) {
    notFound();
  }

  const res = await callLaravelApi<AdminAnalyticsDto>('/api/v1/admin/analytics?range=30d', {
    method: 'GET',
    bearerToken: session.record.accessToken,
  });

  if (!res.ok || res.payload?.status !== 'success') {
    return (
      <div className="rounded-2xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-800">
        Platform analytics are temporarily unavailable.
      </div>
    );
  }

  return <AdminAnalyticsView initialData={res.payload.data} />;
}
