<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Channels\CustomDatabaseChannel;
use App\Domains\Notifications\Enums\NotificationCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class LeadStatusUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $enquiryId,
        public readonly string $enquiryUlid,
        public readonly int $businessId,
        public readonly string $businessName,
        public readonly string $newStatus,
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
        return "enquiry:lead_status:{$this->enquiryId}:{$this->businessId}:{$this->newStatus}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $message = match ($this->newStatus) {
            'contacted' => "{$this->businessName} has marked your enquiry as contacted.",
            'converted' => "{$this->businessName} has completed your enquiry request.",
            'archived' => "{$this->businessName} has closed your enquiry request.",
            default => "{$this->businessName} has updated the status of your enquiry to {$this->newStatus}.",
        };

        return [
            'title' => 'Enquiry Status Update',
            'message' => $message,
            'category' => NotificationCategory::Enquiry->value,
            'action_url' => '/account/enquiries',
            'entity_type' => 'enquiry',
            'entity_ulid' => $this->enquiryUlid,
            'metadata' => [
                'enquiry_id' => $this->enquiryId,
                'business_id' => $this->businessId,
                'business_name' => $this->businessName,
                'lead_status' => $this->newStatus,
            ],
        ];
    }
}
