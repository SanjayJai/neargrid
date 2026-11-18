<?php

declare(strict_types=1);

namespace App\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'source_path',
    'target_path',
    'http_status',
])]
class UrlRedirect extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
