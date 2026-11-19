<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Reviews;

use App\Domains\Reviews\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Review
 */
final class AdminReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'business' => [
                'public_id' => $this->business->public_id,
                'name' => $this->business->name,
                'slug' => $this->business->slug,
            ],
            'customer_name' => $this->customer_name,
            'phone_e164' => $this->phone_e164,
            'rating' => $this->rating,
            'title' => $this->title,
            'content' => $this->content,
            'is_verified_interaction' => (bool) $this->is_verified_interaction,
            'interaction_type' => $this->interaction_type,
            'status' => $this->status->value,
            'moderation_status' => $this->moderation_status->value,
            'moderated_at' => $this->moderated_at?->toIso8601String(),
            'moderation_notes' => $this->moderation_notes,
            'moderated_by' => $this->moderatedByAdmin ? [
                'public_id' => $this->moderatedByAdmin->public_id,
                'name' => $this->moderatedByAdmin->name,
            ] : null,
            'owner_reply' => $this->hasOwnerReply() ? [
                'text' => $this->owner_reply_text,
                'replied_at' => $this->owner_replied_at?->toIso8601String(),
                'replied_by' => $this->ownerReplyUser ? [
                    'public_id' => $this->ownerReplyUser->public_id,
                    'name' => $this->ownerReplyUser->name,
                ] : null,
            ] : null,
            'reports_count' => $this->reports->count(),
            'reports' => $this->reports->map(fn ($r) => [
                'public_id' => $r->public_id,
                'reason' => $r->reason,
                'details' => $r->details,
                'status' => $r->status,
                'created_at' => $r->created_at?->toIso8601String(),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
