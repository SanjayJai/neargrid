import type { ReactNode } from 'react';
import { getServerAuthenticatedUser } from '@/lib/bff/session-cookie';
import { CustomerFooter } from '@/modules/customer/components/CustomerFooter';
import { CustomerHeader } from '@/modules/customer/components/CustomerHeader';

export default async function CustomerLayout({ children }: { children: ReactNode }) {
  const user = await getServerAuthenticatedUser();

  return (
    <div className="flex min-h-screen flex-col bg-white">
      <CustomerHeader user={user} />
      <main className="flex-1">{children}</main>
      <CustomerFooter />
    </div>
  );
}
