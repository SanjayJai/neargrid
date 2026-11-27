import { notFound } from 'next/navigation';
import { AdminUsersView } from '@/modules/admin/components/AdminUsersView';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';

export const dynamic = 'force-dynamic';

export default async function AdminUsersPage() {
  const access = await authorizeAdminAccess('/admin/users');
  if (access.status !== 'allowed') notFound();
  return <AdminUsersView />;
}
