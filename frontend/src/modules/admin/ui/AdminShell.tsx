'use client';

import { useEffect, useState, type ReactNode } from 'react';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import {
  Bell,
  ChevronLeft,
  ChevronRight,
  LogOut,
  Menu,
  Moon,
  Search,
  ShieldCheck,
  Sun,
  X,
} from 'lucide-react';
import type { UserDto } from '@/lib/bff/types';
import { ADMIN_NAV_GROUPS } from '@/modules/admin/lib/admin-navigation';
import { ensureCsrfToken } from '@/shared/api/bff-client';
import { AdminCommandPalette } from './AdminCommandPalette';
import { AdminStatusBadge } from './AdminStatusBadge';

interface AdminShellProps {
  user: UserDto;
  children?: ReactNode;
}

export function AdminShell({ user, children }: AdminShellProps) {
  const pathname = usePathname();
  const router = useRouter();

  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [paletteOpen, setPaletteOpen] = useState(false);
  const [notificationsOpen, setNotificationsOpen] = useState(false);
  const [theme, setTheme] = useState<'light' | 'dark'>('light');
  const [loggingOut, setLoggingOut] = useState(false);

  useEffect(() => {
    const match = document.cookie.match(/(?:^|;\s*)neargrid_admin_theme=(dark|light)/);
    if (match && (match[1] === 'dark' || match[1] === 'light')) {
      setTheme(match[1]);
    }
  }, []);

  useEffect(() => {
    function onKeyDown(e: KeyboardEvent) {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setPaletteOpen((prev) => !prev);
      }
    }
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, []);

  function toggleTheme() {
    const next = theme === 'light' ? 'dark' : 'light';
    setTheme(next);
    document.cookie = `neargrid_admin_theme=${next}; path=/admin; max-age=31536000; SameSite=Lax`;
  }

  async function handleLogout() {
    setLoggingOut(true);
    try {
      const csrf = await ensureCsrfToken();
      await fetch('/api/auth/logout', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'x-csrf-token': csrf },
      });
    } finally {
      router.push('/login');
      router.refresh();
    }
  }

  const sidebarContent = (isMobile: boolean) => (
    <div className="flex h-full flex-col">
      {/* Brand Header */}
      <div className="flex h-16 items-center justify-between border-b border-slate-200 px-4 dark:border-slate-800">
        <Link href="/admin" className="flex items-center gap-2.5 overflow-hidden">
          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-white shadow-sm">
            <ShieldCheck className="h-5 w-5" aria-hidden="true" />
          </div>
          {(!collapsed || isMobile) && (
            <div className="min-w-0">
              <p className="truncate text-sm font-extrabold tracking-tight text-slate-950 dark:text-white">
                NearGrid
              </p>
              <p className="truncate text-[10px] font-bold uppercase tracking-wider text-brand-600 dark:text-brand-400">
                Control Centre
              </p>
            </div>
          )}
        </Link>

        {isMobile ? (
          <button
            type="button"
            onClick={() => setMobileOpen(false)}
            aria-label="Close navigation menu"
            className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
          >
            <X className="h-4 w-4" aria-hidden="true" />
          </button>
        ) : (
          <button
            type="button"
            onClick={() => setCollapsed((prev) => !prev)}
            aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            className="hidden rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 lg:inline-flex dark:hover:bg-slate-800 dark:hover:text-white"
          >
            {collapsed ? (
              <ChevronRight className="h-4 w-4" aria-hidden="true" />
            ) : (
              <ChevronLeft className="h-4 w-4" aria-hidden="true" />
            )}
          </button>
        )}
      </div>

      {/* Grouped Navigation */}
      <nav
        aria-label="Admin sidebar navigation"
        className="flex-1 space-y-5 overflow-y-auto px-3 py-4"
      >
        {ADMIN_NAV_GROUPS.map((group) => (
          <div key={group.key}>
            {(!collapsed || isMobile) && (
              <p className="mb-1.5 px-2.5 text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">
                {group.label}
              </p>
            )}
            <ul className="space-y-0.5">
              {group.items.map((item) => {
                const isActive = pathname === item.href;
                if (!item.enabled) {
                  return (
                    <li key={item.key}>
                      <span
                        title={`${item.label} (${item.milestone})`}
                        className="flex cursor-not-allowed items-center justify-between gap-2 rounded-xl px-2.5 py-2 text-xs font-medium text-slate-400 dark:text-slate-600"
                      >
                        <span className="truncate">
                          {collapsed && !isMobile ? item.label.slice(0, 2) : item.label}
                        </span>
                        {(!collapsed || isMobile) && (
                          <AdminStatusBadge label={item.milestone} tone="neutral" />
                        )}
                      </span>
                    </li>
                  );
                }
                return (
                  <li key={item.key}>
                    <Link
                      href={item.href}
                      onClick={() => isMobile && setMobileOpen(false)}
                      className={`flex items-center justify-between gap-2 rounded-xl px-2.5 py-2 text-xs font-semibold transition-colors ${
                        isActive
                          ? 'bg-brand-50 text-brand-700 dark:bg-brand-950/70 dark:text-brand-300'
                          : 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'
                      }`}
                    >
                      <span className="truncate">
                        {collapsed && !isMobile ? item.label.slice(0, 2) : item.label}
                      </span>
                    </Link>
                  </li>
                );
              })}
            </ul>
          </div>
        ))}
      </nav>

      {/* Admin Profile Footer */}
      <div className="border-t border-slate-200 p-3 dark:border-slate-800">
        {(!collapsed || isMobile) && (
          <div className="mb-2 rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-800/70">
            <p className="truncate text-xs font-bold text-slate-900 dark:text-white">
              {user.name ?? 'Super Admin'}
            </p>
            <p className="truncate text-[11px] text-slate-500 dark:text-slate-400">
              {user.email ?? user.phone_e164}
            </p>
          </div>
        )}
        <button
          type="button"
          onClick={handleLogout}
          disabled={loggingOut}
          className="flex w-full items-center justify-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
        >
          <LogOut className="h-3.5 w-3.5" aria-hidden="true" />
          {(!collapsed || isMobile) && (
            <span>{loggingOut ? 'Signing out...' : 'Sign out'}</span>
          )}
        </button>
      </div>
    </div>
  );

  return (
    <div className={theme === 'dark' ? 'dark' : ''}>
      <div className="flex min-h-screen bg-slate-50 text-slate-950 dark:bg-slate-950 dark:text-slate-100">
        {/* Desktop Collapsible Sidebar */}
        <aside
          className={`hidden shrink-0 border-r border-slate-200 bg-white transition-all lg:block dark:border-slate-800 dark:bg-slate-900 ${
            collapsed ? 'w-20' : 'w-68'
          }`}
        >
          {sidebarContent(false)}
        </aside>

        {/* Mobile / Tablet Slide-Over Drawer */}
        {mobileOpen && (
          <div className="fixed inset-0 z-50 flex lg:hidden">
            <div
              className="fixed inset-0 bg-slate-950/60"
              onClick={() => setMobileOpen(false)}
            />
            <aside className="relative z-10 h-full w-72 border-r border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
              {sidebarContent(true)}
            </aside>
          </div>
        )}

        {/* Main Workspace */}
        <div className="flex min-w-0 flex-1 flex-col">
          {/* Top Bar */}
          <header className="sticky top-0 z-30 flex h-16 items-center justify-between gap-3 border-b border-slate-200 bg-white/95 px-4 backdrop-blur sm:px-6 dark:border-slate-800 dark:bg-slate-900/95">
            <div className="flex items-center gap-3">
              <button
                type="button"
                onClick={() => setMobileOpen(true)}
                aria-label="Open navigation menu"
                className="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden dark:text-slate-300 dark:hover:bg-slate-800"
              >
                <Menu className="h-5 w-5" aria-hidden="true" />
              </button>

              <button
                type="button"
                onClick={() => setPaletteOpen(true)}
                className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs text-slate-500 hover:border-slate-300 hover:bg-white dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300"
              >
                <Search className="h-3.5 w-3.5" aria-hidden="true" />
                <span className="hidden sm:inline">Search admin modules...</span>
                <kbd className="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-[10px] font-semibold text-slate-600 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200">
                  ⌘K
                </kbd>
              </button>
            </div>

            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={toggleTheme}
                aria-label={theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'}
                className="rounded-xl border border-slate-200 p-2 text-slate-600 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
              >
                {theme === 'dark' ? (
                  <Sun className="h-4 w-4" aria-hidden="true" />
                ) : (
                  <Moon className="h-4 w-4" aria-hidden="true" />
                )}
              </button>

              <div className="relative">
                <button
                  type="button"
                  onClick={() => setNotificationsOpen((prev) => !prev)}
                  aria-label="Admin notifications"
                  aria-expanded={notificationsOpen}
                  className="rounded-xl border border-slate-200 p-2 text-slate-600 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                >
                  <Bell className="h-4 w-4" aria-hidden="true" />
                </button>
                {notificationsOpen && (
                  <div className="absolute right-0 mt-2 w-72 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl dark:border-slate-800 dark:bg-slate-900">
                    <p className="text-xs font-bold text-slate-900 dark:text-white">
                      Notification Centre
                    </p>
                    <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                      Live platform alerts and broadcast controls roll out in M7.
                    </p>
                  </div>
                )}
              </div>

              <Link
                href="/"
                className="hidden rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 sm:inline-flex dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
              >
                View Storefront
              </Link>
            </div>
          </header>

          {/* Page Content */}
          <main className="flex-1 p-4 sm:p-6 lg:p-8">{children}</main>
        </div>
      </div>

      <AdminCommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
    </div>
  );
}
