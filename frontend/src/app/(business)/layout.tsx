import type { Metadata } from 'next';
import { headers } from 'next/headers';
import { notFound, redirect } from 'next/navigation';
import type { ReactNode } from 'react';
import { getServerDomainContext } from '@/lib/domains/host-resolver';
import { BusinessShell } from '@/modules/business/components/BusinessShell';
import { MerchantAccessDenied } from '@/modules/business/components/MerchantAccessDenied';
import { OwnerBusinessProvider } from '@/modules/business/context/OwnerBusinessContext';
import { authorizeBusinessDashboardAccess } from '@/modules/business/lib/dashboard-access';

export const dynamic = 'force-dynamic';

export const metadata: Metadata = {
  title: {
    default: 'Business Dashboard',
    template: '%s | NearGrid Business',
  },
  robots: { index: false, follow: false },
};

export default async function BusinessLayout({ children }: { children: ReactNode }) {
  const domainContext = await getServerDomainContext();
  if (domainContext.kind === 'unregistered_or_blocked' || domainContext.kind === 'invalid') {
    notFound();
  }

  const requestedPath = headers().get('x-pathname') ?? '/dashboard';
  const access = await authorizeBusinessDashboardAccess(requestedPath);

  if (access.status === 'unauthenticated') {
    redirect(access.redirectTo);
  }

  if (access.status === 'forbidden') {
    return <MerchantAccessDenied user={access.user} />;
  }

  return (
    <OwnerBusinessProvider initialBusinesses={access.businesses}>
      <BusinessShell user={access.user}>{children}</BusinessShell>
    </OwnerBusinessProvider>
  );
}
