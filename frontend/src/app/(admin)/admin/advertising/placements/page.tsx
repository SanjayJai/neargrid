import { notFound } from 'next/navigation';
import { AdminAdPlacementsView } from '@/modules/admin/components/AdminAdPlacementsView';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';

export const dynamic = 'force-dynamic';

export default async function AdminAdPlacementsPage() {
  const access = await authorizeAdminAccess('/admin/advertising/placements');
  if (access.status !== 'allowed') notFound();
  return <AdminAdPlacementsView />;
}
