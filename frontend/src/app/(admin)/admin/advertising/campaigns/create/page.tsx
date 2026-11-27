import { notFound } from 'next/navigation';
import { AdminAdCampaignCreateView } from '@/modules/admin/components/AdminAdCampaignCreateView';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';

export const dynamic = 'force-dynamic';

export default async function AdminAdCampaignCreatePage() {
  const access = await authorizeAdminAccess('/admin/advertising/campaigns/create');
  if (access.status !== 'allowed') notFound();
  return <AdminAdCampaignCreateView />;
}
