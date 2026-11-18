<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Models\User;
use App\Support\Concerns\HasPublicUlid;
use Database\Factories\UserDeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'platform',
    'device_identifier',
    'fcm_token',
    'app_version',
    'last_active_at',
])]
#[Hidden([
    'id',
    'user_id',
    'fcm_token',
])]
class UserDevice extends Model
{
    /** @use HasFactory<UserDeviceFactory> */
    use HasFactory, HasPublicUlid;

    protected static function newFactory(): UserDeviceFactory
    {
        return UserDeviceFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_active_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
