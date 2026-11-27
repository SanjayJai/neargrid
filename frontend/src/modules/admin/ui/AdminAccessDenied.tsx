import Link from 'next/link';
import { ShieldAlert } from 'lucide-react';
import type { UserDto } from '@/lib/bff/types';

interface AdminAccessDeniedProps {
  user: UserDto;
}

export function AdminAccessDenied({ user }: AdminAccessDeniedProps) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-12">
      <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-50 text-rose-600">
          <ShieldAlert className="h-6 w-6" aria-hidden="true" />
        </div>
        <h1 className="mt-4 text-xl font-bold tracking-tight text-slate-950">
          Super Admin Access Required
        </h1>
        <p className="mt-2 text-sm text-slate-600">
          Signed in as{' '}
          <span className="font-semibold text-slate-900">
            {user.name ?? user.phone_e164}
          </span>
          . Your account is not authorized to access the NearGrid Control Centre.
        </p>
        <div className="mt-6 flex flex-col gap-2.5 sm:flex-row sm:justify-center">
          <Link
            href="/"
            className="inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-xs font-semibold text-white hover:bg-slate-800"
          >
            Return to Directory
          </Link>
          <Link
            href="/account/profile"
            className="inline-flex items-center justify-center rounded-xl border border-slate-200 px-4 py-2.5 text-xs font-semibold text-slate-800 hover:bg-slate-50"
          >
            My Account
          </Link>
        </div>
      </div>
    </div>
  );
}
