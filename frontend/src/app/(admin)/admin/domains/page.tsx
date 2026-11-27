import { notFound } from 'next/navigation';
import { AdminDomainsView } from '@/modules/admin/components/AdminDomainsView';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';

export const dynamic = 'force-dynamic';

export default async function AdminDomainsPage() {
  const access = await authorizeAdminAccess('/admin/domains');
  if (access.status !== 'allowed') notFound();
  return <AdminDomainsView />;
}
