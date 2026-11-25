'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useState, type ReactNode } from 'react';
import {
  ArrowLeft,
  Building2,
  ChevronDown,
  Clock,
  Images,
  Inbox,
  LayoutDashboard,
  LogOut,
  Menu,
  Plus,
  Settings,
  ShieldCheck,
  Users,
  X,
} from 'lucide-react';
import type { UserDto } from '@/lib/bff/types';
import { bffFetch } from '@/shared/api/bff-client';
import { useOwnerBusiness } from '@/modules/business/context/OwnerBusinessContext';
import { CreateBusinessModal } from '@/modules/business/components/CreateBusinessModal';
import { NotificationBellFlyout } from '@/modules/customer/components/NotificationBellFlyout';

const NAV_ITEMS = [
  { href: '/dashboard', label: 'Overview', icon: LayoutDashboard },
  { href: '/dashboard/profile', label: 'Business Profile', icon: Building2 },
  { href: '/dashboard/media-services', label: 'Photos & Services', icon: Images },
  { href: '/dashboard/hours', label: 'Opening Hours', icon: Clock },
  { href: '/dashboard/team', label: 'Team & Staff', icon: Users },
  { href: '/dashboard/verification', label: 'Verification & KYC', icon: ShieldCheck },
  { href: '/dashboard/enquiries', label: 'Enquiries', icon: Inbox },
  { href: '/dashboard/settings', label: 'Account & Devices', icon: Settings },
] as const;

interface BusinessShellProps {
  user: UserDto;
  children: ReactNode;
}

