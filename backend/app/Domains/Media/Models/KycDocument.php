<?php

declare(strict_types=1);

namespace App\Domains\Media\Models;

use App\Domains\Directory\Enums\VerificationDocumentType;
use App\Domains\Directory\Models\Business;
use App\Domains\Directory\Models\BusinessVerification;
use App\Models\User;
use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'business_id',
    'business_verification_id',
    'uploaded_by_user_id',
    'document_type',
    'upload_status',
    'disk',
    'storage_key',
    'mime_type',
    'file_size_bytes',
    'width',
    'height',
    'sha256_checksum',
])]
#[Hidden([
    'id',
    'business_id',
    'business_verification_id',
    'uploaded_by_user_id',
    'disk',
    'storage_key',
])]
class KycDocument extends Model
{
    use HasPublicUlid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => VerificationDocumentType::class,
            'file_size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
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
     * @return BelongsTo<BusinessVerification, $this>
     */
    public function verification(): BelongsTo
    {
        return $this->belongsTo(BusinessVerification::class, 'business_verification_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
