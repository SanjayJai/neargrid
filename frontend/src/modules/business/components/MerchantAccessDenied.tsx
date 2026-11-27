'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Building2, Compass, KeyRound, ShieldAlert, ShieldCheck, UserCircle } from 'lucide-react';
import type { UserDto } from '@/lib/bff/types';
import { CreateBusinessModal } from '@/modules/business/components/CreateBusinessModal';

interface MerchantAccessDeniedProps {
  user: UserDto;
}

export function MerchantAccessDenied({ user }: MerchantAccessDeniedProps) {
  const router = useRouter();
  const [createModalOpen, setCreateModalOpen] = useState(false);
  const isSuperAdmin = user.roles.includes('super_admin');

  return (
    <div className="flex min-h-screen items-center justify-center bg-zinc-50 px-4 py-12">
      <div className="w-full max-w-lg rounded-2xl border border-zinc-200 bg-white p-8 text-center shadow-sm">
        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-50 text-rose-600">
          <ShieldAlert className="h-6 w-6" aria-hidden="true" />
        </div>
        <h1 className="mt-4 text-xl font-semibold tracking-tight text-zinc-950">
          Merchant Access Required
        </h1>
        <p className="mt-2 text-sm text-zinc-600">
          Signed in as{' '}
          <span className="font-medium text-zinc-900">
            {user.name ?? user.email ?? user.phone_e164}
          </span>
          . Your account does not have an active business owner or staff membership.
        </p>
        <p className="mt-1.5 text-xs text-zinc-500">
          Register a new listing or claim an existing business to unlock the Merchant Dashboard
          after verification and approval.
        </p>

        <div className="mt-6 grid gap-2.5 sm:grid-cols-2">
          <button
            type="button"
            onClick={() => setCreateModalOpen(true)}
            className="inline-flex items-center justify-center gap-2 rounded-xl bg-zinc-950 px-4 py-2.5 text-xs font-semibold text-white hover:bg-zinc-800"
          >
            <Building2 className="h-4 w-4" aria-hidden="true" />
            Register Your Business
          </button>
          <Link
            href="/search"
            className="inline-flex items-center justify-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-xs font-semibold text-zinc-900 hover:bg-zinc-50"
          >
            <KeyRound className="h-4 w-4" aria-hidden="true" />
            Claim an Existing Business
          </Link>
          <Link
            href="/"
            className="inline-flex items-center justify-center gap-2 rounded-xl border border-zinc-200 px-4 py-2.5 text-xs font-semibold text-zinc-800 hover:bg-zinc-50"
          >
            <Compass className="h-4 w-4" aria-hidden="true" />
            Browse Directory
          </Link>
          <Link
            href="/account"
            className="inline-flex items-center justify-center gap-2 rounded-xl border border-zinc-200 px-4 py-2.5 text-xs font-semibold text-zinc-800 hover:bg-zinc-50"
          >
            <UserCircle className="h-4 w-4" aria-hidden="true" />
            Go to My Account
          </Link>
        </div>

        {isSuperAdmin && (
          <div className="mt-4 border-t border-zinc-100 pt-4">
            <Link
              href="/admin"
              className="inline-flex items-center justify-center gap-1.5 text-xs font-semibold text-sky-700 hover:text-sky-900"
            >
              <ShieldCheck className="h-4 w-4" aria-hidden="true" />
              Open Super Admin Control Centre (/admin)
            </Link>
          </div>
        )}
      </div>

      <CreateBusinessModal
        isOpen={createModalOpen}
        onClose={() => setCreateModalOpen(false)}
        onCreated={() => {
          setCreateModalOpen(false);
          router.push('/business/dashboard');
          router.refresh();
        }}
      />
    </div>
  );
}
