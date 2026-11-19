<?php

declare(strict_types=1);

namespace App\Domains\Leads\Models;

use App\Domains\Directory\Models\Business;
use App\Models\User;
use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'business_id',
    'user_id',
    'channel',
    'ip_address_hash',
])]
#[Hidden([
    'id',
    'ip_address_hash',
])]
class ContactReveal extends Model
{
    use HasPublicUlid;

    public const UPDATED_AT = null;

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
}
