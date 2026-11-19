<?php

declare(strict_types=1);

namespace App\Domains\Reviews\Models;

use App\Domains\Directory\Models\Business;
use App\Domains\Identity\Models\OtpChallenge;
use App\Domains\Reviews\Enums\ReviewModerationStatus;
use App\Domains\Reviews\Enums\ReviewStatus;
use App\Models\User;
use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'business_id',
    'user_id',
    'customer_name',
    'phone_e164',
    'rating',
    'title',
    'content',
    'is_verified_interaction',
    'interaction_type',
    'interaction_id',
    'status',
    'otp_challenge_id',
    'phone_verified_at',
    'ip_address_hash',
    'owner_reply_text',
    'owner_replied_at',
    'owner_reply_user_id',
    'moderation_status',
    'moderated_at',
    'moderated_by_admin_id',
    'moderation_notes',
])]
#[Hidden([
    'id',
    'ip_address_hash',
    'otp_challenge_id',
])]
class Review extends Model
{
    use HasPublicUlid, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_verified_interaction' => 'boolean',
            'status' => ReviewStatus::class,
            'moderation_status' => ReviewModerationStatus::class,
            'phone_verified_at' => 'datetime',
            'owner_replied_at' => 'datetime',
            'moderated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function ownerReplyUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_reply_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function moderatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by_admin_id');
    }

    /**
     * @return BelongsTo<OtpChallenge, $this>
     */
    public function otpChallenge(): BelongsTo
    {
        return $this->belongsTo(OtpChallenge::class);
    }

    /**
     * @return HasMany<ReviewReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(ReviewReport::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ReviewStatus::Published->value)
            ->where('moderation_status', ReviewModerationStatus::Approved->value);
    }

    public function isPublished(): bool
    {
        return $this->status === ReviewStatus::Published
            && $this->moderation_status === ReviewModerationStatus::Approved;
    }

    public function hasOwnerReply(): bool
    {
        return $this->owner_reply_text !== null && trim($this->owner_reply_text) !== '';
    }
}
