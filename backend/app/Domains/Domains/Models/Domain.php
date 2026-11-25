<?php

declare(strict_types=1);

namespace App\Domains\Domains\Models;

use App\Domains\Directory\Models\Organization;
use App\Domains\Location\Models\City;
use App\Domains\Location\Models\State;
use App\Models\User;
use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'hostname',
    'domain_type',
    'is_primary',
    'primary_slot',
    'status',
    'verification_method',
    'verification_status',
    'ssl_provider',
    'ssl_status',
    'ssl_checked_at',
    'visibility_mode',
    'default_city_id',
    'default_state_id',
    'default_locale',
    'organization_id',
    'canonical_domain_id',
    'is_canonical_for_location',
    'is_indexable',
    'created_by_user_id',
])]
#[Hidden([
    'id',
    'primary_slot',
    'challenge_hash',
    'challenge_expires_at',
    'challenge_consumed_at',
    'created_by_user_id',
])]
class Domain extends Model
{
    use HasPublicUlid, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'primary_slot' => 'integer',
            'verification_attempts' => 'integer',
            'challenge_expires_at' => 'datetime',
            'challenge_consumed_at' => 'datetime',
            'verified_at' => 'datetime',
            'ssl_checked_at' => 'datetime',
            'is_canonical_for_location' => 'boolean',
            'is_indexable' => 'boolean',
        ];
    }

    public function defaultCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'default_city_id');
    }

    public function defaultState(): BelongsTo
    {
        return $this->belongsTo(State::class, 'default_state_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function canonicalDomain(): BelongsTo
    {
        return $this->belongsTo(self::class, 'canonical_domain_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Indexability requires active domain status, verified ownership,
     * active SSL, and explicit indexability approval.
     */
    public function isEffectivelyIndexable(): bool
    {
        return ! $this->trashed()
            && $this->status === 'active'
            && $this->verification_status === 'verified'
            && $this->ssl_status === 'active'
            && (bool) $this->is_indexable;
    }

    public function origin(): string
    {
        $scheme = $this->ssl_status === 'active' ? 'https' : 'http';
        return "{$scheme}://{$this->hostname}";
    }
}
