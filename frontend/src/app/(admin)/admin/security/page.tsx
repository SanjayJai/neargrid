import { notFound } from 'next/navigation';
import { AdminSecurityView } from '@/modules/admin/components/AdminSecurityView';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';

export const dynamic = 'force-dynamic';

export default async function AdminSecurityPage() {
  const access = await authorizeAdminAccess('/admin/security');
  if (access.status !== 'allowed') notFound();
  return <AdminSecurityView />;
}
