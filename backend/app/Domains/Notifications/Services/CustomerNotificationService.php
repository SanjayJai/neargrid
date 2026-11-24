<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Notifications\Enums\NotificationCategory;
use App\Domains\Notifications\Models\CustomerNotification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\Notification;

final class CustomerNotificationService
{
    /**
     * Retrieve paginated notifications for the authenticated user with optional status and category filters.
     *
     * @param  array{status?: string|null, category?: string|null, per_page?: int|null, page?: int|null}  $filters
     * @return LengthAwarePaginator<CustomerNotification>
     */
    public function getCustomerNotifications(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = $user->notifications()->whereNull('deleted_at');

        if (isset($filters['status']) && $filters['status'] === 'unread') {
            $query->whereNull('read_at');
        }

        if (isset($filters['category']) && $filters['category'] !== 'all' && NotificationCategory::tryFrom((string) $filters['category']) !== null) {
            $query->where('category', $filters['category']);
        }

        $perPage = min(50, max(1, (int) ($filters['per_page'] ?? 15)));

        return $query->paginate($perPage);
    }

    /**
     * Get the current count of unread notifications for the user.
     */
    public function getUnreadCount(User $user): int
    {
        return $user->unreadNotifications()->whereNull('deleted_at')->count();
    }

    /**
     * Mark a single notification as read, ensuring strict polymorphic ownership.
     */
    public function markAsRead(User $user, string $notificationId): CustomerNotification
    {
        /** @var CustomerNotification $notification */
        $notification = $user->notifications()
            ->whereNull('deleted_at')
            ->where('id', $notificationId)
            ->firstOrFail();

        $notification->markAsRead();

        return $notification;
    }

    /**
     * Mark all unread notifications as read for the user in a single atomic update.
     */
    public function markAllAsRead(User $user): int
    {
        return $user->unreadNotifications()
            ->whereNull('deleted_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Soft-delete a notification, ensuring strict polymorphic ownership.
     */
    public function softDelete(User $user, string $notificationId): CustomerNotification
    {
        /** @var CustomerNotification $notification */
        $notification = $user->notifications()
            ->whereNull('deleted_at')
            ->where('id', $notificationId)
            ->firstOrFail();

        $notification->delete();

        return $notification;
    }

    /**
     * Dispatches a notification to the user through the custom database channel.
     */
    public function send(User $user, Notification $notification): void
    {
        $user->notify($notification);
    }
}
