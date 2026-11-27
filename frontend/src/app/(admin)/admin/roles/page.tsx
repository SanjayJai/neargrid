import { notFound } from 'next/navigation';
import { AdminRolesView } from '@/modules/admin/components/AdminRolesView';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';

export const dynamic = 'force-dynamic';

export default async function AdminRolesPage() {
  const access = await authorizeAdminAccess('/admin/roles');
  if (access.status !== 'allowed') notFound();
  return <AdminRolesView />;
}
