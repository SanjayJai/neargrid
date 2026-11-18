<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Support\Concerns\HasPublicUlid;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasPublicUlid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'name',
        'device_public_id',
        'token',
        'abilities',
        'expires_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'id',
        'tokenable_id',
        'token',
    ];
}
