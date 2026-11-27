import { notFound } from 'next/navigation';
import { AdminEnquiriesView } from '@/modules/admin/components/AdminEnquiriesView';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';

export const dynamic = 'force-dynamic';

export default async function AdminEnquiriesPage() {
  const access = await authorizeAdminAccess('/admin/enquiries');
  if (access.status !== 'allowed') notFound();
  return <AdminEnquiriesView />;
}
