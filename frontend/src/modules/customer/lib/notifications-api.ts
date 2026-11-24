'use client';

import { bffFetch } from '@/shared/api/bff-client';
import type {
  CustomerNotificationDto,
  NotificationCategory,
  UnreadCountPayload,
  MarkAllReadPayload,
} from '@/shared/api/notification-types';

export interface FetchNotificationsOptions {
  status?: 'all' | 'unread';
  category?: 'all' | NotificationCategory;
  page?: number;
  per_page?: number;
  signal?: AbortSignal;
}

export async function fetchNotifications(options: FetchNotificationsOptions = {}) {
  const params = new URLSearchParams();
  if (options.status && options.status !== 'all') {
    params.set('status', options.status);
  }
  if (options.category && options.category !== 'all') {
    params.set('category', options.category);
  }
  if (options.page && options.page > 1) {
    params.set('page', String(options.page));
  }
  if (options.per_page) {
    params.set('per_page', String(options.per_page));
  }

  const qs = params.toString();
  const path = `/api/account/notifications${qs ? `?${qs}` : ''}`;

  return bffFetch<CustomerNotificationDto[]>(path, {
    method: 'GET',
    signal: options.signal,
  });
}

export async function fetchUnreadCount(signal?: AbortSignal) {
  return bffFetch<UnreadCountPayload>('/api/account/notifications/unread-count', {
    method: 'GET',
    signal,
  });
}

export async function markNotificationAsRead(id: string, signal?: AbortSignal) {
  return bffFetch<CustomerNotificationDto>(`/api/account/notifications/${encodeURIComponent(id)}/read`, {
    method: 'PATCH',
    signal,
  });
}

export async function markAllNotificationsAsRead(signal?: AbortSignal) {
  return bffFetch<MarkAllReadPayload>('/api/account/notifications/mark-all-read', {
    method: 'POST',
    signal,
  });
}

export async function deleteNotification(id: string, signal?: AbortSignal) {
  return bffFetch<null>(`/api/account/notifications/${encodeURIComponent(id)}`, {
    method: 'DELETE',
    signal,
  });
}
