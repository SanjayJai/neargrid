import type { Metadata } from 'next';
import { OtpLoginForm } from '@/modules/customer/components/OtpLoginForm';

export const metadata: Metadata = {
  title: 'Sign in with Mobile OTP',
  robots: { index: false, follow: false },
};

export default function LoginPage() {
  return (
    <div className="mx-auto flex min-h-[70vh] max-w-7xl items-center justify-center px-4 py-12 sm:px-6 lg:px-8">
      <OtpLoginForm />
    </div>
  );
}
