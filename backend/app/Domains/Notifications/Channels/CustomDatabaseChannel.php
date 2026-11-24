<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Channels;

use App\Domains\Notifications\Enums\NotificationCategory;
use App\Domains\Notifications\Models\CustomerNotification;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class CustomDatabaseChannel extends DatabaseChannel
{
    /**
     * Send the given notification.
     *
     * @param  mixed  $notifiable
     * @return CustomerNotification|null
     */
    public function send($notifiable, Notification $notification)
    {
        $payload = $this->buildPayload($notifiable, $notification);

        try {
            /** @var CustomerNotification $record */
            $record = $notifiable->routeNotificationFor('database', $notification)->create($payload);

            return $record;
        } catch (QueryException $e) {
            if ($this->isDuplicateKeyException($e)) {
                Log::debug('Notification discarded due to duplicate deduplication_key', [
                    'notifiable_id' => $notifiable->getKey(),
                    'notifiable_type' => $notifiable->getMorphClass(),
                    'deduplication_key' => $payload['deduplication_key'] ?? null,
                ]);

                return null;
            }

            throw $e;
        }
    }

    /**
     * Build an array payload for the DatabaseNotification Model.
     *
     * @param  mixed  $notifiable
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification): array
    {
        $payload = parent::buildPayload($notifiable, $notification);

        $category = method_exists($notification, 'category')
            ? $notification->category($notifiable)
            : ($payload['data']['category'] ?? NotificationCategory::Account);

        if ($category instanceof NotificationCategory) {
            $category = $category->value;
        }

        $deduplicationKey = method_exists($notification, 'deduplicationKey')
            ? $notification->deduplicationKey($notifiable)
            : ($payload['data']['deduplication_key'] ?? null);

        $payload['category'] = (string) $category;
        $payload['deduplication_key'] = $deduplicationKey !== null && trim((string) $deduplicationKey) !== ''
            ? (string) $deduplicationKey
            : null;

        return $payload;
    }

    protected function isDuplicateKeyException(QueryException $e): bool
    {
        // Extract the driver error message, stripping any appended SQL query text from QueryException
        $driverMessage = $e->getPrevious() instanceof \Throwable
            ? $e->getPrevious()->getMessage()
            : explode(' (Connection:', $e->getMessage())[0];

        // 1. Explicitly exclude unrelated integrity errors (NOT NULL, Foreign Key)
        if (str_contains($driverMessage, 'cannot be null') || str_contains($driverMessage, 'NOT NULL constraint failed')) {
            return false;
        }

        if (str_contains($driverMessage, 'foreign key constraint') || str_contains($driverMessage, 'FOREIGN KEY constraint failed')) {
            return false;
        }

        // 2. Must specifically be a unique constraint violation
        $isUnique = str_contains($driverMessage, 'UNIQUE constraint failed')
            || str_contains($driverMessage, 'Duplicate entry')
            || str_contains($driverMessage, '1062 Duplicate');

        if (! $isUnique) {
            return false;
        }

        // 3. Must specifically target the deduplication unique constraint
        // MySQL reports the key name 'notifications_dedup_unique'
        // SQLite reports the composite columns 'notifications.deduplication_key'
        return str_contains($driverMessage, 'notifications_dedup_unique')
            || str_contains($driverMessage, 'notifications.deduplication_key')
            || str_contains($driverMessage, 'deduplication_key');
    }
}
