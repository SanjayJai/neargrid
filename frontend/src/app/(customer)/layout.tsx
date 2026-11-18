import { Suspense, type ReactNode } from 'react';
import { fetchPublicCategories, fetchPublicCities } from '@/lib/api/public-directory';
import { getServerAuthenticatedUser } from '@/lib/bff/session-cookie';
import { CustomerFooter } from '@/modules/customer/components/CustomerFooter';
import { CustomerHeader } from '@/modules/customer/components/CustomerHeader';

export default async function CustomerLayout({ children }: { children: ReactNode }) {
  const [user, citiesRes, categoriesRes] = await Promise.all([
    getServerAuthenticatedUser(),
    fetchPublicCities('en'),
    fetchPublicCategories('en'),
  ]);

  const cities = citiesRes.ok && citiesRes.data ? citiesRes.data : [];
  const categories = categoriesRes.ok && categoriesRes.data ? categoriesRes.data : [];

  return (
    <div className="flex min-h-screen flex-col bg-surface-canvas">
      <Suspense
        fallback={
          <header className="sticky top-0 z-40 h-16 border-b border-gray-200 bg-white shadow-sm" />
        }
      >
        <CustomerHeader user={user} cities={cities} />
      </Suspense>
      <main className="flex-1">{children}</main>
      <CustomerFooter cities={cities} categories={categories} />
    </div>
  );
}
