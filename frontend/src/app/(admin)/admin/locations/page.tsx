import { notFound } from 'next/navigation';
import { AdminLocationsView } from '@/modules/admin/components/AdminLocationsView';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';

export const dynamic = 'force-dynamic';

export default async function AdminLocationsPage() {
  const access = await authorizeAdminAccess('/admin/locations');
  if (access.status !== 'allowed') notFound();
  return <AdminLocationsView />;
}
