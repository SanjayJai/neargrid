import { notFound } from 'next/navigation';
import { AdminOrganizationsView } from '@/modules/admin/components/AdminOrganizationsView';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';

export const dynamic = 'force-dynamic';

export default async function AdminOrganizationsPage() {
  const access = await authorizeAdminAccess('/admin/organizations');
  if (access.status !== 'allowed') notFound();
  return <AdminOrganizationsView />;
}
