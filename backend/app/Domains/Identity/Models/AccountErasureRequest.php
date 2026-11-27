<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Models\User;
use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'requested_by_user_id',
    'reviewed_by_admin_id',
    'status',
    'reason',
    'decision_notes',
    'retention_snapshot',
    'reviewed_at',
    'anonymized_at',
])]
#[Hidden([
    'id',
    'user_id',
    'requested_by_user_id',
    'reviewed_by_admin_id',
])]
class AccountErasureRequest extends Model
{
    use HasPublicUlid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'retention_snapshot' => 'array',
            'reviewed_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id')->withTrashed();
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_admin_id')->withTrashed();
    }
}
