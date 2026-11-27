import { notFound } from 'next/navigation';
import { AdminAdCampaignDetailView } from '@/modules/admin/components/AdminAdCampaignDetailView';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';

export const dynamic = 'force-dynamic';

export default async function AdminAdCampaignDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const access = await authorizeAdminAccess('/admin/advertising/campaigns');
  if (access.status !== 'allowed') notFound();
  const { id } = await params;
  return <AdminAdCampaignDetailView publicId={id} />;
}
