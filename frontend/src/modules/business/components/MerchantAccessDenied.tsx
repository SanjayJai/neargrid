import Link from 'next/link';
import { ShieldAlert } from 'lucide-react';
import type { UserDto } from '@/lib/bff/types';

interface MerchantAccessDeniedProps {
  user: UserDto;
}

export function MerchantAccessDenied({ user }: MerchantAccessDeniedProps) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-zinc-50 px-4 py-12">
      <div className="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-8 text-center shadow-sm">
        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-50 text-rose-600">
          <ShieldAlert className="h-6 w-6" aria-hidden="true" />
        </div>
        <h1 className="mt-4 text-xl font-semibold tracking-tight text-zinc-950">
          Merchant Access Required
        </h1>
        <p className="mt-2 text-sm text-zinc-600">
          Signed in as <span className="font-medium text-zinc-900">{user.name ?? user.phone_e164}</span>.
          Your account does not have an active business owner or staff membership.
        </p>
        <div className="mt-6 flex flex-col gap-2.5 sm:flex-row sm:justify-center">
          <Link
            href="/"
            className="inline-flex items-center justify-center rounded-xl bg-zinc-950 px-4 py-2.5 text-xs font-semibold text-white hover:bg-zinc-800"
          >
            Browse Directory
          </Link>
          <Link
            href="/saved"
            className="inline-flex items-center justify-center rounded-xl border border-zinc-200 px-4 py-2.5 text-xs font-semibold text-zinc-800 hover:bg-zinc-50"
          >
            Saved Businesses
          </Link>
        </div>
      </div>
    </div>
  );
}
