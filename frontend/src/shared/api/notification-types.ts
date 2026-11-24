export type NotificationCategory = 'enquiry' | 'review' | 'account';

export interface CustomerNotificationDto {
  id: string;
  type: string;
  category: NotificationCategory;
  title: string;
  message: string;
  action_url: string | null;
  entity_type: string | null;
  entity_ulid: string | null;
  metadata: Record<string, unknown>;
  is_read: boolean;
  read_at: string | null;
  created_at: string | null;
}

export interface NotificationPaginationMeta {
  total: number;
  count: number;
  per_page: number;
  current_page: number;
  total_pages: number;
}

export interface NotificationListPayload {
  data: CustomerNotificationDto[];
  meta: {
    pagination: NotificationPaginationMeta;
  };
}

export interface UnreadCountPayload {
  unread_count: number;
}

export interface MarkAllReadPayload {
  marked_count: number;
}
