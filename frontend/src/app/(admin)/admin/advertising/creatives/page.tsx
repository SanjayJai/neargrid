import { notFound } from 'next/navigation';
import { AdminAdCreativesView } from '@/modules/admin/components/AdminAdCreativesView';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';

export const dynamic = 'force-dynamic';

export default async function AdminAdCreativesPage() {
  const access = await authorizeAdminAccess('/admin/advertising/creatives');
  if (access.status !== 'allowed') notFound();
  return <AdminAdCreativesView />;
}
