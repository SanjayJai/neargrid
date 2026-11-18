<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Media;

use App\Domains\Media\Models\KycDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Metadata-only KYC document representation.
 * Strictly omits disk names, private storage keys, permanent URLs, and internal IDs.
 *
 * @mixin KycDocument
 */
final class KycDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'document_type' => $this->document_type->value,
            'upload_status' => $this->upload_status,
            'mime_type' => $this->mime_type,
            'file_size_bytes' => $this->file_size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'sha256_checksum' => $this->sha256_checksum,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
