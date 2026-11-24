<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Channels\CustomDatabaseChannel;
use App\Domains\Notifications\Enums\NotificationCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class EnquiryMatchedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $enquiryId,
        public readonly string $enquiryUlid,
        public readonly string $localityOrCity,
        public readonly int $matchedCount,
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
        return "enquiry:matched:{$this->enquiryId}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $bizText = $this->matchedCount === 1 ? '1 verified business' : "{$this->matchedCount} verified businesses";

        return [
            'title' => 'Enquiry Matched',
            'message' => "Your enquiry has been matched with {$bizText} in {$this->localityOrCity}. You may receive callbacks or quotes shortly.",
            'category' => NotificationCategory::Enquiry->value,
            'action_url' => '/account/enquiries',
            'entity_type' => 'enquiry',
            'entity_ulid' => $this->enquiryUlid,
            'metadata' => [
                'enquiry_id' => $this->enquiryId,
                'locality_or_city' => $this->localityOrCity,
                'matched_count' => $this->matchedCount,
            ],
        ];
    }
}
