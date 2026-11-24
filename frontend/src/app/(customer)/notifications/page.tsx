import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { getServerAuthenticatedUser } from '@/lib/bff/session-cookie';
import { CustomerNotificationCentre } from '@/modules/customer/components/CustomerNotificationCentre';

export const metadata: Metadata = {
  title: 'Notifications | NearGrid',
  description: 'View and manage your enquiries, review updates, and account activity notifications.',
};

export default async function CustomerNotificationsPage() {
  const user = await getServerAuthenticatedUser();

  if (!user) {
    redirect('/login?returnTo=/notifications');
  }

  return (
    <div className="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
      <CustomerNotificationCentre initialUser={user} />
    </div>
  );
}
