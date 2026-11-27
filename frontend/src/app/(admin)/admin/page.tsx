import { notFound } from 'next/navigation';
import { AdminOverviewView } from '@/modules/admin/components/AdminOverviewView';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';

export const dynamic = 'force-dynamic';

export default async function AdminControlCentrePage() {
  const access = await authorizeAdminAccess('/admin');
  if (access.status !== 'allowed') {
    notFound();
  }

  return <AdminOverviewView bootstrap={access.bootstrap} />;
}
