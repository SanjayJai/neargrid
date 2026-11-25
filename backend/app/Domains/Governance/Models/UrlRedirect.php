<?php

declare(strict_types=1);

namespace App\Domains\Governance\Models;

use App\Domains\Domains\Models\Domain;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'domain_id',
    'scope_key',
    'source_path',
    'target_path',
    'http_status',
    'entity_type',
    'entity_id',
    'is_active',
])]
class UrlRedirect extends Model
{
    public const UPDATED_AT = null;

    public static function scopeKeyFor(?int $domainId): string
    {
        return $domainId === null ? 'global' : "domain:{$domainId}";
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'domain_id' => 'integer',
            'http_status' => 'integer',
            'entity_id' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }
}
