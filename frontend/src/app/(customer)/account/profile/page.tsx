import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { getServerAuthenticatedUser } from '@/lib/bff/session-cookie';
import { CustomerProfileView } from '@/modules/customer/components/CustomerProfileView';

export const dynamic = 'force-dynamic';

export const metadata: Metadata = {
  title: 'My Account | NearGrid',
  description: 'Manage your NearGrid customer profile and account details.',
  robots: { index: false, follow: false },
};

export default async function CustomerProfilePage() {
  const user = await getServerAuthenticatedUser();
  if (!user) {
    redirect('/login?next=/account/profile');
  }

  return (
    <div className="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
      <CustomerProfileView initialUser={user} />
    </div>
  );
}
