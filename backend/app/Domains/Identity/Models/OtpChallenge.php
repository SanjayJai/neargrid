<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Support\Concerns\HasPublicUlid;
use Database\Factories\OtpChallengeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'challenge_id',
    'phone_e164',
    'purpose',
    'code_hash',
    'attempts',
    'max_attempts',
    'request_ip_hash',
    'expires_at',
    'verified_at',
])]
#[Hidden([
    'id',
    'code_hash',
    'request_ip_hash',
])]
class OtpChallenge extends Model
{
    /** @use HasFactory<OtpChallengeFactory> */
    use HasFactory, HasPublicUlid;

    public const UPDATED_AT = null;

    protected static function newFactory(): OtpChallengeFactory
    {
        return OtpChallengeFactory::new();
    }

    public function publicUlidColumn(): string
    {
        return 'challenge_id';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function hasExceededAttempts(): bool
    {
        return $this->attempts >= $this->max_attempts;
    }
}
