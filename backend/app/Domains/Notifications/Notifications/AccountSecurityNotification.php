<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Channels\CustomDatabaseChannel;
use App\Domains\Notifications\Enums\NotificationCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class AccountSecurityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $eventType,
        public readonly string $title,
        public readonly string $message,
        public readonly string $uniqueEventId,
        public readonly string $actionUrl = '/dashboard/settings',
        public readonly array $extraMetadata = [],
    ) {
        $this->queue = 'notifications';
        $this->afterCommit = true;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [CustomDatabaseChannel::class];
    }

    public function category(object $notifiable): NotificationCategory
    {
        return NotificationCategory::Account;
    }

    public function deduplicationKey(object $notifiable): string
    {
        return "account:security:{$this->eventType}:{$this->uniqueEventId}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'category' => NotificationCategory::Account->value,
            'action_url' => $this->actionUrl,
            'entity_type' => 'account',
            'entity_ulid' => $this->uniqueEventId,
            'metadata' => array_merge([
                'event_type' => $this->eventType,
            ], $this->extraMetadata),
        ];
    }
}
