<?php

declare(strict_types=1);

namespace App\Domains\Directory\Models;

use App\Domains\Directory\Enums\BusinessMemberRole;
use App\Models\User;
use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'business_id',
    'user_id',
    'role',
    'permissions',
    'status',
    'invited_by_user_id',
    'accepted_at',
])]
#[Hidden(['id', 'business_id', 'user_id', 'invited_by_user_id'])]
class BusinessMember extends Model
{
    use HasPublicUlid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => BusinessMemberRole::class,
            'permissions' => 'array',
            'accepted_at' => 'datetime',
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
     * @return list<string>
     */
    public function effectiveCapabilities(): array
    {
        $defaults = $this->role instanceof BusinessMemberRole
            ? $this->role->defaultCapabilities()
            : [];

        $explicit = is_array($this->permissions) ? $this->permissions : [];

        return array_values(array_unique(array_merge($defaults, $explicit)));
    }

    public function hasCapability(string $capability): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        return in_array($capability, $this->effectiveCapabilities(), true);
    }
}
