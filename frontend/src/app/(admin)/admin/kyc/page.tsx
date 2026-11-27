import { notFound } from 'next/navigation';
import { AdminKycClaimsView } from '@/modules/admin/components/AdminKycClaimsView';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';

export const dynamic = 'force-dynamic';

export default async function AdminKycPage() {
  const access = await authorizeAdminAccess('/admin/kyc');
  if (access.status !== 'allowed') notFound();
  return <AdminKycClaimsView />;
}
