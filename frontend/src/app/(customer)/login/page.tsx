import type { Metadata } from 'next';
import { OtpLoginForm } from '@/modules/customer/components/OtpLoginForm';
import { sanitizeInternalRedirectPath } from '@/modules/customer/lib/auth-redirect';

export const metadata: Metadata = {
  title: 'Sign in with Mobile OTP',
  robots: { index: false, follow: false },
};

interface LoginPageProps {
  searchParams?:
    | {
        next?: string | string[];
      }
    | Promise<{
        next?: string | string[];
      }>;
}

export default async function LoginPage({ searchParams }: LoginPageProps) {
  const resolvedSearchParams = await Promise.resolve(searchParams);
  const rawNext = Array.isArray(resolvedSearchParams?.next)
    ? resolvedSearchParams?.next[0]
    : resolvedSearchParams?.next;
  const redirectTo = sanitizeInternalRedirectPath(rawNext);

  return (
    <div className="mx-auto flex min-h-[70vh] max-w-7xl items-center justify-center px-4 py-12 sm:px-6 lg:px-8">
      <OtpLoginForm redirectTo={redirectTo} />
    </div>
  );
}
