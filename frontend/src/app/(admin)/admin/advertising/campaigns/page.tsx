import { notFound } from 'next/navigation';
import { AdminAdCampaignsView } from '@/modules/admin/components/AdminAdCampaignsView';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';

export const dynamic = 'force-dynamic';

export default async function AdminAdCampaignsPage() {
  const access = await authorizeAdminAccess('/admin/advertising/campaigns');
  if (access.status !== 'allowed') notFound();
  return <AdminAdCampaignsView />;
}
