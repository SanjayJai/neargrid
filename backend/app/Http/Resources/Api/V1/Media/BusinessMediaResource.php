<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Media;

use App\Domains\Media\Models\BusinessMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Owner/staff view of business media records.
 * Never exposes internal database IDs, disk names, or raw storage keys.
 *
 * @mixin BusinessMedia
 */
final class BusinessMediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'media_type' => $this->media_type->value,
            'upload_status' => $this->upload_status->value,
            'moderation_status' => $this->moderation_status,
            'mime_type' => $this->mime_type,
            'file_size_bytes' => $this->file_size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'exif_stripped' => $this->exif_stripped,
            'alt_text' => $this->alt_text,
            'sort_order' => $this->sort_order,
            'urls' => $this->publicUrls(),
            'failure_reason' => $this->failure_reason,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
