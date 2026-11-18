<?php

declare(strict_types=1);

namespace App\Domains\Directory\Models;

use App\Domains\Directory\Enums\VerificationDocumentType;
use App\Models\User;
use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'business_id',
    'submitted_by_user_id',
    'document_type',
    'document_number_encrypted',
    'private_file_path',
    'status',
    'reviewed_by_admin_id',
    'rejection_reason',
    'reviewed_at',
])]
#[Hidden([
    'id',
    'business_id',
    'submitted_by_user_id',
    'reviewed_by_admin_id',
    'document_number_encrypted',
    'private_file_path',
])]
class BusinessVerification extends Model
{
    use HasPublicUlid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => VerificationDocumentType::class,
            'document_number_encrypted' => 'encrypted',
            'reviewed_at' => 'datetime',
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
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_admin_id');
    }
}
