'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import {
  Bell,
  Check,
  CheckCheck,
  ExternalLink,
  Inbox,
  Loader2,
  ShieldAlert,
  Star,
  X,
} from 'lucide-react';
import type { UserDto } from '@/lib/bff/types';
import type { CustomerNotificationDto, NotificationCategory } from '@/shared/api/notification-types';
import {
  fetchNotifications,
  fetchUnreadCount,
  markAllNotificationsAsRead,
  markNotificationAsRead,
} from '@/modules/customer/lib/notifications-api';

interface NotificationBellFlyoutProps {
  user: UserDto | null;
}

function getCategoryMeta(category: NotificationCategory) {
  switch (category) {
    case 'enquiry':
      return {
        label: 'Enquiry',
        icon: Inbox,
        badgeClass: 'bg-signal-50 text-signal-700 border-signal-200',
        iconClass: 'text-signal-600',
      };
    case 'review':
      return {
        label: 'Review',
        icon: Star,
        badgeClass: 'bg-amber-50 text-amber-800 border-amber-200',
        iconClass: 'text-amber-600',
      };
    case 'account':
      return {
        label: 'Security',
        icon: ShieldAlert,
        badgeClass: 'bg-purple-50 text-purple-800 border-purple-200',
        iconClass: 'text-purple-600',
      };
    default:
      return {
        label: 'Update',
        icon: Bell,
        badgeClass: 'bg-gray-50 text-gray-700 border-gray-200',
        iconClass: 'text-gray-600',
      };
  }
}

function formatRelativeTime(dateString: string | null): string {
  if (!dateString) return '';
  const now = Date.now();
  const date = new Date(dateString).getTime();
  const diffSec = Math.floor((now - date) / 1000);

  if (diffSec < 60) return 'Just now';
  const diffMin = Math.floor(diffSec / 60);
  if (diffMin < 60) return `${diffMin}m ago`;
  const diffHour = Math.floor(diffMin / 60);
  if (diffHour < 24) return `${diffHour}h ago`;
  const diffDay = Math.floor(diffHour / 24);
  if (diffDay < 7) return `${diffDay}d ago`;

  return new Date(dateString).toLocaleDateString('en-IN', {
    month: 'short',
    day: 'numeric',
  });
}

