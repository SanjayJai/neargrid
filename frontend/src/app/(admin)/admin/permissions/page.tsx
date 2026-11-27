import { notFound } from 'next/navigation';
import { AdminPermissionsView } from '@/modules/admin/components/AdminPermissionsView';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';

export const dynamic = 'force-dynamic';

export default async function AdminPermissionsPage() {
  const access = await authorizeAdminAccess('/admin/permissions');
  if (access.status !== 'allowed') notFound();
  return <AdminPermissionsView />;
}
