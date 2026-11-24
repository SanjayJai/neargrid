<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Channels\CustomDatabaseChannel;
use App\Domains\Notifications\Enums\NotificationCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class EnquiryCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $enquiryId,
        public readonly string $enquiryUlid,
        public readonly string $safeReason,
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
        return NotificationCategory::Enquiry;
    }

    public function deduplicationKey(object $notifiable): string
    {
        return "enquiry:cancelled:{$this->enquiryId}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Enquiry Closed',
            'message' => "Your enquiry was closed: {$this->safeReason}",
            'category' => NotificationCategory::Enquiry->value,
            'action_url' => '/account/enquiries',
            'entity_type' => 'enquiry',
            'entity_ulid' => $this->enquiryUlid,
            'metadata' => [
                'enquiry_id' => $this->enquiryId,
                'reason' => $this->safeReason,
            ],
        ];
    }
}
