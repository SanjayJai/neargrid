'use client';

import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { useCallback, useEffect, useState } from 'react';
import {
  AlertTriangle,
  ArrowRight,
  Bell,
  Check,
  CheckCheck,
  ChevronLeft,
  ChevronRight,
  Inbox,
  Loader2,
  RefreshCw,
  ShieldAlert,
  Star,
  Trash2,
} from 'lucide-react';
import type { UserDto } from '@/lib/bff/types';
import type {
  CustomerNotificationDto,
  NotificationCategory,
  NotificationPaginationMeta,
} from '@/shared/api/notification-types';
import {
  deleteNotification,
  fetchNotifications,
  fetchUnreadCount,
  markAllNotificationsAsRead,
  markNotificationAsRead,
} from '@/modules/customer/lib/notifications-api';

interface CustomerNotificationCentreProps {
  initialUser: UserDto;
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
        label: 'Account Security',
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

function formatFullDate(dateString: string | null): string {
  if (!dateString) return '';
  return new Date(dateString).toLocaleDateString('en-IN', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
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

  return formatFullDate(dateString);
}

export function CustomerNotificationCentre({ initialUser }: CustomerNotificationCentreProps) {
  const router = useRouter();
  const searchParams = useSearchParams();

  const [statusFilter, setStatusFilter] = useState<'all' | 'unread'>(
    searchParams.get('status') === 'unread' ? 'unread' : 'all'
  );
  const [categoryFilter, setCategoryFilter] = useState<'all' | NotificationCategory>(
    (searchParams.get('category') as NotificationCategory) || 'all'
  );
  const [currentPage, setCurrentPage] = useState<number>(
    Math.max(1, Number(searchParams.get('page')) || 1)
  );

  const [notifications, setNotifications] = useState<CustomerNotificationDto[]>([]);
  const [pagination, setPagination] = useState<NotificationPaginationMeta>({
    total: 0,
    count: 0,
    per_page: 10,
    current_page: 1,
    total_pages: 1,
  });
  const [unreadCount, setUnreadCount] = useState<number>(0);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [actionInProgress, setActionInProgress] = useState<string | null>(null);

  const loadNotifications = useCallback(async () => {
    setLoading(true);
    setError(null);

    try {
      const [listRes, countRes] = await Promise.all([
        fetchNotifications({
          status: statusFilter,
          category: categoryFilter,
          page: currentPage,
          per_page: 10,
        }),
        fetchUnreadCount(),
      ]);

      if (listRes.ok && listRes.payload && listRes.payload.status === 'success') {
        setNotifications(listRes.payload.data);
        if (listRes.payload.meta?.pagination) {
          const p = listRes.payload.meta.pagination;
          const total = p.total ?? 0;
          const perPage = p.per_page ?? 10;
          const totalPages = p.last_page ?? (total > 0 ? Math.ceil(total / perPage) : 1);
          setPagination({
            total,
            count: listRes.payload.data.length,
            per_page: perPage,
            current_page: p.current_page ?? currentPage,
            total_pages: totalPages,
          });
        }
      } else {
        const errorMsg =
          listRes.payload && 'error' in listRes.payload
            ? listRes.payload.error.message
            : 'Unable to load notifications.';
        setError(errorMsg);
      }

      if (countRes.ok && countRes.payload && countRes.payload.status === 'success') {
        setUnreadCount(countRes.payload.data.unread_count);
      }
    } catch {
      setError('A network or server error occurred. Please try again.');
    } finally {
      setLoading(false);
    }
  }, [statusFilter, categoryFilter, currentPage]);

  useEffect(() => {
    loadNotifications();
  }, [loadNotifications]);

  async function handleMarkRead(id: string) {
    setActionInProgress(`read:${id}`);
    try {
      await markNotificationAsRead(id);
      setNotifications((prev) =>
        prev.map((n) => (n.id === id ? { ...n, is_read: true, read_at: new Date().toISOString() } : n))
      );
      setUnreadCount((c) => Math.max(0, c - 1));
    } catch {
      // optimistic fallback
    } finally {
      setActionInProgress(null);
    }
  }

  async function handleMarkAll() {
    setActionInProgress('mark-all');
    try {
      await markAllNotificationsAsRead();
      setNotifications((prev) =>
        prev.map((n) => ({ ...n, is_read: true, read_at: new Date().toISOString() }))
      );
      setUnreadCount(0);
    } catch {
      // fallback
    } finally {
      setActionInProgress(null);
    }
  }

  async function handleDelete(id: string) {
    setActionInProgress(`delete:${id}`);
    try {
      await deleteNotification(id);
      const wasUnread = notifications.find((n) => n.id === id)?.is_read === false;
      setNotifications((prev) => prev.filter((n) => n.id !== id));
      setPagination((p) => ({ ...p, total: Math.max(0, p.total - 1) }));
      if (wasUnread) {
        setUnreadCount((c) => Math.max(0, c - 1));
      }
    } catch {
      // fallback
    } finally {
      setActionInProgress(null);
    }
  }

  function handleActionClick(notification: CustomerNotificationDto) {
    if (!notification.is_read) {
      handleMarkRead(notification.id);
    }
    if (notification.action_url) {
      router.push(notification.action_url);
    }
  }

  function handleStatusChange(status: 'all' | 'unread') {
    setStatusFilter(status);
    setCurrentPage(1);
  }

  function handleCategoryChange(category: 'all' | NotificationCategory) {
    setCategoryFilter(category);
    setCurrentPage(1);
  }

  return (
    <div className="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
      {/* Top Header & Breadcrumb */}
      <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <nav className="mb-1 text-xs text-ink-subtle" aria-label="Breadcrumb">
            <Link href="/" className="hover:text-ink">
              Home
            </Link>
            <span className="mx-1.5">/</span>
            <span className="font-semibold text-ink">Notifications</span>
          </nav>
          <h1 className="text-2xl font-bold tracking-tight text-ink sm:text-3xl">
            Notification Centre
          </h1>
          <p className="mt-1 text-xs text-ink-muted sm:text-sm">
            Updates and alerts for {initialUser.name ?? initialUser.phone_e164 ?? 'your account'}. Stay informed on enquiry matches, merchant responses, and security events.
          </p>
        </div>

        {unreadCount > 0 && (
          <button
            type="button"
            onClick={handleMarkAll}
            disabled={actionInProgress === 'mark-all'}
            className="inline-flex items-center gap-1.5 rounded-lg border border-brand-200 bg-brand-50 px-3.5 py-2 text-xs font-semibold text-brand-700 shadow-sm transition-colors hover:bg-brand-100 disabled:opacity-50"
          >
            {actionInProgress === 'mark-all' ? (
              <Loader2 className="h-4 w-4 animate-spin text-brand-600" />
            ) : (
              <CheckCheck className="h-4 w-4 text-brand-600" />
            )}
            <span>Mark all as read</span>
          </button>
        )}
      </div>

      {/* Filter Toolbar */}
      <div className="mb-6 flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-3 shadow-sm sm:flex-row sm:items-center sm:justify-between">
        {/* Status Filter */}
        <div className="flex items-center gap-1 rounded-lg bg-gray-100 p-1 text-xs font-semibold">
          <button
            type="button"
            onClick={() => handleStatusChange('all')}
            className={`rounded-md px-3 py-1.5 transition-colors ${
              statusFilter === 'all'
                ? 'bg-white text-ink shadow-sm'
                : 'text-ink-muted hover:text-ink'
            }`}
          >
            All
          </button>
          <button
            type="button"
            onClick={() => handleStatusChange('unread')}
            className={`inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 transition-colors ${
              statusFilter === 'unread'
                ? 'bg-white text-ink shadow-sm'
                : 'text-ink-muted hover:text-ink'
            }`}
          >
            <span>Unread</span>
            {unreadCount > 0 && (
              <span className="rounded-full bg-signal-600 px-1.5 py-0.2 text-[10px] font-bold text-white">
                {unreadCount}
              </span>
            )}
          </button>
        </div>

        {/* Category Filters */}
        <div className="flex flex-wrap items-center gap-1.5 text-xs font-medium text-ink-muted">
          {(
            [
              { key: 'all', label: 'All Categories' },
              { key: 'enquiry', label: 'Enquiries', icon: Inbox },
              { key: 'review', label: 'Reviews', icon: Star },
              { key: 'account', label: 'Security', icon: ShieldAlert },
            ] as const
          ).map((item) => {
            const isSelected = categoryFilter === item.key;
            const Icon = 'icon' in item ? item.icon : null;

            return (
              <button
                key={item.key}
                type="button"
                onClick={() => handleCategoryChange(item.key)}
                className={`inline-flex items-center gap-1 rounded-lg border px-2.5 py-1.5 transition-colors ${
                  isSelected
                    ? 'border-brand-600 bg-brand-50 font-semibold text-brand-700'
                    : 'border-gray-200 bg-white hover:border-gray-300 hover:text-ink'
                }`}
              >
                {Icon && <Icon className="h-3 w-3" />}
                <span>{item.label}</span>
              </button>
            );
          })}
        </div>
      </div>

      {/* Main Content Area */}
      {loading ? (
        <div className="space-y-3">
          {[1, 2, 3, 4].map((i) => (
            <div
              key={i}
              className="animate-pulse rounded-xl border border-gray-200 bg-white p-4 shadow-sm"
            >
              <div className="flex items-start gap-4">
                <div className="h-10 w-10 rounded-lg bg-gray-200" />
                <div className="flex-1 space-y-2">
                  <div className="h-4 w-1/3 rounded bg-gray-200" />
                  <div className="h-3.5 w-full rounded bg-gray-100" />
                  <div className="h-3 w-1/4 rounded bg-gray-100" />
                </div>
              </div>
            </div>
          ))}
        </div>
      ) : error ? (
        <div className="rounded-xl border border-rose-200 bg-rose-50 p-6 text-center">
          <AlertTriangle className="mx-auto h-8 w-8 text-rose-600" />
          <h2 className="mt-2 text-sm font-semibold text-rose-900">Failed to load notifications</h2>
          <p className="mt-1 text-xs text-rose-700">{error}</p>
          <button
            type="button"
            onClick={loadNotifications}
            className="mt-4 inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3.5 py-2 text-xs font-semibold text-rose-800 shadow-sm hover:bg-rose-50"
          >
            <RefreshCw className="h-3.5 w-3.5" />
            <span>Try Again</span>
          </button>
        </div>
      ) : notifications.length === 0 ? (
        <div className="rounded-xl border border-gray-200 bg-white px-6 py-16 text-center shadow-sm">
          <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-ink-subtle">
            <Bell className="h-6 w-6" />
          </div>
          <h2 className="text-base font-semibold text-ink">
            {statusFilter === 'unread'
              ? 'No unread notifications'
              : categoryFilter !== 'all'
                ? `No ${categoryFilter} notifications found`
                : 'No notifications yet'}
          </h2>
          <p className="mx-auto mt-1 max-w-sm text-xs text-ink-muted">
            {statusFilter === 'unread'
              ? "You're all caught up! Switch to 'All' to review your past notification history."
              : 'Updates regarding your enquiry matches, review responses, and account security will appear here.'}
          </p>

          {statusFilter === 'unread' && (
            <button
              type="button"
              onClick={() => handleStatusChange('all')}
              className="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-700"
            >
              <span>View All Notifications</span>
            </button>
          )}
        </div>
      ) : (
        <div className="space-y-3">
          {notifications.map((notification) => {
            const meta = getCategoryMeta(notification.category);
            const IconComponent = meta.icon;
            const isDeleting = actionInProgress === `delete:${notification.id}`;
            const isMarking = actionInProgress === `read:${notification.id}`;

            return (
              <div
                key={notification.id}
                className={`relative rounded-xl border p-4 shadow-sm transition-colors ${
                  !notification.is_read
                    ? 'border-signal-200 bg-signal-50/20'
                    : 'border-gray-200 bg-white'
                }`}
              >
                <div className="flex items-start justify-between gap-3 sm:gap-4">
                  {/* Category icon */}
                  <div
                    className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border ${meta.badgeClass}`}
                  >
                    <IconComponent className={`h-5 w-5 ${meta.iconClass}`} aria-hidden="true" />
                  </div>

                  {/* Body Content */}
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <span
                        className={`inline-flex items-center rounded-md border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider ${meta.badgeClass}`}
                      >
                        {meta.label}
                      </span>
                      {!notification.is_read && (
                        <span className="flex items-center gap-1 text-[11px] font-bold text-signal-700">
                          <span className="h-2 w-2 rounded-full bg-signal-600" />
                          <span>Unread</span>
                        </span>
                      )}
                      <span className="text-xs text-ink-subtle">
                        {formatRelativeTime(notification.created_at)}
                      </span>
                    </div>

                    <h2 className="mt-1.5 text-sm font-semibold text-ink sm:text-base">
                      {notification.title}
                    </h2>
                    <p className="mt-1 text-xs text-ink-muted leading-relaxed sm:text-sm">
                      {notification.message}
                    </p>

                    {/* Action Link & Timestamp */}
                    <div className="mt-3 flex flex-wrap items-center gap-3 pt-1 border-t border-gray-100/80">
                      {notification.action_url && (
                        <button
                          type="button"
                          onClick={() => handleActionClick(notification)}
                          className="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 hover:text-brand-800"
                        >
                          <span>View Details</span>
                          <ArrowRight className="h-3 w-3" />
                        </button>
                      )}
                      <span className="text-[11px] text-ink-subtle">
                        {formatFullDate(notification.created_at)}
                      </span>
                    </div>
                  </div>

                  {/* Actions (Mark read / Delete) */}
                  <div className="flex shrink-0 items-center gap-1">
                    {!notification.is_read && (
                      <button
                        type="button"
                        onClick={() => handleMarkRead(notification.id)}
                        disabled={isMarking}
                        title="Mark as read"
                        aria-label="Mark notification as read"
                        className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-gray-200 text-ink-subtle transition-colors hover:border-signal-300 hover:bg-signal-50 hover:text-signal-700 disabled:opacity-50"
                      >
                        {isMarking ? (
                          <Loader2 className="h-3.5 w-3.5 animate-spin" />
                        ) : (
                          <Check className="h-3.5 w-3.5" />
                        )}
                      </button>
                    )}

                    <button
                      type="button"
                      onClick={() => handleDelete(notification.id)}
                      disabled={isDeleting}
                      title="Delete notification"
                      aria-label="Delete notification"
                      className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-gray-200 text-ink-subtle transition-colors hover:border-rose-300 hover:bg-rose-50 hover:text-rose-700 disabled:opacity-50"
                    >
                      {isDeleting ? (
                        <Loader2 className="h-3.5 w-3.5 animate-spin" />
                      ) : (
                        <Trash2 className="h-3.5 w-3.5" />
                      )}
                    </button>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Pagination Controls */}
      {pagination.total > 0 && pagination.total_pages > 1 && (
        <div className="mt-6 flex flex-col items-center justify-between gap-3 border-t border-gray-200 pt-4 sm:flex-row">
          <p className="text-xs text-ink-muted">
            Showing{' '}
            <span className="font-semibold text-ink">
              {(pagination.current_page - 1) * pagination.per_page + 1}
            </span>{' '}
            to{' '}
            <span className="font-semibold text-ink">
              {Math.min(pagination.current_page * pagination.per_page, pagination.total)}
            </span>{' '}
            of <span className="font-semibold text-ink">{pagination.total}</span> notifications
          </p>

          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
              disabled={pagination.current_page <= 1 || loading}
              className="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-ink shadow-sm transition-colors hover:bg-gray-50 disabled:opacity-40"
            >
              <ChevronLeft className="h-3.5 w-3.5" />
              <span>Previous</span>
            </button>

            <span className="px-2 text-xs font-semibold text-ink">
              Page {pagination.current_page} of {pagination.total_pages}
            </span>

            <button
              type="button"
              onClick={() => setCurrentPage((p) => Math.min(pagination.total_pages, p + 1))}
              disabled={pagination.current_page >= pagination.total_pages || loading}
              className="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-ink shadow-sm transition-colors hover:bg-gray-50 disabled:opacity-40"
            >
              <span>Next</span>
              <ChevronRight className="h-3.5 w-3.5" />
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
