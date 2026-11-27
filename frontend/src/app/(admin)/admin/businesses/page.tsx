import { notFound } from 'next/navigation';
import { AdminBusinessesView } from '@/modules/admin/components/AdminBusinessesView';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';

export const dynamic = 'force-dynamic';

export default async function AdminBusinessesPage() {
  const access = await authorizeAdminAccess('/admin/businesses');
  if (access.status !== 'allowed') notFound();
  return <AdminBusinessesView />;
}
