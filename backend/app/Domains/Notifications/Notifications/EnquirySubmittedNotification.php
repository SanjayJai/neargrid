<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Channels\CustomDatabaseChannel;
use App\Domains\Notifications\Enums\NotificationCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class EnquirySubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $enquiryId,
        public readonly string $enquiryUlid,
        public readonly string $categoryName,
        public readonly ?string $targetBusinessName = null,
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
        return "enquiry:submitted:{$this->enquiryId}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $message = $this->targetBusinessName !== null && trim($this->targetBusinessName) !== ''
            ? "Your direct enquiry to {$this->targetBusinessName} has been verified and submitted."
            : "Your enquiry for {$this->categoryName} has been verified and is active.";

        return [
            'title' => 'Enquiry Submitted & Verified',
            'message' => $message,
            'category' => NotificationCategory::Enquiry->value,
            'action_url' => '/account/enquiries',
            'entity_type' => 'enquiry',
            'entity_ulid' => $this->enquiryUlid,
            'metadata' => [
                'enquiry_id' => $this->enquiryId,
                'category_name' => $this->categoryName,
                'target_business_name' => $this->targetBusinessName,
            ],
        ];
    }
}
