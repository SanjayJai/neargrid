import { notFound } from 'next/navigation';
import { AdminUserDetailView } from '@/modules/admin/components/AdminUserDetailView';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';

export const dynamic = 'force-dynamic';

export default async function AdminUserDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const access = await authorizeAdminAccess('/admin/users');
  if (access.status !== 'allowed') notFound();
  const { id } = await params;
  return <AdminUserDetailView publicId={id} />;
}
