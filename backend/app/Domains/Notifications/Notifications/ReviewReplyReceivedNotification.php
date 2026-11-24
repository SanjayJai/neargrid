<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Channels\CustomDatabaseChannel;
use App\Domains\Notifications\Enums\NotificationCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

final class ReviewReplyReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $reviewId,
        public readonly string $reviewUlid,
        public readonly string $businessName,
        public readonly string $businessSlug,
        public readonly string $citySlug,
        public readonly string $replyText,
        public readonly string $replyVersion = 'initial',
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
        return NotificationCategory::Review;
    }

    public function deduplicationKey(object $notifiable): string
    {
        return "review:reply:{$this->reviewId}:{$this->replyVersion}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $preview = Str::limit(trim($this->replyText), 120, '...');

        return [
            'title' => 'New Reply to Your Review',
            'message' => "{$this->businessName} replied to your review: \"{$preview}\"",
            'category' => NotificationCategory::Review->value,
            'action_url' => "/{$this->citySlug}/business/{$this->businessSlug}#reviews",
            'entity_type' => 'review',
            'entity_ulid' => $this->reviewUlid,
            'metadata' => [
                'review_id' => $this->reviewId,
                'business_name' => $this->businessName,
                'business_slug' => $this->businessSlug,
                'city_slug' => $this->citySlug,
                'reply_version' => $this->replyVersion,
            ],
        ];
    }
}
