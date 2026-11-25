<?php

declare(strict_types=1);

namespace App\Domains\Taxonomy\Models;

use App\Models\User;
use App\Support\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'category_id',
    'version_number',
    'status',
    'snapshot_json',
    'change_summary',
    'created_by_user_id',
    'approved_by_user_id',
    'published_at',
    'created_at',
])]
#[Hidden(['id', 'category_id', 'created_by_user_id', 'approved_by_user_id'])]
class CategoryRevision extends Model
{
    use HasPublicUlid;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'version_number' => 'integer',
            'snapshot_json' => 'array',
            'published_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
