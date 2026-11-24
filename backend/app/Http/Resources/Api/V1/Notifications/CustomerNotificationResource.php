<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Notifications;

use App\Domains\Notifications\Models\CustomerNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerNotification
 */
final class CustomerNotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->data) ? $this->data : [];

        return [
            'id' => $this->id,
            'type' => class_basename($this->type),
            'category' => $this->category->value,
            'title' => (string) ($payload['title'] ?? 'Notification'),
            'message' => (string) ($payload['message'] ?? ''),
            'action_url' => isset($payload['action_url']) ? (string) $payload['action_url'] : null,
            'entity_type' => isset($payload['entity_type']) ? (string) $payload['entity_type'] : null,
            'entity_ulid' => isset($payload['entity_ulid']) ? (string) $payload['entity_ulid'] : null,
            'metadata' => isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : [],
            'is_read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
