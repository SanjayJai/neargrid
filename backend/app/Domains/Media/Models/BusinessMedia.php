<?php

declare(strict_types=1);

namespace App\Domains\Media\Models;

use App\Domains\Directory\Models\Business;
use App\Domains\Media\Enums\BusinessMediaType;
use App\Domains\Media\Enums\MediaUploadStatus;
use App\Models\User;
use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'business_id',
    'uploaded_by_user_id',
    'media_type',
    'upload_status',
    'moderation_status',
    'disk',
    'storage_key',
    'original_key',
    'webp_full_key',
    'webp_card_key',
    'webp_thumb_key',
    'mime_type',
    'file_size_bytes',
    'width',
    'height',
    'exif_stripped',
    'alt_text',
    'sort_order',
    'failure_reason',
    'processed_at',
])]
#[Hidden([
    'id',
    'business_id',
    'uploaded_by_user_id',
    'disk',
    'storage_key',
    'original_key',
    'webp_full_key',
    'webp_card_key',
    'webp_thumb_key',
])]
class BusinessMedia extends Model
{
    use HasPublicUlid;

    protected $table = 'business_media';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'media_type' => BusinessMediaType::class,
            'upload_status' => MediaUploadStatus::class,
            'file_size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'exif_stripped' => 'boolean',
            'sort_order' => 'integer',
            'processed_at' => 'datetime',
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
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function isPubliclyDeliverable(): bool
    {
        return $this->upload_status === MediaUploadStatus::Ready
            && $this->moderation_status === 'approved'
            && $this->webp_full_key !== null;
    }

    /**
     * @return array{thumb: ?string, card: ?string, full: ?string}
     */
    public function publicUrls(): array
    {
        if ($this->upload_status !== MediaUploadStatus::Ready) {
            return ['thumb' => null, 'card' => null, 'full' => null];
        }

        $disk = Storage::disk($this->disk);

        return [
            'thumb' => $this->webp_thumb_key ? $disk->url($this->webp_thumb_key) : null,
            'card' => $this->webp_card_key ? $disk->url($this->webp_card_key) : null,
            'full' => $this->webp_full_key ? $disk->url($this->webp_full_key) : null,
        ];
    }

    /**
     * @return list<string>
     */
    public function allStorageKeys(): array
    {
        return array_values(array_unique(array_filter([
            $this->storage_key,
            $this->original_key,
            $this->webp_full_key,
            $this->webp_card_key,
            $this->webp_thumb_key,
        ])));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublicApproved(Builder $query): Builder
    {
        return $query
            ->where('upload_status', MediaUploadStatus::Ready->value)
            ->where('moderation_status', 'approved')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
