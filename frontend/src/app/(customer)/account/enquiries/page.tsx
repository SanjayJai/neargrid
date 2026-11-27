import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { getServerAuthenticatedUser } from '@/lib/bff/session-cookie';
import { CustomerEnquiriesView } from '@/modules/customer/components/CustomerEnquiriesView';

export const dynamic = 'force-dynamic';

export const metadata: Metadata = {
  title: 'My Enquiries | NearGrid',
  description: 'View your submitted business enquiries on NearGrid.',
  robots: { index: false, follow: false },
};

export default async function CustomerEnquiriesPage() {
  const user = await getServerAuthenticatedUser();
  if (!user) {
    redirect('/login?next=/account/enquiries');
  }

  return (
    <div className="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
      <CustomerEnquiriesView />
    </div>
  );
}
