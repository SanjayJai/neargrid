import type { Metadata } from 'next';
import type { ReactNode } from 'react';
import { getServerAuthenticatedUser } from '@/lib/bff/session-cookie';
import { BusinessShell } from '@/modules/business/components/BusinessShell';
import { OwnerBusinessProvider } from '@/modules/business/context/OwnerBusinessContext';

export const metadata: Metadata = {
  title: {
    default: 'Business Dashboard',
    template: '%s | NearGrid Business',
  },
  robots: { index: false, follow: false },
};

export default async function BusinessLayout({ children }: { children: ReactNode }) {
  const user = await getServerAuthenticatedUser();

  return (
    <OwnerBusinessProvider>
      <BusinessShell user={user}>{children}</BusinessShell>
    </OwnerBusinessProvider>
  );
}
