import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { getServerAuthenticatedUser } from '@/lib/bff/session-cookie';
import { SavedBusinessesHub } from '@/modules/customer/components/SavedBusinessesHub';

export const dynamic = 'force-dynamic';

export const metadata: Metadata = {
  title: 'Saved Businesses | NearGrid',
  description: 'View and manage your saved local businesses on NearGrid.',
  robots: { index: false, follow: false },
};

export default async function SavedBusinessesPage() {
  const user = await getServerAuthenticatedUser();

  if (!user) {
    redirect('/login?next=/saved');
  }

  return (
    <div className="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
      <SavedBusinessesHub initialUser={user} />
    </div>
  );
}
