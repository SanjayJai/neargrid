import { notFound } from 'next/navigation';
import { AdminSeoView } from '@/modules/admin/components/AdminSeoView';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';

export const dynamic = 'force-dynamic';

export default async function AdminSeoPage() {
  const access = await authorizeAdminAccess('/admin/seo');
  if (access.status !== 'allowed') notFound();
  return <AdminSeoView />;
}
