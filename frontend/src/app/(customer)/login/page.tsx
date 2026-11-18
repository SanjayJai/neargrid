import type { Metadata } from 'next';
import {
  OtpLoginForm,
  sanitizeInternalRedirectPath,
} from '@/modules/customer/components/OtpLoginForm';

export const metadata: Metadata = {
  title: 'Sign in with Mobile OTP',
  robots: { index: false, follow: false },
};

interface LoginPageProps {
  searchParams?: {
    next?: string | string[];
  };
}

export default function LoginPage({ searchParams }: LoginPageProps) {
  const rawNext = Array.isArray(searchParams?.next)
    ? searchParams?.next[0]
    : searchParams?.next;
  const redirectTo = sanitizeInternalRedirectPath(rawNext);

  return (
    <div className="mx-auto flex min-h-[70vh] max-w-7xl items-center justify-center px-4 py-12 sm:px-6 lg:px-8">
      <OtpLoginForm redirectTo={redirectTo} />
    </div>
  );
}
