<?php

declare(strict_types=1);

namespace App\Domains\Directory\Models;

use App\Domains\Domains\Models\Domain;
use App\Models\User;
use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name',
    'slug',
    'owner_user_id',
    'status',
])]
#[Hidden(['id', 'owner_user_id'])]
class Organization extends Model
{
    use HasPublicUlid, SoftDeletes;

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Business::class, 'organization_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class, 'organization_id');
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class, 'organization_id');
    }
}
