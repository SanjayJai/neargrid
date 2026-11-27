import type { Metadata } from 'next';
import { headers } from 'next/headers';
import { notFound, redirect } from 'next/navigation';
import type { ReactNode } from 'react';
import { getServerDomainContext } from '@/lib/domains/host-resolver';
import { authorizeAdminAccess } from '@/modules/admin/lib/admin-access';
import { AdminAccessDenied } from '@/modules/admin/ui/AdminAccessDenied';
import { AdminShell } from '@/modules/admin/ui/AdminShell';

export const dynamic = 'force-dynamic';

export const metadata: Metadata = {
  title: {
    default: 'Super Admin Control Centre',
    template: '%s | NearGrid Admin',
  },
  robots: { index: false, follow: false },
};

export default async function AdminLayout({ children }: { children: ReactNode }) {
  const domainContext = await getServerDomainContext();
  if (domainContext.kind === 'unregistered_or_blocked' || domainContext.kind === 'invalid') {
    notFound();
  }

  const requestedPath = headers().get('x-pathname') ?? '/admin';
  const access = await authorizeAdminAccess(requestedPath);

  if (access.status === 'unauthenticated') {
    redirect(access.redirectTo);
  }

  if (access.status === 'forbidden') {
    return <AdminAccessDenied user={access.user} />;
  }

  return <AdminShell user={access.user}>{children}</AdminShell>;
}
