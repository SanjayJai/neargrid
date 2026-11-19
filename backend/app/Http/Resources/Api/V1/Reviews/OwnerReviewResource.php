<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Reviews;

use App\Domains\Reviews\Enums\ReviewTrustBadge;
use App\Domains\Reviews\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Review
 */
final class OwnerReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'customer_name' => $this->customer_name,
            'rating' => $this->rating,
            'title' => $this->title,
            'content' => $this->content,
            'is_verified_interaction' => (bool) $this->is_verified_interaction,
            'trust_badge' => $this->trust_badge instanceof ReviewTrustBadge
                ? $this->trust_badge->value
                : ($this->trust_badge ?? 'none'),
            'interaction_type' => $this->interaction_type,
            'status' => $this->status->value,
            'moderation_status' => $this->moderation_status->value,
            'has_reply' => $this->hasOwnerReply(),
            'owner_reply' => $this->hasOwnerReply() ? [
                'text' => $this->owner_reply_text,
                'replied_at' => $this->owner_replied_at?->toIso8601String(),
                'replied_by' => $this->ownerReplyUser ? [
                    'public_id' => $this->ownerReplyUser->public_id,
                    'name' => $this->ownerReplyUser->name,
                ] : null,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