export function BusinessShell({ user, children }: BusinessShellProps) {
  const pathname = usePathname();
  const router = useRouter();
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const [createModalOpen, setCreateModalOpen] = useState(false);

  const {
    businesses,
    activeBusiness,
    activeBusinessId,
    setActiveBusinessId,
    refreshBusinesses,
  } = useOwnerBusiness();

  async function handleLogout() {
    setLoggingOut(true);
    try {
      await bffFetch('/api/auth/logout', { method: 'POST' });
      router.push('/login');
      router.refresh();
    } finally {
      setLoggingOut(false);
    }
  }

  return (
    <div className="min-h-screen bg-zinc-50 text-zinc-950">
      {/* Top header */}
      <header className="sticky top-0 z-40 border-b border-zinc-200 bg-white">
        <div className="flex h-16 items-center justify-between px-4 sm:px-6">
          <div className="flex items-center gap-3">
            <button
              type="button"
              onClick={() => setSidebarOpen((prev) => !prev)}
              aria-label="Toggle dashboard navigation"
              aria-expanded={sidebarOpen}
              className="inline-flex rounded-lg border border-zinc-200 p-2 text-zinc-800 lg:hidden"
            >
              {sidebarOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
            </button>

            <Link href="/dashboard" className="flex items-center gap-2.5">
              <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-zinc-950 text-xs font-bold text-white">
                NG
              </span>
              <div className="leading-tight">
                <span className="block text-sm font-semibold text-zinc-950">NearGrid Business</span>
                <span className="block text-[11px] text-zinc-500">Merchant Workspace</span>
              </div>
            </Link>

            {/* Active business selector (if user has listings) */}
            {businesses.length > 0 && (
              <div className="ml-4 hidden items-center gap-2 lg:flex">
                <div className="relative">
                  <select
                    value={activeBusinessId ?? ''}
                    onChange={(e) => setActiveBusinessId(e.target.value)}
                    aria-label="Switch active business listing"
                    className="h-8 cursor-pointer appearance-none rounded-lg border border-zinc-200 bg-zinc-50 pl-2.5 pr-7 text-xs font-semibold text-zinc-900 hover:bg-zinc-100 focus:border-zinc-950 focus:outline-none"
                  >
                    {businesses.map((biz) => (
                      <option key={biz.public_id} value={biz.public_id}>
                        {biz.name}
                      </option>
                    ))}
                  </select>
                  <ChevronDown
                    className="pointer-events-none absolute right-2 top-2.5 h-3.5 w-3.5 text-zinc-400"
                    aria-hidden="true"
                  />
                </div>
              </div>
            )}
          </div>

          <div className="flex items-center gap-2.5 sm:gap-3">
            <button
              type="button"
              onClick={() => setCreateModalOpen(true)}
              className="inline-flex items-center gap-1 rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-zinc-800 hover:bg-zinc-50 sm:px-3"
            >
              <Plus className="h-3.5 w-3.5" aria-hidden="true" />
              <span className="hidden sm:inline">Add Listing</span>
              <span className="sm:hidden">New</span>
            </button>

            <Link
              href="/"
              className="hidden items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50 sm:inline-flex"
            >
              <ArrowLeft className="h-3.5 w-3.5" aria-hidden="true" />
              Customer Site
            </Link>

            <div className="flex items-center gap-2 sm:gap-2.5">
              <NotificationBellFlyout user={user} />
              <span className="hidden text-xs font-medium text-zinc-700 md:inline">
                {user.name ?? user.phone_e164}
              </span>
              <button
                type="button"
                onClick={handleLogout}
                disabled={loggingOut}
                className="inline-flex items-center gap-1.5 rounded-lg bg-zinc-950 px-3 py-1.5 text-xs font-semibold text-white hover:bg-zinc-800 disabled:opacity-50"
              >
                <LogOut className="h-3.5 w-3.5" aria-hidden="true" />
                {loggingOut ? 'Signing out...' : 'Sign out'}
              </button>
            </div>
          </div>
        </div>
      </header>

      <div className="mx-auto flex max-w-7xl">
        {/* Sidebar */}
        <aside
          aria-label="Business dashboard navigation"
          className={`${
            sidebarOpen ? 'block' : 'hidden'
          } fixed inset-y-16 left-0 z-30 w-64 border-r border-zinc-200 bg-white p-4 lg:static lg:block lg:min-h-[calc(100vh-4rem)]`}
        >
          {/* Mobile active business selector */}
          {businesses.length > 0 && (
            <div className="mb-4 lg:hidden">
              <label htmlFor="mobile-biz-select" className="mb-1 block text-[11px] font-semibold text-zinc-500">
                Active Listing
              </label>
              <select
                id="mobile-biz-select"
                value={activeBusinessId ?? ''}
                onChange={(e) => setActiveBusinessId(e.target.value)}
                className="w-full rounded-lg border border-zinc-200 bg-zinc-50 p-2 text-xs font-semibold text-zinc-900"
              >
                {businesses.map((biz) => (
                  <option key={biz.public_id} value={biz.public_id}>
                    {biz.name}
                  </option>
                ))}
              </select>
            </div>
          )}

          <nav className="space-y-1">
            {NAV_ITEMS.map((item) => {
              const Icon = item.icon;
              const active = pathname === item.href;
              return (
                <Link
                  key={item.href}
                  href={item.href}
                  onClick={() => setSidebarOpen(false)}
                  aria-current={active ? 'page' : undefined}
                  className={`flex items-center gap-2.5 rounded-xl px-3.5 py-2.5 text-sm font-medium transition-colors ${
                    active
                      ? 'bg-zinc-950 text-white'
                      : 'text-zinc-700 hover:bg-zinc-100 hover:text-zinc-950'
                  }`}
                >
                  <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />
                  <span>{item.label}</span>
                </Link>
              );
            })}
          </nav>

          {activeBusiness && (
            <div className="mt-6 rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-600">
              <p className="font-semibold text-zinc-900 truncate">{activeBusiness.name}</p>
              <p className="mt-0.5 text-[11px] text-zinc-500">
                Score: {activeBusiness.completeness_score}% · {activeBusiness.status}
              </p>
            </div>
          )}

          <div className="mt-4 rounded-xl border border-zinc-200 bg-zinc-50 p-3.5 text-xs text-zinc-600">
            <p className="font-semibold text-zinc-900">Live BFF Session Active</p>
            <p className="mt-1 leading-relaxed">
              All storefront actions connect live to Laravel 13 via Next.js BFF.
            </p>
          </div>
        </aside>

        <main className="min-w-0 flex-1 p-4 sm:p-6 lg:p-8">{children}</main>
      </div>

      <CreateBusinessModal
        isOpen={createModalOpen}
        onClose={() => setCreateModalOpen(false)}
        onCreated={async (newBiz) => {
          await refreshBusinesses();
          setActiveBusinessId(newBiz.public_id);
        }}
      />
    </div>
  );
}
