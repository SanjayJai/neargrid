<?php

declare(strict_types=1);

namespace App\Domains\Directory\Models;

use App\Models\User;
use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'user_id',
    'role',
    'scope_type',
    'scope_city_ids',
    'permissions',
    'status',
    'invited_by_user_id',
    'accepted_at',
])]
#[Hidden(['id', 'organization_id', 'user_id', 'invited_by_user_id'])]
class OrganizationMember extends Model
{
    use HasPublicUlid;

    protected function casts(): array
    {
        return [
            'scope_city_ids' => 'array',
            'permissions' => 'array',
            'accepted_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
