import { notFound } from 'next/navigation';
import { AdminReviewsView } from '@/modules/admin/components/AdminReviewsView';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';

export const dynamic = 'force-dynamic';

export default async function AdminReviewsPage() {
  const access = await authorizeAdminAccess('/admin/reviews');
  if (access.status !== 'allowed') notFound();
  return <AdminReviewsView />;
}
