<?php

declare(strict_types=1);

namespace App\Domains\Seo\Models;

use App\Models\User;
use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'seo_metadata_id',
    'version_number',
    'snapshot_json',
    'change_summary',
    'created_by_user_id',
    'created_at',
])]
#[Hidden(['id', 'seo_metadata_id', 'created_by_user_id'])]
class SeoRevision extends Model
{
    use HasPublicUlid;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'seo_metadata_id' => 'integer',
            'version_number' => 'integer',
            'snapshot_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SeoMetadata, $this>
     */
    public function seoMetadata(): BelongsTo
    {
        return $this->belongsTo(SeoMetadata::class, 'seo_metadata_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