export function NotificationBellFlyout({ user }: NotificationBellFlyoutProps) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [unreadCount, setUnreadCount] = useState<number>(0);
  const [notifications, setNotifications] = useState<CustomerNotificationDto[]>([]);
  const [loading, setLoading] = useState(false);
  const [markingAll, setMarkingAll] = useState(false);
  const flyoutRef = useRef<HTMLDivElement>(null);

  // Poll or fetch unread count when user changes
  useEffect(() => {
    if (!user) {
      setUnreadCount(0);
      return;
    }

    let active = true;
    async function loadCount() {
      try {
        const res = await fetchUnreadCount();
        if (active && res.ok && res.payload && res.payload.status === 'success') {
          setUnreadCount(res.payload.data.unread_count);
        }
      } catch {
        // graceful silence
      }
    }

    loadCount();
    return () => {
      active = false;
    };
  }, [user]);

  // Handle outside click and Escape key
  useEffect(() => {
    if (!open) return;

    function handleOutside(e: MouseEvent) {
      if (flyoutRef.current && !flyoutRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }

    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        setOpen(false);
      }
    }

    document.addEventListener('mousedown', handleOutside);
    document.addEventListener('keydown', handleKeyDown);

    return () => {
      document.removeEventListener('mousedown', handleOutside);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [open]);

  // Fetch recent notifications when flyout opens
  async function handleToggleOpen() {
    const nextState = !open;
    setOpen(nextState);

    if (nextState) {
      setLoading(true);
      try {
        const [listRes, countRes] = await Promise.all([
          fetchNotifications({ per_page: 5 }),
          fetchUnreadCount(),
        ]);

        if (listRes.ok && listRes.payload && listRes.payload.status === 'success') {
          setNotifications(listRes.payload.data);
        }
        if (countRes.ok && countRes.payload && countRes.payload.status === 'success') {
          setUnreadCount(countRes.payload.data.unread_count);
        }
      } finally {
        setLoading(false);
      }
    }
  }

  async function handleMarkRead(id: string, e?: React.MouseEvent) {
    e?.stopPropagation();
    try {
      await markNotificationAsRead(id);
      setNotifications((prev) =>
        prev.map((n) => (n.id === id ? { ...n, is_read: true, read_at: new Date().toISOString() } : n))
      );
      setUnreadCount((c) => Math.max(0, c - 1));
    } catch {
      // optimistic fallback
    }
  }

  async function handleMarkAll() {
    setMarkingAll(true);
    try {
      await markAllNotificationsAsRead();
      setNotifications((prev) =>
        prev.map((n) => ({ ...n, is_read: true, read_at: new Date().toISOString() }))
      );
      setUnreadCount(0);
    } finally {
      setMarkingAll(false);
    }
  }

  function handleNotificationClick(notification: CustomerNotificationDto) {
    if (!notification.is_read) {
      handleMarkRead(notification.id);
    }
    setOpen(false);

    if (notification.action_url) {
      router.push(notification.action_url);
    } else {
      router.push('/notifications');
    }
  }

  if (!user) return null;

  return (
    <div ref={flyoutRef} className="relative inline-block text-left">
      <button
        type="button"
        onClick={handleToggleOpen}
        aria-label={
          unreadCount > 0 ? `${unreadCount} unread notifications` : 'Notifications'
        }
        aria-expanded={open}
        aria-haspopup="dialog"
        className="relative inline-flex items-center justify-center rounded-lg border border-gray-200 bg-white p-2 text-ink transition-colors hover:border-brand-300 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-500"
      >
        <Bell className="h-4 w-4 text-ink-subtle hover:text-brand-600" aria-hidden="true" />
        {unreadCount > 0 && (
          <span
            data-testid="notification-badge"
            className="absolute -right-1 -top-1 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-signal-600 px-1 text-[10px] font-extrabold text-white shadow-sm"
          >
            {unreadCount > 99 ? '99+' : unreadCount}
          </span>
        )}
      </button>

      {open && (
        <div
          role="dialog"
          aria-label="Recent notifications flyout"
          className="absolute right-0 z-50 mt-2 w-80 sm:w-96 rounded-xl border border-gray-200 bg-white shadow-2xl transition-all"
        >
          {/* Header */}
          <div className="flex items-center justify-between border-b border-gray-100 px-4 py-3">
            <div className="flex items-center gap-2">
              <span className="font-semibold text-sm text-ink">Notifications</span>
              {unreadCount > 0 && (
                <span className="rounded-full bg-signal-50 px-2 py-0.5 text-xs font-bold text-signal-700">
                  {unreadCount} new
                </span>
              )}
            </div>
            <div className="flex items-center gap-2">
              {unreadCount > 0 && (
                <button
                  type="button"
                  onClick={handleMarkAll}
                  disabled={markingAll}
                  className="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-800 disabled:opacity-50"
                >
                  {markingAll ? (
                    <Loader2 className="h-3 w-3 animate-spin" />
                  ) : (
                    <CheckCheck className="h-3.5 w-3.5" />
                  )}
                  <span>Mark all read</span>
                </button>
              )}
              <button
                type="button"
                onClick={() => setOpen(false)}
                aria-label="Close notifications flyout"
                className="rounded-md p-1 text-ink-subtle hover:bg-gray-100 hover:text-ink"
              >
                <X className="h-4 w-4" />
              </button>
            </div>
          </div>

          {/* List Content */}
          <div className="max-h-80 overflow-y-auto divide-y divide-gray-100">
            {loading ? (
              <div className="space-y-3 p-4">
                {[1, 2, 3].map((i) => (
                  <div key={i} className="animate-pulse flex items-start gap-3">
                    <div className="h-8 w-8 rounded-lg bg-gray-200" />
                    <div className="flex-1 space-y-1.5">
                      <div className="h-3.5 w-3/4 rounded bg-gray-200" />
                      <div className="h-3 w-full rounded bg-gray-100" />
                    </div>
                  </div>
                ))}
              </div>
            ) : notifications.length === 0 ? (
              <div className="px-4 py-8 text-center">
                <div className="mx-auto mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-gray-50 text-ink-subtle">
                  <Bell className="h-5 w-5" />
                </div>
                <p className="text-xs font-medium text-ink">No notifications yet</p>
                <p className="mt-0.5 text-[11px] text-ink-subtle">
                  We will alert you when businesses respond to enquiries or your reviews receive replies.
                </p>
              </div>
            ) : (
              notifications.map((notification) => {
                const meta = getCategoryMeta(notification.category);
                const IconComponent = meta.icon;

                return (
                  <div
                    key={notification.id}
                    onClick={() => handleNotificationClick(notification)}
                    className={`flex items-start gap-3 p-3 text-left transition-colors cursor-pointer hover:bg-gray-50 ${
                      !notification.is_read ? 'bg-signal-50/30' : 'bg-white'
                    }`}
                  >
                    <div
                      className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border ${meta.badgeClass}`}
                    >
                      <IconComponent className={`h-4 w-4 ${meta.iconClass}`} aria-hidden="true" />
                    </div>

                    <div className="min-w-0 flex-1">
                      <div className="flex items-center justify-between gap-1">
                        <span className="truncate text-xs font-semibold text-ink">
                          {notification.title}
                        </span>
                        <span className="shrink-0 text-[10px] text-ink-subtle">
                          {formatRelativeTime(notification.created_at)}
                        </span>
                      </div>
                      <p className="mt-0.5 line-clamp-2 text-xs text-ink-muted">
                        {notification.message}
                      </p>

                      <div className="mt-1 flex items-center justify-between pt-0.5">
                        <span className="text-[10px] font-medium text-ink-subtle uppercase tracking-wider">
                          {meta.label}
                        </span>
                        {notification.action_url && (
                          <span className="inline-flex items-center gap-0.5 text-[11px] font-semibold text-brand-600">
                            <span>Open</span>
                            <ExternalLink className="h-2.5 w-2.5" />
                          </span>
                        )}
                      </div>
                    </div>

                    {!notification.is_read && (
                      <button
                        type="button"
                        onClick={(e) => handleMarkRead(notification.id, e)}
                        title="Mark as read"
                        aria-label="Mark as read"
                        className="mt-0.5 shrink-0 rounded p-1 text-signal-600 hover:bg-signal-100"
                      >
                        <Check className="h-3.5 w-3.5" />
                      </button>
                    )}
                  </div>
                );
              })
            )}
          </div>

          {/* Footer */}
          <div className="border-t border-gray-100 bg-gray-50/80 px-4 py-2.5 text-center">
            <Link
              href="/notifications"
              onClick={() => setOpen(false)}
              className="text-xs font-semibold text-brand-600 hover:text-brand-800"
            >
              View all in Notification Centre →
            </Link>
          </div>
        </div>
      )}
    </div>
  );
}
